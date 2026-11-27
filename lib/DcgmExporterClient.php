<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_gpu, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_gpu
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Gpu;

/**
 * Hardened read-only HTTP(S) client for NVIDIA DCGM Exporter scrapes
 * (REQ-REVIEW-003, docs/research/dcgm-exporter.md).
 *
 * Enforces:
 * - Strict operator-configured endpoint allowlist (host/port/scheme).
 * - TLS peer and hostname verification enabled by default (never honors
 *   Cacti's allow_unsafe_https).
 * - No redirects (follow_location = 0, max_redirects = 0).
 * - Total request timeout set on the 'http' context key (which PHP's HTTPS
 *   stream wrapper reads).
 * - Hard response body size cap via bounded reads.
 */
final class DcgmExporterClient {
	public const DEFAULT_TIMEOUT_SECONDS = 5;
	public const MAX_TIMEOUT_SECONDS     = 30;
	public const DEFAULT_MAX_BYTES       = 2097152;
	public const HARD_MAX_BYTES          = 4194304;
	public const DEFAULT_PORT            = 9400;
	public const DEFAULT_PATH            = '/metrics';

	/** @var list<string> */
	private readonly array $allowlist;
	private readonly int $timeoutSeconds;
	private readonly int $maxBytes;

	/**
	 * @param list<string>                                                                   $allowlist      allowed hostnames, IP literals, or host:port entries
	 * @param int                                                                            $timeoutSeconds bounded request timeout (1..30)
	 * @param int                                                                            $maxBytes       response size cap in bytes (1024..4194304)
	 * @param bool                                                                           $allowPlainHttp only allow plain HTTP when explicitly enabled for trusted local networks
	 * @param ?string                                                                        $caFile         optional CA bundle path for TLS verification
	 * @param ?\Closure(string, array<string, mixed>, int): array{status: int, body: string, truncated: bool} $transport optional transport for tests
	 */
	public function __construct(
		array $allowlist,
		int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
		int $maxBytes = self::DEFAULT_MAX_BYTES,
		private readonly bool $allowPlainHttp = false,
		private readonly ?string $caFile = null,
		private readonly ?\Closure $transport = null,
	) {
		$normalized = [];

		foreach ($allowlist as $entry) {
			$clean = strtolower(trim($entry));

			if ($clean !== '') {
				$normalized[] = $clean;
			}
		}

		$this->allowlist      = array_values(array_unique($normalized));
		$this->timeoutSeconds = max(1, min(self::MAX_TIMEOUT_SECONDS, $timeoutSeconds));
		$this->maxBytes       = max(1024, min(self::HARD_MAX_BYTES, $maxBytes));
	}

	/**
	 * Validate an endpoint URL against the strict security policy and allowlist.
	 *
	 * @return array{scheme: string, host: string, port: int, path: string, normalized: string}
	 *
	 * @throws CollectionError
	 */
	public function validateEndpoint(string $endpoint): array {
		$endpoint = trim($endpoint);

		if ($endpoint === '' || preg_match('/[\x00-\x1F\x7F\s]/', $endpoint) === 1) {
			throw new CollectionError(ExecutionState::NotConfigured, 'DCGM exporter endpoint is empty or contains invalid characters');
		}

		$parts = parse_url($endpoint);

		if (!is_array($parts)) {
			throw new CollectionError(ExecutionState::NotConfigured, 'DCGM exporter endpoint could not be parsed');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));

		if ($scheme !== 'https' && !($scheme === 'http' && $this->allowPlainHttp)) {
			throw new CollectionError(ExecutionState::PermissionDenied, 'DCGM exporter endpoint must use HTTPS');
		}

		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			throw new CollectionError(ExecutionState::PermissionDenied, 'DCGM exporter endpoint must not contain credentials, query strings, or fragments');
		}

		$host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

		if ($host === '') {
			throw new CollectionError(ExecutionState::NotConfigured, 'DCGM exporter endpoint has no host');
		}

		$port = isset($parts['port']) ? (int) $parts['port'] : self::DEFAULT_PORT;

		if ($port < 1 || $port > 65535) {
			throw new CollectionError(ExecutionState::NotConfigured, 'DCGM exporter endpoint port is out of range');
		}

		$path = (string) ($parts['path'] ?? self::DEFAULT_PATH);

		if ($path === '') {
			$path = self::DEFAULT_PATH;
		}

		if ($path !== self::DEFAULT_PATH) {
			throw new CollectionError(ExecutionState::PermissionDenied, 'DCGM exporter endpoint path must be /metrics');
		}

		if (!$this->isHostAllowed($host, $port)) {
			throw new CollectionError(ExecutionState::PermissionDenied, 'DCGM exporter host is not in the configured allowlist');
		}

		$formattedHost = str_contains($host, ':') ? '[' . $host . ']' : $host;

		return [
			'scheme'     => $scheme,
			'host'       => $host,
			'port'       => $port,
			'path'       => $path,
			'normalized' => $scheme . '://' . $formattedHost . ':' . $port . $path,
		];
	}

	/**
	 * Build the stream context options used for a validated request.
	 * Exposed for unit verification of TLS, redirect, and timeout settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function streamContextOptions(): array {
		$ssl = [
			'verify_peer'       => true,
			'verify_peer_name'  => true,
			'allow_self_signed' => false,
		];

		if ($this->caFile !== null && $this->caFile !== '') {
			$ssl['cafile'] = $this->caFile;
		}

		// PHP's HTTPS wrapper reads the 'http' key for method, redirects, and timeout.
		return [
			'http' => [
				'method'          => 'GET',
				'timeout'         => (float) $this->timeoutSeconds,
				'follow_location' => 0,
				'max_redirects'   => 0,
				'ignore_errors'   => true,
				'user_agent'      => 'Cacti-Plugin-GPU/1.0',
				'header'          => "Accept: text/plain; version=0.0.4\r\nConnection: close\r\n",
			],
			'ssl' => $ssl,
		];
	}

	/**
	 * Fetch Prometheus metrics text from a validated, allowlisted DCGM Exporter endpoint.
	 *
	 * @throws CollectionError
	 */
	public function fetch(string $endpoint): string {
		$validated = $this->validateEndpoint($endpoint);
		$options   = $this->streamContextOptions();

		$result = $this->transport !== null
			? ($this->transport)($validated['normalized'], $options, $this->maxBytes)
			: $this->performStreamRequest($validated['normalized'], $options);

		if ($result['truncated']) {
			throw new CollectionError(ExecutionState::Truncated, sprintf('DCGM exporter response exceeded %d bytes', $this->maxBytes));
		}

		if ($result['status'] === 401 || $result['status'] === 403) {
			throw new CollectionError(ExecutionState::PermissionDenied, sprintf('DCGM exporter rejected request with HTTP %d', $result['status']));
		}

		if ($result['status'] === 503) {
			throw new CollectionError(ExecutionState::TimedOut, 'DCGM exporter returned HTTP 503');
		}

		if ($result['status'] < 200 || $result['status'] >= 300) {
			throw new CollectionError(ExecutionState::Unreachable, sprintf('DCGM exporter returned HTTP %d', $result['status']));
		}

		return $result['body'];
	}

	private function isHostAllowed(string $host, int $port): bool {
		if ($this->allowlist === []) {
			return false;
		}

		$hostPort = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;

		foreach ($this->allowlist as $allowed) {
			if ($allowed === $host || $allowed === $hostPort) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, array<string, mixed>> $options
	 *
	 * @return array{status: int, body: string, truncated: bool}
	 *
	 * @throws CollectionError
	 */
	private function performStreamRequest(string $url, array $options): array {
		$context = stream_context_create($options);
		$stream  = @fopen($url, 'rb', false, $context);

		if (!is_resource($stream)) {
			throw new CollectionError(ExecutionState::Unreachable, 'Unable to connect to DCGM exporter endpoint');
		}

		try {
			stream_set_timeout($stream, $this->timeoutSeconds);

			$body      = '';
			$remaining = $this->maxBytes + 1;

			while (!feof($stream) && $remaining > 0) {
				$chunk = fread($stream, min(8192, $remaining));

				if ($chunk === false) {
					throw new CollectionError(ExecutionState::Unreachable, 'Failed while reading DCGM exporter response');
				}

				if ($chunk === '') {
					$meta = stream_get_meta_data($stream);

					if ($meta['timed_out']) {
						throw new CollectionError(ExecutionState::TimedOut, 'DCGM exporter request timed out');
					}

					break;
				}

				$body      .= $chunk;
				$remaining -= strlen($chunk);
			}

			$meta = stream_get_meta_data($stream);

			if ($meta['timed_out']) {
				throw new CollectionError(ExecutionState::TimedOut, 'DCGM exporter request timed out');
			}

			$status = 0;
			$wrapperData = $meta['wrapper_data'] ?? [];

			if (is_array($wrapperData) && isset($wrapperData[0]) && is_string($wrapperData[0]) && preg_match('/^HTTP\/[0-9.]+\s+([0-9]{3})\b/', $wrapperData[0], $m) === 1) {
				$status = (int) $m[1];
			}

			$truncated = strlen($body) > $this->maxBytes;

			return [
				'status'    => $status,
				'body'      => $truncated ? substr($body, 0, $this->maxBytes) : $body,
				'truncated' => $truncated,
			];
		} finally {
			fclose($stream);
		}
	}
}
