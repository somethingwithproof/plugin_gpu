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
 * Configuration-as-Code (CasC) and operator health provider for plugin_gpu.
 *
 * Supports deterministic YAML and XML export, schema validation, diff planning
 * with SHA-256 confirmation hash, fail-closed apply, and structured health
 * reporting. Uses zero external Composer runtime dependencies.
 */
final class CascProvider {
	public const SCHEMA_VERSION = 1;
	public const FORMATS        = ['yaml', 'xml'];

		/**
	 * Construct a CascProvider instance.
	 *
	 * @param SettingsRepository $settings
	 * @param NodeRepository $nodes
	 * @param GpuRepository $gpus
	 * @param CollectionRepository $collections
	 * @param ObservationRepository $observations
	 * @param Clock $clock
	 * @param int $pollerInterval
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly NodeRepository $nodes,
		private readonly GpuRepository $gpus,
		private readonly CollectionRepository $collections,
		private readonly ObservationRepository $observations,
		private readonly Clock $clock,
		private readonly int $pollerInterval = 300,
	) {
	}

	/**
	 * Current plugin configuration as a canonical array (no secrets).
	 *
	 * @return array{schema_version: int, plugin: string, settings: array<string, int|bool>, nodes: list<array{host_id: int, description: string, admin_state: string}>}
	 */
	public function snapshot(): array {
		$current = $this->settings->load();
		$nodes   = [];

		foreach ($this->nodes->all() as $node) {
			$nodes[] = [
				'host_id'     => $node->hostId,
				'description' => $node->description,
				'admin_state' => $node->adminState->value,
			];
		}

		usort($nodes, static fn (array $a, array $b): int => $a['host_id'] <=> $b['host_id']);

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'plugin'         => 'gpu',
			'settings'       => [
				'gpu_absent_after'             => $current->absentAfter,
				'gpu_stale_after'              => $current->staleAfter,
				'gpu_uninstall_keep_tables'    => $current->keepTablesOnUninstall,
				'gpu_uninstall_remove_objects' => $current->removeObjectsOnUninstall,
			],
			'nodes' => $nodes,
		];
	}

	/**
	 * Export current configuration as deterministic YAML or XML.
	 * @param string $format
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	public function export(string $format = 'yaml'): string {
		$format = strtolower(trim($format));
		$data   = $this->snapshot();

		return match ($format) {
			'yaml'  => self::encodeYaml($data),
			'xml'   => self::encodeXml($data),
			default => throw new \InvalidArgumentException('Unsupported export format: must be yaml or xml'),
		};
	}

	/**
	 * Parse a YAML or XML configuration document safely (LIBXML_NONET, no DTD/XXE).
	 *
	 * @return array<string, mixed>
	 * @param string $content
	 * @param string $format
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $content, string $format = 'yaml'): array {
		$format = strtolower(trim($format));

		return match ($format) {
			'yaml'  => self::decodeYaml($content),
			'xml'   => self::decodeXml($content),
			default => throw new \InvalidArgumentException('Unsupported format: must be yaml or xml'),
		};
	}

	/**
	 * Validate a desired CasC document. Returns an empty list when valid.
	 *
	 * @param array<string, mixed> $desired
	 *
	 * @return list<string>
	 */
	public function validate(array $desired): array {
		$errors = [];

		if (($desired['plugin'] ?? null) !== 'gpu') {
			$errors[] = 'plugin must be "gpu"';
		}

		$schemaVersion = $desired['schema_version'] ?? null;

		if (!is_int($schemaVersion) || $schemaVersion !== self::SCHEMA_VERSION) {
			$errors[] = sprintf('schema_version must be %d', self::SCHEMA_VERSION);
		}

		if (isset($desired['settings'])) {
			if (!is_array($desired['settings'])) {
				$errors[] = 'settings must be a mapping';
			} else {
				foreach ($desired['settings'] as $name => $value) {
					if (!in_array((string) $name, Settings::NAMES, true)) {
						$errors[] = sprintf('unknown setting "%s"', (string) $name);

						continue;
					}

					if ($name === 'gpu_absent_after' && (!is_int($value) || $value < 1 || $value > Settings::MAX_ABSENT_AFTER)) {
						$errors[] = sprintf('gpu_absent_after must be an integer between 1 and %d', Settings::MAX_ABSENT_AFTER);
					}

					if ($name === 'gpu_stale_after' && (!is_int($value) || $value < Settings::MIN_STALE_AFTER || $value > Settings::MAX_STALE_AFTER)) {
						$errors[] = sprintf('gpu_stale_after must be an integer between %d and %d', Settings::MIN_STALE_AFTER, Settings::MAX_STALE_AFTER);
					}

					if (($name === 'gpu_uninstall_keep_tables' || $name === 'gpu_uninstall_remove_objects') && !is_bool($value)) {
						$errors[] = sprintf('%s must be a boolean', (string) $name);
					}
				}
			}
		}

		if (isset($desired['nodes'])) {
			if (!is_array($desired['nodes']) || !array_is_list($desired['nodes'])) {
				$errors[] = 'nodes must be a list';
			} else {
				$seenHosts = [];

				foreach ($desired['nodes'] as $i => $node) {
					if (!is_array($node)) {
						$errors[] = sprintf('nodes[%d] must be a mapping', $i);

						continue;
					}

					$hostId = $node['host_id'] ?? null;

					if (!is_int($hostId) || $hostId < 1) {
						$errors[] = sprintf('nodes[%d].host_id must be a positive integer', $i);
					} elseif (isset($seenHosts[$hostId])) {
						$errors[] = sprintf('nodes[%d].host_id %d is duplicated', $i, $hostId);
					} else {
						$seenHosts[$hostId] = true;
					}

					$state = $node['admin_state'] ?? null;

					if (!is_string($state) || AdminState::tryFrom($state) === null) {
						$errors[] = sprintf('nodes[%d].admin_state must be enabled or disabled', $i);
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Compute a deterministic plan and SHA-256 confirmation hash.
	 *
	 * @param array<string, mixed> $desired
	 *
	 * @return array{valid: bool, errors: list<string>, changes: array{settings: array<string, array{from: int|bool, to: int|bool}>, nodes: list<array{action: string, host_id: int, from: ?string, to: string}>}, plan_hash: string}
	 */
	public function plan(array $desired): array {
		$errors = $this->validate($desired);

		if ($errors !== []) {
			return [
				'valid'     => false,
				'errors'    => $errors,
				'changes'   => ['settings' => [], 'nodes' => []],
				'plan_hash' => '',
			];
		}

		$current         = $this->snapshot();
		$settingChanges  = [];
		$desiredSettings = isset($desired['settings']) && is_array($desired['settings']) ? $desired['settings'] : [];

		foreach (Settings::NAMES as $name) {
			if (!array_key_exists($name, $desiredSettings)) {
				continue;
			}

			$from = $current['settings'][$name];
			$to   = $desiredSettings[$name];

			if ((is_int($to) || is_bool($to)) && $from !== $to) {
				$settingChanges[$name] = ['from' => $from, 'to' => $to];
			}
		}

		ksort($settingChanges);

		$existingByHost = [];

		foreach ($current['nodes'] as $node) {
			$existingByHost[$node['host_id']] = $node['admin_state'];
		}

		$nodeChanges  = [];
		$desiredNodes = isset($desired['nodes']) && is_array($desired['nodes']) ? $desired['nodes'] : [];

		foreach ($desiredNodes as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$hostId = is_int($entry['host_id'] ?? null) ? $entry['host_id'] : 0;
			$state  = is_string($entry['admin_state'] ?? null) ? $entry['admin_state'] : 'enabled';

			if ($hostId < 1) {
				continue;
			}

			if (!isset($existingByHost[$hostId])) {
				$nodeChanges[] = ['action' => 'create', 'host_id' => $hostId, 'from' => null, 'to' => $state];
			} elseif ($existingByHost[$hostId] !== $state) {
				$nodeChanges[] = ['action' => 'update', 'host_id' => $hostId, 'from' => $existingByHost[$hostId], 'to' => $state];
			}
		}

		usort($nodeChanges, static fn (array $a, array $b): int => $a['host_id'] <=> $b['host_id']);

		$changes = ['settings' => $settingChanges, 'nodes' => $nodeChanges];
		$hash    = hash('sha256', json_encode($changes, JSON_THROW_ON_ERROR));

		return [
			'valid'     => true,
			'errors'    => [],
			'changes'   => $changes,
			'plan_hash' => $hash,
		];
	}

	/**
	 * Apply desired configuration only when $confirmHash matches the computed plan hash.
	 *
	 * @param array<string, mixed> $desired
	 *
	 * @return array{applied: bool, plan_hash: string, settings_updated: int, nodes_created: int, nodes_updated: int, errors: list<string>}
	 * @param string $confirmHash
	 */
	public function apply(array $desired, string $confirmHash): array {
		$plan = $this->plan($desired);

		if (!$plan['valid']) {
			return [
				'applied'          => false,
				'plan_hash'        => '',
				'settings_updated' => 0,
				'nodes_created'    => 0,
				'nodes_updated'    => 0,
				'errors'           => $plan['errors'],
			];
		}

		if ($confirmHash === '' || !hash_equals($plan['plan_hash'], trim($confirmHash))) {
			return [
				'applied'          => false,
				'plan_hash'        => $plan['plan_hash'],
				'settings_updated' => 0,
				'nodes_created'    => 0,
				'nodes_updated'    => 0,
				'errors'           => ['Confirmation hash does not match the current plan hash; re-run plan and pass --confirm=' . $plan['plan_hash']],
			];
		}

		$settingsUpdated = 0;

		foreach ($plan['changes']['settings'] as $name => $change) {
			$stored = is_bool($change['to']) ? ($change['to'] ? 'on' : '') : (string) $change['to'];

			if ($this->settings->save($name, $stored)) {
				$settingsUpdated++;
			}
		}

		$nodesCreated = 0;
		$nodesUpdated = 0;

		foreach ($plan['changes']['nodes'] as $change) {
			$state = AdminState::tryFrom($change['to']) ?? AdminState::Disabled;

			if ($change['action'] === 'create') {
				if ($this->nodes->save(0, $change['host_id'], $state) > 0) {
					$nodesCreated++;
				}
			} elseif ($change['action'] === 'update') {
				$existing = $this->nodes->findByHost($change['host_id']);

				if ($existing !== null && $this->nodes->save($existing->id, $existing->hostId, $state) > 0) {
					$nodesUpdated++;
				}
			}
		}

		return [
			'applied'          => true,
			'plan_hash'        => $plan['plan_hash'],
			'settings_updated' => $settingsUpdated,
			'nodes_created'    => $nodesCreated,
			'nodes_updated'    => $nodesUpdated,
			'errors'           => [],
		];
	}

	/**
	 * Build a structured health report from stored records only (never contacts a node).
	 *
	 * @return array{plugin: string, status: string, checked_at: string, summary: array<string, int>, nodes: list<array<string, mixed>>}
	 */
	public function health(): array {
		$now         = $this->clock->now();
		$settings    = $this->settings->load();
		$nodes       = $this->nodes->all();
		$gpus        = $this->gpus->all();
		$collections = $this->collections->all();
		$samples     = $this->observations->summaries();

		$byNode = [];

		foreach ($gpus as $gpu) {
			$byNode[$gpu->nodeId][] = $gpu;
		}

		$summary = [
			'nodes_total'   => count($nodes),
			'nodes_enabled' => 0,
			'nodes_healthy' => 0,
			'nodes_stale'   => 0,
			'nodes_failed'  => 0,
			'gpus_total'    => count($gpus),
			'gpus_present'  => 0,
			'gpus_absent'   => 0,
			'mig_instances' => 0,
			'pending_moves' => 0,
		];

		foreach ($gpus as $gpu) {
			if ($gpu->presence === Presence::Present) {
				$summary['gpus_present']++;
			} else {
				$summary['gpus_absent']++;
			}

			if ($gpu->isMig()) {
				$summary['mig_instances']++;
			}

			if ($gpu->movePending()) {
				$summary['pending_moves']++;
			}
		}

		$nodeRows = [];

		foreach ($nodes as $node) {
			if ($node->enabled()) {
				$summary['nodes_enabled']++;
			}

			$status   = $collections[$node->id] ?? null;
			$nodeGpus = $byNode[$node->id] ?? [];
			$newest   = $status === null ? 0 : $status->observedAt;

			foreach ($nodeGpus as $gpu) {
				$sample = $samples[$gpu->id] ?? null;

				if ($sample !== null) {
					$newest = max($newest, Row::int($sample, 'observed_at', 0, 0));
				}
			}

			$freshness = FreshnessState::of($newest, $now, $this->pollerInterval, $settings->staleAfter);
			$failed    = $status !== null && $status->state !== null && !$status->succeeded();

			if ($node->enabled()) {
				if ($failed) {
					$summary['nodes_failed']++;
				} elseif ($freshness === FreshnessState::Stale || $freshness === FreshnessState::Never || $freshness === FreshnessState::Ahead) {
					$summary['nodes_stale']++;
				} else {
					$summary['nodes_healthy']++;
				}
			}

			$nodeRows[] = [
				'node_id'          => $node->id,
				'host_id'          => $node->hostId,
				'description'      => $node->description,
				'admin_state'      => $node->adminState->value,
				'freshness'        => $freshness->value,
				'collection_state' => $status !== null && $status->state !== null ? $status->state->value : 'never',
				'gpus'             => count($nodeGpus),
				'mig_instances'    => count(array_filter($nodeGpus, static fn (GpuRecord $g): bool => $g->isMig())),
			];
		}

		$overall = 'ok';

		if ($summary['nodes_failed'] > 0 || $summary['nodes_stale'] > 0) {
			$overall = 'degraded';
		} elseif ($summary['pending_moves'] > 0 || $summary['gpus_absent'] > 0) {
			$overall = 'warning';
		}

		return [
			'plugin'     => 'gpu',
			'status'     => $overall,
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
			'summary'    => $summary,
			'nodes'      => $nodeRows,
		];
	}

	/**
	 * Render health report as plain text or JSON.
	 * @param string $format
	 * @return string
	 */
	public function formatHealth(string $format = 'text'): string {
		$report = $this->health();

		if (strtolower(trim($format)) === 'json') {
			return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		}

		$s = $report['summary'];
		$lines = [
			sprintf('plugin_gpu health: %s (%s)', strtoupper($report['status']), $report['checked_at']),
			sprintf('Nodes: %d total, %d enabled, %d healthy, %d stale, %d failed', $s['nodes_total'], $s['nodes_enabled'], $s['nodes_healthy'], $s['nodes_stale'], $s['nodes_failed']),
			sprintf('GPUs: %d total, %d present, %d absent, %d MIG instances, %d pending moves', $s['gpus_total'], $s['gpus_present'], $s['gpus_absent'], $s['mig_instances'], $s['pending_moves']),
		];

		foreach ($report['nodes'] as $row) {
			$lines[] = sprintf(
				'  - node#%d (host %d, %s): state=%s freshness=%s collection=%s gpus=%d mig=%d',
				Row::int($row, 'node_id'),
				Row::int($row, 'host_id'),
				Row::string($row, 'description'),
				Row::string($row, 'admin_state'),
				Row::string($row, 'freshness'),
				Row::string($row, 'collection_state'),
				Row::int($row, 'gpus'),
				Row::int($row, 'mig_instances'),
			);
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * @param array{schema_version: int, plugin: string, settings: array<string, int|bool>, nodes: list<array{host_id: int, description: string, admin_state: string}>} $data
	 * @return string
	 */
	private static function encodeYaml(array $data): string {
		$lines = [
			'schema_version: ' . $data['schema_version'],
			'plugin: ' . self::yamlScalar($data['plugin']),
			'settings:',
		];

		foreach ($data['settings'] as $key => $value) {
			$lines[] = '  ' . $key . ': ' . self::yamlScalar($value);
		}

		if ($data['nodes'] === []) {
			$lines[] = 'nodes: []';
		} else {
			$lines[] = 'nodes:';

			foreach ($data['nodes'] as $node) {
				$lines[] = '  - host_id: ' . $node['host_id'];
				$lines[] = '    description: ' . self::yamlScalar($node['description']);
				$lines[] = '    admin_state: ' . self::yamlScalar($node['admin_state']);
			}
		}

		return implode("\n", $lines) . "\n";
	}

		/**
	 * Yaml scalar.
	 *
	 * @param int|bool|string $value
	 * @return string
	 */
	private static function yamlScalar(int|bool|string $value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (preg_match('/^[a-zA-Z0-9._-]+$/', $value) === 1 && !in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no'], true)) {
			return $value;
		}

		return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
	}

	/**
	 * Minimal, strict YAML subset parser matching export() output without external dependencies.
	 *
	 * @return array<string, mixed>
	 * @param string $content
	 * @throws \InvalidArgumentException
	 */
	private static function decodeYaml(string $content): array {
		$result  = [];
		$section = '';
		$current = null;

		foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $rawLine) {
			$trimmed = trim($rawLine);

			if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '---') {
				continue;
			}

			if (preg_match('/^([a-zA-Z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				$key   = $m[1];
				$value = trim($m[2]);

				if ($value === '') {
					$section      = $key;
					$result[$key] = [];
					$current      = null;
				} elseif ($value === '[]') {
					$section      = '';
					$result[$key] = [];
					$current      = null;
				} else {
					$section      = '';
					$result[$key] = self::parseYamlValue($value);
					$current      = null;
				}

				continue;
			}

			if ($section === 'settings' && preg_match('/^\s+([a-zA-Z0-9_]+):\s*(.+)$/', $rawLine, $m) === 1) {
				if (!isset($result['settings']) || !is_array($result['settings'])) {
					$result['settings'] = [];
				}

				$result['settings'][$m[1]] = self::parseYamlValue(trim($m[2]));

				continue;
			}

			if ($section === 'nodes' && preg_match('/^\s+-\s+([a-zA-Z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				if (!isset($result['nodes']) || !is_array($result['nodes'])) {
					$result['nodes'] = [];
				}

				$result['nodes'][] = [$m[1] => self::parseYamlValue(trim($m[2]))];
				$current           = count($result['nodes']) - 1;

				continue;
			}

			if ($section === 'nodes' && $current !== null && preg_match('/^\s+([a-zA-Z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				if (isset($result['nodes']) && is_array($result['nodes']) && isset($result['nodes'][$current]) && is_array($result['nodes'][$current])) {
					$result['nodes'][$current][$m[1]] = self::parseYamlValue(trim($m[2]));
				}

				continue;
			}

			throw new \InvalidArgumentException('Unsupported or malformed YAML syntax');
		}

		return $result;
	}

		/**
	 * Parse yaml value.
	 *
	 * @param string $raw
	 * @return int|bool|string
	 */
	private static function parseYamlValue(string $raw): int|bool|string {
		if ($raw === 'true') {
			return true;
		}

		if ($raw === 'false') {
			return false;
		}

		if (preg_match('/^-?[0-9]+$/', $raw) === 1) {
			return (int) $raw;
		}

		if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[strlen($raw) - 1] === '"') {
			return str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], substr($raw, 1, -1));
		}

		if (strlen($raw) >= 2 && $raw[0] === "'" && $raw[strlen($raw) - 1] === "'") {
			return str_replace("''", "'", substr($raw, 1, -1));
		}

		return $raw;
	}

	/**
	 * @param array{schema_version: int, plugin: string, settings: array<string, int|bool>, nodes: list<array{host_id: int, description: string, admin_state: string}>} $data
	 * @return string
	 */
	private static function encodeXml(array $data): string {
		$doc               = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;

		$root = $doc->createElement('casc');
		$root->setAttribute('plugin', $data['plugin']);
		$root->setAttribute('schema_version', (string) $data['schema_version']);
		$doc->appendChild($root);

		$settingsEl = $doc->createElement('settings');

		foreach ($data['settings'] as $key => $value) {
			$el = $doc->createElement('setting', is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
			$el->setAttribute('name', $key);
			$el->setAttribute('type', is_bool($value) ? 'bool' : 'int');
			$settingsEl->appendChild($el);
		}

		$root->appendChild($settingsEl);

		$nodesEl = $doc->createElement('nodes');

		foreach ($data['nodes'] as $node) {
			$el = $doc->createElement('node');
			$el->setAttribute('host_id', (string) $node['host_id']);
			$el->setAttribute('admin_state', $node['admin_state']);
			$el->setAttribute('description', $node['description']);
			$nodesEl->appendChild($el);
		}

		$root->appendChild($nodesEl);

		$xml = $doc->saveXML();

		return is_string($xml) ? $xml : '';
	}

	/**
	 * @return array<string, mixed>
	 * @param string $content
	 * @throws \InvalidArgumentException
	 */
	private static function decodeXml(string $content): array {
		if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
			throw new \InvalidArgumentException('XML DOCTYPE and ENTITY declarations are forbidden');
		}

		$previous = libxml_use_internal_errors(true);

		try {
			$doc = new \DOMDocument();
			$ok  = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA);
			libxml_clear_errors();
		} finally {
			libxml_use_internal_errors($previous);
		}

		if (!$ok || $doc->documentElement === null || $doc->documentElement->nodeName !== 'casc') {
			throw new \InvalidArgumentException('Invalid CasC XML document');
		}

		$root   = $doc->documentElement;
		$result = [
			'plugin'         => $root->getAttribute('plugin'),
			'schema_version' => ctype_digit($root->getAttribute('schema_version')) ? (int) $root->getAttribute('schema_version') : 0,
			'settings'       => [],
			'nodes'          => [],
		];

		foreach ($root->getElementsByTagName('setting') as $el) {
			$name = $el->getAttribute('name');
			$type = $el->getAttribute('type');
			$val  = trim($el->textContent);

			if ($type === 'bool') {
				$result['settings'][$name] = $val === 'true';
			} elseif ($type === 'int' && preg_match('/^-?[0-9]+$/', $val) === 1) {
				$result['settings'][$name] = (int) $val;
			} else {
				$result['settings'][$name] = self::parseYamlValue($val);
			}
		}

		foreach ($root->getElementsByTagName('node') as $el) {
			$hostAttr = $el->getAttribute('host_id');
			$result['nodes'][] = [
				'host_id'     => ctype_digit($hostAttr) ? (int) $hostAttr : 0,
				'description' => $el->getAttribute('description'),
				'admin_state' => $el->getAttribute('admin_state'),
			];
		}

		return $result;
	}
}
