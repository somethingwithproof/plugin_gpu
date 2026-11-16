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
 * The common metric model (ADR 0009, REQ-METRIC-001 to REQ-METRIC-007).
 *
 * Every backend fills these fields and nothing else, so one data query, one
 * data template, and one set of graph templates serve every vendor. Units
 * are fixed here: utilization in percent 0 to 100 whatever the source scale,
 * memory in bytes, temperature in degrees C, power in watts, clocks in MHz.
 * A value a backend cannot supply is unknown (U) and is never estimated.
 *
 * docs/metrics.md is generated from table(); a unit test keeps them equal.
 */
final class MetricModel {
	public const MIB = 1048576.0;

	// The field that carries the clock event reasons bitmask (REQ-NVSMI-005).
	// nvidia-smi renamed the reasons in its v530 changelog and added the old
	// query names back in v535 (research/clock-event-reasons.md), so an
	// older nvidia-smi can know only the old name. The metrics entry asks for the current name; a second entry asks for the
	// old one in the same position and is read only when the first is
	// refused. Both print the same bitmask, decoded by the same code.
	public const MASK_FIELD        = 'clocks_event_reasons.active';
	public const LEGACY_MASK_FIELD = 'clocks_throttle_reasons.active';

	// Whether ECC is on, so the health page can say why an ECC value is
	// unknown (REQ-ERR-002). Not a model field: it is stored as text.
	public const ECC_MODE_FIELD = 'ecc.mode.current';

	/**
	 * Fields the plugin computes from two consecutive samples of a total:
	 * delta => total. They are stored as gauges (see all()).
	 */
	public const DELTAS = ['ecc_corr_new' => 'ecc_corr', 'ecc_uncorr_new' => 'ecc_uncorr'];

	// Bits of the mask that get an indicator. The rest (idle, application
	// clocks, sync boost, display, board limit, reliability, and any bit a
	// later driver adds) stay in the raw mask only.
	public const BITS = [
		'thr_sw_power_cap'   => 0x4,
		'thr_hw_slowdown'    => 0x8,
		'thr_sw_thermal'     => 0x20,
		'thr_hw_thermal'     => 0x40,
		'thr_hw_power_brake' => 0x80,
	];

	/**
	 * @return list<Metric>
	 */
	public static function all(): array {
		$gauge = Metric::GAUGE;
		$flag  = Metric::INDICATOR;

		return [
			new Metric('util_gpu', 'GPU utilization', 'percent', $gauge, 0.0, 100.0, 'utilization.gpu', 1.0, null, '1613 (ratio 0.0 to 1.0, times 100), or 203 (percent, deprecated)', 100.0, '`metric --usage` `gfx_activity` (#18)'),
			new Metric('util_mem', 'Memory controller utilization', 'percent', $gauge, 0.0, 100.0, 'utilization.memory', 1.0, null, '1614 (ratio 0.0 to 1.0, times 100), or 204 (unit not stated, #15)', 100.0, '`metric --usage` `umc_activity` (#18)'),
			new Metric('mem_used', 'Frame buffer memory used', 'bytes', $gauge, 0.0, null, 'memory.used', self::MIB, null, '1627 (used, bytes), or 252 (MiB, deprecated)', 1.0, '`metric --mem-usage` `used_vram` (MiB, #18)'),
			new Metric('mem_total', 'Frame buffer memory total', 'bytes', $gauge, 0.0, null, 'memory.total', self::MIB, null, '1625 (capacity, bytes), or 250 (MiB, deprecated); 1626 is free bytes and is not used', 1.0, '`metric --mem-usage` `total_vram` (MiB, #18)'),
			new Metric('temp_gpu', 'GPU temperature', 'degrees C', $gauge, null, null, 'temperature.gpu', 1.0, null, '150', 1.0, '`metric --temperature` `edge` (#18)'),
			new Metric('power', 'Board power draw', 'watts', $gauge, 0.0, null, 'power.draw', 1.0, null, '155', 1.0, '`metric --power` `socket_power`'),
			new Metric('power_limit', 'Board power limit', 'watts', $gauge, 0.0, null, 'power.limit', 1.0, null, '160', 1.0, '#18'),
			new Metric('clock_sm', 'SM clock', 'MHz', $gauge, 0.0, null, 'clocks.sm', 1.0, null, '1603 (hertz, divided by 1,000,000), or 100 (unit not stated)', 1.0e-6, '`metric --clock` (#18)'),
			new Metric('clock_mem', 'Memory clock', 'MHz', $gauge, 0.0, null, 'clocks.mem', 1.0, null, '1604 (hertz, divided by 1,000,000), or 101 (unit not stated)', 1.0e-6, '`metric --clock` (#18)'),
			new Metric('ecc_corr', 'Corrected ECC errors since driver load', 'count', $gauge, 0.0, null, 'ecc.errors.corrected.volatile.total', 1.0, null, '310', 1.0, '`metric --ecc` `total_correctable_count`'),
			new Metric('ecc_uncorr', 'Uncorrected ECC errors since driver load', 'count', $gauge, 0.0, null, 'ecc.errors.uncorrected.volatile.total', 1.0, null, '311', 1.0, '`metric --ecc` `total_uncorrectable_count`'),
			// Each alertable value is a gauge (ADR 0002): Thold 1.8.2 fails on
			// a DERIVE or COUNTER data source that reads U, so the plugin
			// computes the new errors itself from consecutive samples, and a
			// reset at driver load, or a missing value, is unknown.
			new Metric('ecc_corr_new', 'Corrected ECC errors since the previous sample', 'count', $gauge, 0.0, null, null, 1.0, null, 'computed from consecutive `ecc_corr` samples', 1.0, 'computed from consecutive `ecc_corr` samples'),
			new Metric('ecc_uncorr_new', 'Uncorrected ECC errors since the previous sample', 'count', $gauge, 0.0, null, null, 1.0, null, 'computed from consecutive `ecc_uncorr` samples', 1.0, 'computed from consecutive `ecc_uncorr` samples'),
			new Metric('retired_pending', 'Page retirement pending', '0 or 1', $flag, 0.0, 1.0, 'retired_pages.pending', 1.0, null, '392', 1.0, '#18'),
			new Metric('retired_sbe', 'Pages retired after single-bit ECC errors', 'pages', $gauge, 0.0, null, 'retired_pages.sbe', 1.0, null, '390 (`DCGM_FI_DEV_RETIRED_SBE`, unconfirmed, #15)', 1.0, '#18'),
			new Metric('retired_dbe', 'Pages retired after a double-bit ECC error', 'pages', $gauge, 0.0, null, 'retired_pages.dbe', 1.0, null, '391 (`DCGM_FI_DEV_RETIRED_DBE`, unconfirmed, #15)', 1.0, '#18'),
			new Metric('thr_sw_power_cap', 'Clocks held by the software power cap', '0 or 1', $flag, 0.0, 1.0, null, 1.0, self::BITS['thr_sw_power_cap'], 'field 112, bit 0x4', 1.0, '`metric --violation` (MI300 or newer, #18)'),
			new Metric('thr_hw_slowdown', 'Hardware slowdown', '0 or 1', $flag, 0.0, 1.0, null, 1.0, self::BITS['thr_hw_slowdown'], 'field 112, bit 0x8', 1.0, 'not applicable'),
			new Metric('thr_sw_thermal', 'Software thermal slowdown', '0 or 1', $flag, 0.0, 1.0, null, 1.0, self::BITS['thr_sw_thermal'], 'field 112, bit 0x20', 1.0, '`metric --violation` (MI300 or newer, #18)'),
			new Metric('thr_hw_thermal', 'Hardware thermal slowdown', '0 or 1', $flag, 0.0, 1.0, null, 1.0, self::BITS['thr_hw_thermal'], 'field 112, bit 0x40', 1.0, '`metric --violation` (MI300 or newer, #18)'),
			new Metric('thr_hw_power_brake', 'Hardware power brake', '0 or 1', $flag, 0.0, 1.0, null, 1.0, self::BITS['thr_hw_power_brake'], 'field 112, bit 0x80', 1.0, 'not applicable'),
			new Metric('age', 'Seconds since the tool took the sample', 'seconds', $gauge, 0.0, null, null, 1.0, null, 'computed from the sample timestamp', 1.0, 'computed from the sample timestamp'),
		];
	}

	public static function find(string $name): ?Metric {
		foreach (self::all() as $metric) {
			if ($metric->name === $name) {
				return $metric;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	public static function names(): array {
		return array_map(static fn (Metric $metric): string => $metric->name, self::all());
	}

	/**
	 * The nvidia-smi --query-gpu field list, in output order (REQ-NVSMI-001,
	 * REQ-NVSMI-002). Identity and the sample time come first, in the same
	 * query as the metrics, so one line describes one GPU at one moment. The
	 * GPU name is free text that may contain the separator, so it is read by
	 * its own query (namesFields()) and never shifts these fields.
	 *
	 * With $legacy, the clock event reasons field has its earlier name, in
	 * the same position.
	 *
	 * @return list<string>
	 */
	public static function nvidiaFields(bool $legacy = false): array {
		$fields = ['timestamp', 'uuid', 'index', 'pci.bus_id', 'driver_version', self::ECC_MODE_FIELD];

		foreach (self::all() as $metric) {
			if ($metric->nvidiaField !== null) {
				$fields[] = $metric->nvidiaField;
			}
		}

		$fields[] = $legacy ? self::LEGACY_MASK_FIELD : self::MASK_FIELD;

		return $fields;
	}

	/**
	 * @return list<string>
	 */
	public static function namesFields(): array {
		return ['uuid', 'name'];
	}

	/**
	 * The model as the Markdown table in docs/metrics.md.
	 */
	public static function table(): string {
		$rows = ['| Field | Meaning | Unit | Kind | Range | nvidia-smi source | DCGM source | AMD source |', '|---|---|---|---|---|---|---|---|'];

		foreach (self::all() as $metric) {
			$nvidia = match (true) {
				$metric->nvidiaField !== null && $metric->nvidiaScale !== 1.0 => sprintf('`%s` (MiB, times 1,048,576)', $metric->nvidiaField),
				$metric->nvidiaField !== null => sprintf('`%s`', $metric->nvidiaField),
				$metric->maskBit !== null => sprintf('`%s`, bit 0x%X', self::MASK_FIELD, $metric->maskBit),
				isset(self::DELTAS[$metric->name]) => sprintf('computed from consecutive `%s` samples', self::DELTAS[$metric->name]),
				default => 'computed from `timestamp`',
			};

			$rows[] = sprintf('| `%s` | %s | %s | %s | %s to %s | %s | %s | %s |', $metric->name, $metric->label, $metric->unit, $metric->kind, $metric->rrdMin(), $metric->rrdMax(), $nvidia, $metric->dcgm, $metric->amd);
		}

		return implode("\n", $rows) . "\n";
	}
}
