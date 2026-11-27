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
 * Read-only parser for AMD amd-smi JSON/SNMP-extend output
 * (REQ-AMD-001 to REQ-AMD-006, REQ-AMDM-001 to REQ-AMDM-004).
 *
 * Normalizes amd-smi --json output (list + metric invocations matched on
 * stable UUID/BDF identity, or a single combined JSON payload) into the
 * unified MetricModel:
 * - Utilization: gfx_activity -> util_gpu, umc_activity -> util_mem
 * - VRAM: used_vram / total_vram -> mem_used / mem_total (unit-aware -> bytes)
 * - Power: socket_power -> power, power_limit -> power_limit (watts)
 * - Temperature: edge (fallback hotspot/junction) -> temp_gpu (degrees C);
 *   mem (HBM) and hotspot/junction preserved in Reading::$vendorExtensions
 * - Clocks: gfx / sclk -> clock_sm, mem / mclk -> clock_mem (MHz)
 * - ECC: total_correctable_count / total_uncorrectable_count -> ecc_corr / ecc_uncorr
 * - Interconnect and cooling: XGMI TX/RX & errors, PCIe TX/RX & replay errors,
 *   and fan speed % captured in Reading::$vendorExtensions
 * - Throttling: MI300+ violation status flags mapped to common throttle indicators;
 *   raw accumulators preserved separately in Reading::$vendorExtensions and NEVER
 *   presented as the NVIDIA clock event bitmask (REQ-AMDM-003).
 * - Compute and memory partitions recorded as attributes (REQ-AMD-006).
 */
final class AmdSmiParser {
	public const BACKEND = 'amd-smi';

	/**
	 * Parse amd-smi list and metric JSON payloads (for example from SNMP extend entries).
	 *
	 * @param ExtendOutput $listOutput   SNMP extend output for amd-smi list --json
	 * @param ExtendOutput $metricOutput SNMP extend output for amd-smi metric --json
	 * @param int          $observedAt   Unix timestamp when the sample was collected
	 *
	 * @return Collection
	 *
	 * @throws CollectionError
	 */
	public function parseExtend(ExtendOutput $listOutput, ExtendOutput $metricOutput, int $observedAt): Collection {
		$this->checkExtend($listOutput, 'AMD GPU list');
		$this->checkExtend($metricOutput, 'AMD GPU metrics');

		return $this->parseJson(implode("\n", $listOutput->lines), implode("\n", $metricOutput->lines), $observedAt);
	}

	/**
	 * Parse amd-smi JSON output from separate list and metric invocations,
	 * matching entries on stable GPU identity and rejecting mismatches (REQ-AMD-002).
	 *
	 * @param string $listJson    JSON payload from amd-smi list --json
	 * @param string $metricJson  JSON payload from amd-smi metric --json
	 * @param int    $observedAt  Unix timestamp when the sample was collected
	 * @param string $toolVersion optional amd-smi version override
	 *
	 * @return Collection
	 *
	 * @throws CollectionError
	 */
	public function parseJson(string $listJson, string $metricJson, int $observedAt, string $toolVersion = ''): Collection {
		$listData   = $this->decodeList($listJson, 'AMD list');
		$metricData = $this->decodeList($metricJson, 'AMD metric');

		/** @var array<string, array<array-key, mixed>> $identityByKey */
		$identityByKey = [];
		/** @var array<int|string, array<array-key, mixed>> $identityByGpu */
		$identityByGpu = [];

		foreach ($listData as $index => $entry) {
			$uuid = $this->stringField($entry, ['uuid', 'gpu_uuid', 'hip_uuid']);
			$bdf  = $this->stringField($entry, ['bdf', 'gpu_bdf', 'pci_bus_id']);

			if ($uuid === '' || $bdf === '') {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('AMD list entry %d is missing uuid or bdf', $index + 1), 1);
			}

			$normUuid = strtolower($uuid);

			if (isset($identityByKey[$normUuid])) {
				throw new CollectionError(ExecutionState::InvalidOutput, 'AMD list reported duplicate GPU UUID', 1);
			}

			$identityByKey[$normUuid] = $entry;

			if (isset($entry['gpu']) && (is_int($entry['gpu']) || (is_string($entry['gpu']) && ctype_digit($entry['gpu'])))) {
				$identityByGpu[(string) $entry['gpu']] = $entry;
			}
		}

		$reports  = [];
		$readings = [];
		$driver   = '';

		foreach ($metricData as $index => $entry) {
			$matched = $this->matchIdentity($entry, $identityByKey, $identityByGpu, $index + 1);

			$uuid        = $this->stringField($matched, ['uuid', 'gpu_uuid', 'hip_uuid']);
			$bdf         = $this->stringField($matched, ['bdf', 'gpu_bdf', 'pci_bus_id']);
			$gpuIndex    = $this->intField($matched, ['gpu', 'gpu_index']);
			$name        = $this->stringField($matched, ['market_name', 'product_name', 'asic_name', 'name']);
			$entryDriver = $this->stringField($matched, ['driver_version', 'rocm_version']);

			if ($name === '' && isset($entry['asic']) && is_array($entry['asic'])) {
				$name = $this->stringField($entry['asic'], ['market_name', 'asic_name']);
			}

			if ($entryDriver === '' && isset($entry['driver']) && is_array($entry['driver'])) {
				$entryDriver = $this->stringField($entry['driver'], ['version']);
			}

			$partition = $this->partitionString($matched, $entry);

			try {
				$report = GpuReport::create(
					Vendor::Amd,
					$uuid,
					$gpuIndex,
					$bdf,
					$name,
					$entryDriver,
					'',
					$partition,
				);
			} catch (\InvalidArgumentException $e) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('AMD metric entry %d: %s', $index + 1, $e->getMessage()), 1);
			}

			$values     = $this->extractMetrics($entry);
			$extensions = $this->extractVendorExtensions($entry);
			$eccMode    = $this->extractEccMode($entry, $values);

			if ($driver === '' && $report->driverVersion !== '') {
				$driver = $report->driverVersion;
			}

			$reports[]  = $report;
			// Mask stays null for AMD; throttle accumulators are never coerced into NVIDIA's bitmask (REQ-AMDM-003).
			$readings[] = new Reading($report, $observedAt, $values, null, $eccMode, $extensions);
		}

		try {
			$snapshot = GpuSnapshot::create(self::BACKEND, $toolVersion !== '' ? $toolVersion : $driver, $reports);
		} catch (\InvalidArgumentException $e) {
			throw new CollectionError(ExecutionState::InvalidOutput, $e->getMessage());
		}

		return new Collection($snapshot, $readings, self::BACKEND);
	}

	/**
	 * Verify that an SNMP extend output succeeded and was not truncated.
	 *
	 * @param ExtendOutput $output SNMP extend command result
	 * @param string       $what   human-readable label for error reporting
	 *
	 * @return void
	 *
	 * @throws CollectionError
	 */
	private function checkExtend(ExtendOutput $output, string $what): void {
		if ($output->result !== 0) {
			throw new CollectionError(ExecutionState::fromExitStatus($output->result), sprintf('The %s command exited with status %d', $what, $output->result));
		}

		if ($output->truncated()) {
			throw new CollectionError(ExecutionState::Truncated, sprintf('The %s output was cut short or reached the SNMP agent\'s limit', $what));
		}
	}

	/**
	 * Decode an amd-smi JSON payload into a list of GPU associative arrays.
	 *
	 * @param string $json  raw JSON string
	 * @param string $label human-readable source name for error messages
	 *
	 * @return list<array<array-key, mixed>>
	 *
	 * @throws CollectionError
	 */
	private function decodeList(string $json, string $label): array {
		$trimmed = trim($json);

		if ($trimmed === '') {
			return [];
		}

		try {
			$decoded = json_decode($trimmed, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('%s output is not valid JSON', $label), 1);
		}

		if (!is_array($decoded)) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('%s JSON root must be a list or object', $label), 1);
		}

		if (isset($decoded['gpu_data']) && is_array($decoded['gpu_data'])) {
			$decoded = $decoded['gpu_data'];
		}

		if (!array_is_list($decoded)) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('%s JSON payload must be a list of GPU objects', $label), 1);
		}

		$items = [];

		foreach ($decoded as $i => $item) {
			if (!is_array($item)) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('%s item %d is not an object', $label, $i + 1), 1);
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Match a metric entry to its identity record from amd-smi list and reject
	 * any BDF or UUID mismatch (REQ-AMD-002).
	 *
	 * @param array<array-key, mixed>                    $entry         metric record
	 * @param array<string, array<array-key, mixed>>     $identityByKey lowercased UUID => list entry
	 * @param array<int|string, array<array-key, mixed>> $identityByGpu GPU index => list entry
	 * @param int                                        $position      1-based entry index for errors
	 *
	 * @return array<array-key, mixed>
	 *
	 * @throws CollectionError
	 */
	private function matchIdentity(array $entry, array $identityByKey, array $identityByGpu, int $position): array {
		$entryUuid = strtolower($this->stringField($entry, ['uuid', 'gpu_uuid', 'hip_uuid']));
		$entryBdf  = strtolower($this->stringField($entry, ['bdf', 'gpu_bdf', 'pci_bus_id']));
		$matched   = null;

		if ($entryUuid !== '') {
			$matched = $identityByKey[$entryUuid] ?? null;

			if ($matched === null) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('AMD metric entry %d UUID does not match amd-smi list', $position), 1);
			}
		} elseif (isset($entry['gpu']) && (is_int($entry['gpu']) || (is_string($entry['gpu']) && ctype_digit($entry['gpu'])))) {
			$matched = $identityByGpu[(string) $entry['gpu']] ?? null;

			if ($matched === null) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('AMD metric entry %d GPU index does not match amd-smi list', $position), 1);
			}
		} else {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('AMD metric entry %d has no stable identity key', $position), 1);
		}

		$listBdf = strtolower($this->stringField($matched, ['bdf', 'gpu_bdf', 'pci_bus_id']));

		if ($entryBdf !== '' && $listBdf !== '' && $entryBdf !== $listBdf) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('AMD metric entry %d BDF does not match amd-smi list', $position), 1);
		}

		return $matched;
	}

	/**
	 * Extract unified MetricModel fields from one amd-smi metric entry.
	 *
	 * @param array<array-key, mixed> $entry decoded amd-smi metric object
	 *
	 * @return array<string, ?float>
	 */
	private function extractMetrics(array $entry): array {
		$usage    = $this->subArray($entry, 'usage');
		$mem      = $this->subArray($entry, 'mem_usage');
		$power    = $this->subArray($entry, 'power');
		$temp     = $this->subArray($entry, 'temperature');
		$clock    = $this->subArray($entry, 'clock');
		$ecc      = $this->subArray($entry, 'ecc');
		$throttle = $this->subArray($entry, 'throttle');

		$edgeTemp     = $this->unwrapNumber($temp['edge'] ?? null);
		$junctionTemp = $this->unwrapNumber($temp['hotspot'] ?? $temp['junction'] ?? null);

		$gfxClock = $this->extractClockMhz($clock, ['gfx_0', 'gfx', 'sclk']);
		$memClock = $this->extractClockMhz($clock, ['mem_0', 'mem', 'mclk']);

		$pptViolation = $this->extractViolationFlag($throttle, ['ppt_violation_status', 'ppt_accumulated']);
		$swThermFlag  = $this->extractViolationFlag($throttle, ['socket_thermal_violation_status']);
		$hwThermFlag  = $this->maxFlag(
			$this->extractViolationFlag($throttle, ['hbm_thermal_violation_status']),
			$this->extractViolationFlag($throttle, ['vr_thermal_violation_status']),
		);

		return [
			'util_gpu'           => $this->unwrapNumber($usage['gfx_activity'] ?? null),
			'util_mem'           => $this->unwrapNumber($usage['umc_activity'] ?? null),
			'mem_used'           => $this->unwrapBytes($mem['used_vram'] ?? null),
			'mem_total'          => $this->unwrapBytes($mem['total_vram'] ?? null),
			'temp_gpu'           => $edgeTemp ?? $junctionTemp,
			'power'              => $this->unwrapNumber($power['socket_power'] ?? $power['average_socket_power'] ?? null),
			'power_limit'        => $this->unwrapNumber($power['power_limit'] ?? $power['max_power_limit'] ?? null),
			'clock_sm'           => $gfxClock,
			'clock_mem'          => $memClock,
			'ecc_corr'           => $this->unwrapNumber($ecc['total_correctable_count'] ?? null),
			'ecc_uncorr'         => $this->unwrapNumber($ecc['total_uncorrectable_count'] ?? null),
			'retired_pending'    => null,
			'retired_sbe'        => null,
			'retired_dbe'        => null,
			'thr_sw_power_cap'   => $pptViolation,
			'thr_hw_slowdown'    => null,
			'thr_sw_thermal'     => $swThermFlag,
			'thr_hw_thermal'     => $hwThermFlag,
			'thr_hw_power_brake' => null,
		];
	}

	/**
	 * Extract AMD throttle accumulators (REQ-AMDM-003) alongside extended
	 * enterprise telemetry (HBM/hotspot temperatures, XGMI and PCIe link
	 * throughput and errors, and fan speed) into Reading::$vendorExtensions.
	 *
	 * @param array<array-key, mixed> $entry decoded amd-smi metric object
	 *
	 * @return array<string, ?float>
	 */
	private function extractVendorExtensions(array $entry): array {
		$out      = [];
		$throttle = $this->subArray($entry, 'throttle');
		$keys     = [
			'accumulation_counter',
			'prochot_accumulated',
			'ppt_accumulated',
			'socket_thermal_accumulated',
			'vr_thermal_accumulated',
			'hbm_thermal_accumulated',
			'low_utilization_accumulated',
		];

		foreach ($keys as $key) {
			if (array_key_exists($key, $throttle)) {
				$out[$key] = $this->unwrapNumber($throttle[$key]);
			}
		}

		$temp = $this->subArray($entry, 'temperature');

		if (array_key_exists('mem', $temp) || array_key_exists('hbm', $temp)) {
			$out[ExtendedGpuTelemetry::KEY_TEMP_MEMORY] = $this->unwrapNumber($temp['mem'] ?? $temp['hbm'] ?? null);
		}

		if (array_key_exists('hotspot', $temp) || array_key_exists('junction', $temp)) {
			$out[ExtendedGpuTelemetry::KEY_TEMP_HOTSPOT] = $this->unwrapNumber($temp['hotspot'] ?? $temp['junction'] ?? null);
		}

		$fan = $this->subArray($entry, 'fan');

		if (array_key_exists('usage', $fan) || array_key_exists('speed', $fan)) {
			$out[ExtendedGpuTelemetry::KEY_FAN_SPEED_PCT] = $this->unwrapNumber($fan['usage'] ?? $fan['speed'] ?? null);
		}

		$pcie = $this->subArray($entry, 'pcie');

		if (array_key_exists('tx', $pcie) || array_key_exists('tx_bytes', $pcie) || array_key_exists('bandwidth_tx', $pcie)) {
			$out[ExtendedGpuTelemetry::KEY_PCIE_TX_BYTES] = $this->unwrapThroughputBytes($pcie['tx_bytes'] ?? $pcie['tx'] ?? $pcie['bandwidth_tx'] ?? null);
		}

		if (array_key_exists('rx', $pcie) || array_key_exists('rx_bytes', $pcie) || array_key_exists('bandwidth_rx', $pcie)) {
			$out[ExtendedGpuTelemetry::KEY_PCIE_RX_BYTES] = $this->unwrapThroughputBytes($pcie['rx_bytes'] ?? $pcie['rx'] ?? $pcie['bandwidth_rx'] ?? null);
		}

		if (array_key_exists('replay_count', $pcie)) {
			$out[ExtendedGpuTelemetry::KEY_PCIE_REPLAY_ERRORS] = $this->unwrapNumber($pcie['replay_count']);
		}

		$xgmi = $this->subArray($entry, 'xgmi');

		if (array_key_exists('tx', $xgmi) || array_key_exists('tx_bytes', $xgmi) || array_key_exists('write_bytes', $xgmi)) {
			$out[ExtendedGpuTelemetry::KEY_NVLINK_TX_BYTES] = $this->unwrapThroughputBytes($xgmi['tx_bytes'] ?? $xgmi['tx'] ?? $xgmi['write_bytes'] ?? null);
		}

		if (array_key_exists('rx', $xgmi) || array_key_exists('rx_bytes', $xgmi) || array_key_exists('read_bytes', $xgmi)) {
			$out[ExtendedGpuTelemetry::KEY_NVLINK_RX_BYTES] = $this->unwrapThroughputBytes($xgmi['rx_bytes'] ?? $xgmi['rx'] ?? $xgmi['read_bytes'] ?? null);
		}

		if (array_key_exists('crc_errors', $xgmi)) {
			$out[ExtendedGpuTelemetry::KEY_NVLINK_CRC_ERRORS] = $this->unwrapNumber($xgmi['crc_errors']);
		}

		if (array_key_exists('replay_errors', $xgmi) || array_key_exists('error_count', $xgmi)) {
			$out[ExtendedGpuTelemetry::KEY_NVLINK_REPLAY_ERRORS] = $this->unwrapNumber($xgmi['replay_errors'] ?? $xgmi['error_count'] ?? null);
		}

		return $out;
	}

	/**
	 * Derive the EccMode value from ECC counters or the explicit enabled flag.
	 *
	 * @param array<array-key, mixed> $entry  decoded amd-smi metric object
	 * @param array<string, ?float>   $values extracted core metric values
	 *
	 * @return string
	 */
	private function extractEccMode(array $entry, array $values): string {
		if ($values['ecc_corr'] !== null || $values['ecc_uncorr'] !== null) {
			return EccMode::Enabled->value;
		}

		$ecc = $this->subArray($entry, 'ecc');

		if (isset($ecc['enabled']) && is_bool($ecc['enabled'])) {
			return $ecc['enabled'] ? EccMode::Enabled->value : EccMode::Disabled->value;
		}

		return EccMode::Unknown->value;
	}

	/**
	 * Build a normalized AMD partition attribute string from list and metric entries.
	 *
	 * @param array<array-key, mixed> $matched list entry for the GPU
	 * @param array<array-key, mixed> $entry   metric entry for the GPU
	 *
	 * @return string
	 */
	private function partitionString(array $matched, array $entry): string {
		$parts       = [];
		$partitionId = $this->stringField($matched, ['partition_id']);

		if ($partitionId !== '' && strtoupper($partitionId) !== 'N/A') {
			$parts[] = 'partition=' . $partitionId;
		}

		$compute = $this->stringField($entry, ['compute_partition']);
		$memory  = $this->stringField($entry, ['memory_partition']);

		if (isset($entry['partition']) && is_array($entry['partition'])) {
			$compute = $compute !== '' ? $compute : $this->stringField($entry['partition'], ['compute_partition', 'compute']);
			$memory  = $memory !== '' ? $memory : $this->stringField($entry['partition'], ['memory_partition', 'memory']);
		}

		if ($compute !== '' && strtoupper($compute) !== 'N/A') {
			$parts[] = 'compute=' . $compute;
		}

		if ($memory !== '' && strtoupper($memory) !== 'N/A') {
			$parts[] = 'memory=' . $memory;
		}

		return implode(',', $parts);
	}

	/**
	 * Extract a clock frequency normalized to MHz.
	 *
	 * @param array<array-key, mixed> $clock clock sub-object
	 * @param list<string>            $keys  candidate clock field names in priority order
	 *
	 * @return ?float
	 */
	private function extractClockMhz(array $clock, array $keys): ?float {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $clock)) {
				continue;
			}

			$raw = $clock[$key];

			if (is_array($raw) && array_key_exists('clk', $raw)) {
				$raw = $raw['clk'];
			}

			$num = $this->unwrapNumber($raw);

			if ($num !== null) {
				$unit = is_array($raw) && isset($raw['unit']) && is_string($raw['unit']) ? strtolower(trim($raw['unit'])) : 'mhz';

				return match ($unit) {
					'hz'    => $num * 1.0e-6,
					'khz'   => $num * 1.0e-3,
					'ghz'   => $num * 1000.0,
					default => $num,
				};
			}
		}

		return null;
	}

	/**
	 * Extract a boolean or numeric violation status flag as 0.0, 1.0, or null.
	 *
	 * @param array<array-key, mixed> $throttle throttle sub-object
	 * @param list<string>            $keys     candidate violation status keys
	 *
	 * @return ?float
	 */
	private function extractViolationFlag(array $throttle, array $keys): ?float {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $throttle)) {
				continue;
			}

			$raw = $throttle[$key];

			if (is_array($raw) && array_key_exists('value', $raw)) {
				$raw = $raw['value'];
			}

			if (is_bool($raw)) {
				return $raw ? 1.0 : 0.0;
			}

			if (is_int($raw) || is_float($raw)) {
				if (str_ends_with($key, '_accumulated')) {
					return null;
				}

				return $raw > 0 ? 1.0 : 0.0;
			}

			if (is_string($raw)) {
				$lower = strtolower(trim($raw));

				if ($lower === 'n/a' || $lower === '') {
					return null;
				}

				if ($lower === 'true' || $lower === 'yes' || $lower === 'active' || $lower === '1') {
					return 1.0;
				}

				if ($lower === 'false' || $lower === 'no' || $lower === 'inactive' || $lower === '0') {
					return 0.0;
				}
			}
		}

		return null;
	}

	/**
	 * Combine two nullable indicator flags using max semantics.
	 *
	 * @param ?float $a first indicator
	 * @param ?float $b second indicator
	 *
	 * @return ?float
	 */
	private function maxFlag(?float $a, ?float $b): ?float {
		if ($a === null) {
			return $b;
		}

		if ($b === null) {
			return $a;
		}

		return max($a, $b);
	}

	/**
	 * Unwrap amd-smi's {"value": ..., "unit": ...} wrapper or bare scalar, returning null for "N/A".
	 *
	 * @param mixed $raw wrapped or scalar JSON value
	 *
	 * @return ?float
	 */
	private function unwrapNumber(mixed $raw): ?float {
		if (is_array($raw)) {
			if (!array_key_exists('value', $raw)) {
				return null;
			}

			$raw = $raw['value'];
		}

		if (is_int($raw) || is_float($raw)) {
			$val = (float) $raw;

			return is_finite($val) ? $val : null;
		}

		if (is_string($raw)) {
			$trimmed = trim($raw);

			if ($trimmed === '' || strcasecmp($trimmed, 'N/A') === 0) {
				return null;
			}

			if (is_numeric($trimmed)) {
				$val = (float) $trimmed;

				return is_finite($val) ? $val : null;
			}
		}

		return null;
	}

	/**
	 * Unwrap a VRAM measurement into bytes, defaulting to MiB per amd-smi conventions.
	 *
	 * @param mixed $raw wrapped or bare numeric VRAM value
	 *
	 * @return ?float
	 */
	private function unwrapBytes(mixed $raw): ?float {
		$value = $this->unwrapNumber($raw);

		if ($value === null) {
			return null;
		}

		$unit = 'mib';

		if (is_array($raw) && isset($raw['unit']) && is_string($raw['unit'])) {
			$unit = strtolower(trim($raw['unit']));
		}

		return match ($unit) {
			'b', 'byte', 'bytes' => $value,
			'kb', 'kib'          => $value * 1024.0,
			'mb', 'mib'          => $value * MetricModel::MIB,
			'gb', 'gib'          => $value * MetricModel::MIB * 1024.0,
			default              => $value * MetricModel::MIB,
		};
	}

	/**
	 * Unwrap an interconnect throughput or byte counter into bytes.
	 *
	 * @param mixed $raw wrapped or bare numeric interconnect value
	 *
	 * @return ?float
	 */
	private function unwrapThroughputBytes(mixed $raw): ?float {
		$value = $this->unwrapNumber($raw);

		if ($value === null) {
			return null;
		}

		$unit = 'b';

		if (is_array($raw) && isset($raw['unit']) && is_string($raw['unit'])) {
			$unit = strtolower(trim($raw['unit']));
		}

		return match ($unit) {
			'kb', 'kib', 'kb/s', 'kib/s' => $value * 1024.0,
			'mb', 'mib', 'mb/s', 'mib/s' => $value * MetricModel::MIB,
			'gb', 'gib', 'gb/s', 'gib/s' => $value * MetricModel::MIB * 1024.0,
			default                      => $value,
		};
	}

	/**
	 * Return a nested array sub-object directly without cloning its elements.
	 *
	 * @param array<array-key, mixed> $entry parent entry
	 * @param string                  $key   child key
	 *
	 * @return array<array-key, mixed>
	 */
	private function subArray(array $entry, string $key): array {
		return isset($entry[$key]) && is_array($entry[$key]) ? $entry[$key] : [];
	}

	/**
	 * Read the first non-empty scalar string matching any candidate key.
	 *
	 * @param array<array-key, mixed> $entry source map
	 * @param list<string>            $keys  candidate keys in priority order
	 *
	 * @return string
	 */
	private function stringField(array $entry, array $keys): string {
		foreach ($keys as $key) {
			if (isset($entry[$key]) && is_scalar($entry[$key])) {
				$val = trim((string) $entry[$key]);

				if ($val !== '') {
					return $val;
				}
			}
		}

		return '';
	}

	/**
	 * Read the first non-negative integer matching any candidate key.
	 *
	 * @param array<array-key, mixed> $entry source map
	 * @param list<string>            $keys  candidate keys in priority order
	 *
	 * @return ?int
	 */
	private function intField(array $entry, array $keys): ?int {
		foreach ($keys as $key) {
			if (isset($entry[$key]) && (is_int($entry[$key]) || (is_string($entry[$key]) && ctype_digit($entry[$key])))) {
				return (int) $entry[$key];
			}
		}

		return null;
	}
}
