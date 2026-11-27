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
 * The snmpd.conf lines a GPU node's administrator installs (ADR 0008). They
 * are generated here so the documentation, the collector's entry names, and
 * the field list the parser expects cannot drift apart; a unit test compares
 * them with docs/node-setup.md.
 *
 * Each entry runs nvidia-smi with a fixed argument vector and no shell
 * (extend without -execType sh), with an empty environment apart from TZ=UTC
 * so the sample time is UTC, under timeout(1) so the child always exits
 * before snmpd's cache for the entry expires. The plugin sends the agent
 * nothing but read requests.
 */
final class SnmpdConfig {
	public const METRICS        = 'cacti_gpu';
	public const METRICS_LEGACY = 'cacti_gpu_legacy';
	public const NAMES          = 'cacti_gpu_names';

	// nvidia-smi's documented return value for an invalid argument, which is
	// what it gives for a query field the driver does not know.
	public const INVALID_ARGUMENT = 2;

	// Seconds snmpd keeps a result. Every Cacti poll inside the window sees
	// the same sample, so the window bounds how fresh a value can be.
	public const METRICS_CACHE = 30;
	public const NAMES_CACHE   = 300;

	// timeout(1) sends TERM after TIMEOUT seconds and KILL KILL_AFTER later,
	// so the command ends well inside the cache window.
	public const TIMEOUT    = 10;
	public const KILL_AFTER = 2;

	public const NVIDIA_SMI = '/usr/bin/nvidia-smi';

	// extend.c formats the command and its arguments into 255 * 2 + 2 bytes.
	public const MAX_COMMAND = 510;

	/**
	 * The entries every node needs.
	 *
	 * @return list<string>
	 */
	public static function extendLines(): array {
		return [
			self::line(self::METRICS, self::METRICS_CACHE, ['TZ=UTC'], MetricModel::nvidiaFields(), 'csv,noheader,nounits'),
			self::line(self::NAMES, self::NAMES_CACHE, [], MetricModel::namesFields(), 'csv,noheader'),
		];
	}

	/**
	 * The optional entry for an nvidia-smi older than the clocks_event_reasons
	 * rename, which refuses the metrics entry's field name (REQ-NVSMI-005).
	 *
	 * @return list<string>
	 */
	public static function legacyLines(): array {
		return [self::line(self::METRICS_LEGACY, self::METRICS_CACHE, ['TZ=UTC'], MetricModel::nvidiaFields(true), 'csv,noheader,nounits')];
	}

	/**
	 * The command and arguments of each entry, as extend.c formats them.
	 *
	 * @return array<string, string> entry name => command line
	 */
	public static function commands(): array {
		$out = [];

		foreach ([...self::extendLines(), ...self::legacyLines()] as $line) {
			$parts = explode(' ', $line, 5);

			$out[$parts[3]] = $parts[4];
		}

		return $out;
	}

	/**
	 * @param list<string> $env
	 * @param list<string> $fields
	 * @param string $name
	 * @param int $cache
	 * @param string $format
	 * @return string
	 */
	private static function line(string $name, int $cache, array $env, array $fields, string $format): string {
		return implode(' ', array_merge(
			['extend', '-cacheTime', (string) $cache, $name, '/usr/bin/env', '-i'],
			$env,
			['/usr/bin/timeout', '-k', (string) self::KILL_AFTER, (string) self::TIMEOUT, self::NVIDIA_SMI, '--query-gpu=' . implode(',', $fields), '--format=' . $format],
		));
	}
}
