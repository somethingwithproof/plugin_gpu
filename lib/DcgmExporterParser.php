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
 * Read-only Prometheus text-format parser for NVIDIA DCGM Exporter output
 * (REQ-DCGM-001 to REQ-DCGM-006, REQ-MIG-001 to REQ-MIG-005).
 *
 * Supports both legacy (3.3-4.8: DCGM_FI_DEV_GPU_UTIL, DCGM_FI_DEV_FB_USED,
 * DCGM_FI_DEV_POWER_USAGE, DCGM_FI_DEV_GPU_TEMP, DCGM_FI_DEV_CLOCK_THROTTLE_REASONS)
 * and 4.9+ (_RATIO, _BYTES, _WATTS, _CELSIUS, _CLOCKS_EVENT_REASONS) series.
 *
 * Security controls (docs/research/dcgm-exporter.md):
 * - Keys every GPU by UUID (never DCGM GPU ID).
 * - Rejects duplicate series for the same metric and entity.
 * - Treats NaN, Inf, blank, and unsupported values as unknown (null), never 0.
 * - Tracks MIG instances via GPU_I_ID / GPU_I_PROFILE without copying parent
 *   metrics onto instances.
 */
final class DcgmExporterParser {
	public const BACKEND = 'dcgm-exporter';

	/**
	 * Confirmed numeric DCGM field IDs (REQ-DCGM-001, docs/research/dcgm.md).
	 */
	public const FIELD_IDS = [
		'DCGM_FI_DEV_UUID'                            => 54,
		'DCGM_FI_DEV_GPU_UUID'                        => 54,
		'DCGM_FI_DEV_PCI_BUSID'                       => 57,
		'DCGM_FI_DEV_PCI_BUS_ID'                      => 57,
		'DCGM_FI_DEV_MIG_MODE'                        => 67,
		'DCGM_FI_DEV_SM_CLOCK'                        => 100,
		'DCGM_FI_DEV_SM_CLOCK_HERTZ'                  => 1603,
		'DCGM_FI_DEV_MEM_CLOCK'                       => 101,
		'DCGM_FI_DEV_MEMORY_CLOCK_HERTZ'              => 1604,
		'DCGM_FI_DEV_CLOCK_THROTTLE_REASONS'          => 112,
		'DCGM_FI_DEV_CLOCKS_EVENT_REASONS'            => 112,
		'DCGM_FI_DEV_GPU_TEMP'                        => 150,
		'DCGM_FI_DEV_GPU_TEMP_CELSIUS'                => 150,
		'DCGM_FI_DEV_POWER_USAGE'                     => 155,
		'DCGM_FI_DEV_BOARD_POWER_WATTS'               => 155,
		'DCGM_FI_DEV_POWER_MGMT_LIMIT'                => 160,
		'DCGM_FI_DEV_BOARD_POWER_LIMIT_REQUESTED_WATTS' => 160,
		'DCGM_FI_DEV_ENFORCED_POWER_LIMIT'            => 164,
		'DCGM_FI_DEV_BOARD_POWER_LIMIT_ENFORCED_WATTS' => 164,
		'DCGM_FI_DEV_GPU_UTIL'                        => 203,
		'DCGM_FI_DEV_GPU_UTIL_RATIO'                  => 1613,
		'DCGM_FI_DEV_MEM_COPY_UTIL'                   => 204,
		'DCGM_FI_DEV_MEMORY_UTIL_RATIO'               => 1614,
		'DCGM_FI_DEV_FB_TOTAL'                        => 250,
		'DCGM_FI_DEV_FB_CAPACITY_BYTES'               => 1625,
		'DCGM_FI_DEV_FB_FREE'                         => 251,
		'DCGM_FI_DEV_FB_FREE_BYTES'                   => 1626,
		'DCGM_FI_DEV_FB_USED'                         => 252,
		'DCGM_FI_DEV_FB_USED_BYTES'                   => 1627,
		'DCGM_FI_DEV_ECC_SBE_VOL_TOTAL'               => 310,
		'DCGM_FI_DEV_ECC_DBE_VOL_TOTAL'               => 311,
		'DCGM_FI_DEV_RETIRED_SBE'                     => 390,
		'DCGM_FI_DEV_RETIRED_DBE'                     => 391,
		'DCGM_FI_DEV_RETIRED_PENDING'                 => 392,
		'DCGM_FI_DEV_PAGE_RETIRED_PENDING'            => 392,
	];

	private const METRIC_MAP = [
		'DCGM_FI_DEV_GPU_UTIL'                          => ['field' => 'util_gpu', 'scale' => 1.0],
		'DCGM_FI_DEV_GPU_UTIL_RATIO'                    => ['field' => 'util_gpu', 'scale' => 100.0],
		'DCGM_FI_DEV_MEM_COPY_UTIL'                     => ['field' => 'util_mem', 'scale' => 1.0],
		'DCGM_FI_DEV_MEMORY_UTIL_RATIO'                 => ['field' => 'util_mem', 'scale' => 100.0],
		'DCGM_FI_DEV_FB_USED'                           => ['field' => 'mem_used', 'scale' => MetricModel::MIB],
		'DCGM_FI_DEV_FB_USED_BYTES'                     => ['field' => 'mem_used', 'scale' => 1.0],
		'DCGM_FI_DEV_FB_FREE'                           => ['field' => '_mem_free', 'scale' => MetricModel::MIB],
		'DCGM_FI_DEV_FB_FREE_BYTES'                     => ['field' => '_mem_free', 'scale' => 1.0],
		'DCGM_FI_DEV_FB_TOTAL'                          => ['field' => 'mem_total', 'scale' => MetricModel::MIB],
		'DCGM_FI_DEV_FB_CAPACITY_BYTES'                 => ['field' => 'mem_total', 'scale' => 1.0],
		'DCGM_FI_DEV_GPU_TEMP'                          => ['field' => 'temp_gpu', 'scale' => 1.0],
		'DCGM_FI_DEV_GPU_TEMP_CELSIUS'                  => ['field' => 'temp_gpu', 'scale' => 1.0],
		'DCGM_FI_DEV_POWER_USAGE'                       => ['field' => 'power', 'scale' => 1.0],
		'DCGM_FI_DEV_BOARD_POWER_WATTS'                 => ['field' => 'power', 'scale' => 1.0],
		'DCGM_FI_DEV_POWER_MGMT_LIMIT'                  => ['field' => 'power_limit', 'scale' => 1.0],
		'DCGM_FI_DEV_BOARD_POWER_LIMIT_REQUESTED_WATTS' => ['field' => 'power_limit', 'scale' => 1.0],
		'DCGM_FI_DEV_ENFORCED_POWER_LIMIT'              => ['field' => 'power_limit', 'scale' => 1.0],
		'DCGM_FI_DEV_BOARD_POWER_LIMIT_ENFORCED_WATTS'  => ['field' => 'power_limit', 'scale' => 1.0],
		'DCGM_FI_DEV_SM_CLOCK'                          => ['field' => 'clock_sm', 'scale' => 1.0],
		'DCGM_FI_DEV_SM_CLOCK_HERTZ'                    => ['field' => 'clock_sm', 'scale' => 1.0e-6],
		'DCGM_FI_DEV_MEM_CLOCK'                         => ['field' => 'clock_mem', 'scale' => 1.0],
		'DCGM_FI_DEV_MEMORY_CLOCK_HERTZ'                => ['field' => 'clock_mem', 'scale' => 1.0e-6],
		'DCGM_FI_DEV_ECC_SBE_VOL_TOTAL'                 => ['field' => 'ecc_corr', 'scale' => 1.0],
		'DCGM_FI_DEV_ECC_DBE_VOL_TOTAL'                 => ['field' => 'ecc_uncorr', 'scale' => 1.0],
		'DCGM_FI_DEV_RETIRED_SBE'                       => ['field' => 'retired_sbe', 'scale' => 1.0],
		'DCGM_FI_DEV_RETIRED_DBE'                       => ['field' => 'retired_dbe', 'scale' => 1.0],
		'DCGM_FI_DEV_RETIRED_PENDING'                   => ['field' => 'retired_pending', 'scale' => 1.0],
		'DCGM_FI_DEV_PAGE_RETIRED_PENDING'              => ['field' => 'retired_pending', 'scale' => 1.0],
	];

	private const THROTTLE_METRICS = [
		'DCGM_FI_DEV_CLOCK_THROTTLE_REASONS' => true,
		'DCGM_FI_DEV_CLOCKS_EVENT_REASONS'   => true,
	];

	private const MAX_LINES = 50000;

	/**
	 * Parse Prometheus text exposition from dcgm-exporter.
	 *
	 * @param int $fallbackTimestamp Unix seconds to stamp samples that omit an explicit
	 *                               Prometheus millisecond timestamp (0 keeps age unknown)
	 *
	 * @throws CollectionError
	 */
	public function parse(string $body, int $fallbackTimestamp = 0, string $toolVersion = ''): Collection {
		$lines = explode("\n", str_replace("\r\n", "\n", $body));

		if (count($lines) > self::MAX_LINES) {
			throw new CollectionError(ExecutionState::Truncated, 'DCGM exporter output exceeded line limit');
		}

		/** @var array<string, array{uuid: string, parent_uuid: string, index: ?int, pci_bus_id: string, name: string, driver: string, mig_mode: string, mig_instance_id: string, mig_profile: string, values: array<string, ?float>, mem_free: ?float, mask: ?int, observed_at: int, seen_metrics: array<string, true>}> $entities */
		$entities      = [];
		$parentsWithMig = [];

		foreach ($lines as $number => $rawLine) {
			$line = trim($rawLine);

			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$parsed = $this->parseSampleLine($line, $number + 1);
			$metric = $parsed['metric'];

			if (!isset(self::METRIC_MAP[$metric]) && !isset(self::THROTTLE_METRICS[$metric]) && $metric !== 'DCGM_FI_DEV_MIG_MODE') {
				continue;
			}

			$labels = $parsed['labels'];
			$uuid   = trim($labels['UUID'] ?? $labels['uuid'] ?? '');

			if ($uuid === '') {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is missing the UUID label', $number + 1), 1);
			}

			$migInstanceId = trim($labels['GPU_I_ID'] ?? '');
			$migProfile    = trim($labels['GPU_I_PROFILE'] ?? '');
			$migComputeId  = trim($labels['GPU_CI_ID'] ?? '0');

			$isMig = $migInstanceId !== '' || stripos($uuid, 'MIG-') === 0;

			if ($isMig) {
				$parentUuid = stripos($uuid, 'MIG-') === 0 ? $this->extractParentUuid($uuid) : $uuid;
				$migUuid    = stripos($uuid, 'MIG-') === 0
					? $uuid
					: sprintf('MIG-%s/%s/%s', $parentUuid, $migInstanceId, $migComputeId !== '' ? $migComputeId : '0');
				$entityKey  = 'mig:' . strtolower($migUuid);

				if ($parentUuid !== '') {
					$parentsWithMig[strtolower($parentUuid)] = true;
				}
			} else {
				$parentUuid = '';
				$migUuid    = '';
				$entityKey  = 'gpu:' . strtolower($uuid);
			}

			$busId = trim($labels['pci_bus_id'] ?? $labels['PCI_BUS_ID'] ?? '');

			if ($busId === '') {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is missing the pci_bus_id label', $number + 1), 1);
			}

			if (!isset($entities[$entityKey])) {
				$gpuLabel = trim($labels['gpu'] ?? '');
				$entities[$entityKey] = [
					'uuid'            => $isMig ? $migUuid : $uuid,
					'parent_uuid'     => $parentUuid,
					'index'           => preg_match('/^[0-9]+$/', $gpuLabel) === 1 ? (int) $gpuLabel : null,
					'pci_bus_id'      => $busId,
					'name'            => trim($labels['modelName'] ?? ''),
					'driver'          => trim($labels['driver_version'] ?? $labels['DCGM_FI_DRIVER_VERSION'] ?? ''),
					'mig_mode'        => $isMig ? 'enabled' : '',
					'mig_instance_id' => $migInstanceId !== '' ? $migInstanceId : ($isMig ? $this->extractMigInstanceId($migUuid) : ''),
					'mig_profile'     => $migProfile,
					'values'          => [],
					'mem_free'        => null,
					'mask'            => null,
					'observed_at'     => 0,
					'seen_metrics'    => [],
				];
			}

			if (isset($entities[$entityKey]['seen_metrics'][$metric])) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d duplicates series %s for one GPU', $number + 1, $metric), 1);
			}

			$entities[$entityKey]['seen_metrics'][$metric] = true;

			if ($parsed['timestamp'] > 0) {
				$entities[$entityKey]['observed_at'] = max($entities[$entityKey]['observed_at'], $parsed['timestamp']);
			}

			if ($metric === 'DCGM_FI_DEV_MIG_MODE') {
				if ($parsed['value'] !== null) {
					$entities[$entityKey]['mig_mode'] = $parsed['value'] >= 1.0 ? 'enabled' : 'disabled';
				}

				continue;
			}

			if (isset(self::THROTTLE_METRICS[$metric])) {
				if ($parsed['value'] !== null && $parsed['value'] >= 0.0 && $parsed['value'] <= 4503599627370495.0) {
					$entities[$entityKey]['mask'] = (int) round($parsed['value']);
				}

				continue;
			}

			if (!isset(self::METRIC_MAP[$metric])) {
				continue;
			}

			$spec   = self::METRIC_MAP[$metric];
			$scaled = Metric::scale($parsed['value'], $spec['scale']);

			if ($spec['field'] === '_mem_free') {
				$entities[$entityKey]['mem_free'] = $scaled;
			} elseif ($spec['field'] === 'retired_pending') {
				$entities[$entityKey]['values']['retired_pending'] = $scaled === null ? null : ($scaled >= 1.0 ? 1.0 : 0.0);
			} else {
				$entities[$entityKey]['values'][$spec['field']] = $scaled;
			}
		}

		$reports  = [];
		$readings = [];
		$driver   = $toolVersion;

		foreach ($entities as $entity) {
			if ($entity['parent_uuid'] === '' && isset($parentsWithMig[strtolower($entity['uuid'])])) {
				$entity['mig_mode'] = 'enabled';
			}

			try {
				$report = $entity['parent_uuid'] !== ''
					? GpuReport::createMig(
						$entity['uuid'],
						$entity['parent_uuid'],
						$entity['mig_instance_id'] !== '' ? $entity['mig_instance_id'] : '0',
						$entity['mig_profile'],
						$entity['pci_bus_id'],
						$entity['name'],
						$entity['driver'],
						$entity['index'],
					)
					: GpuReport::create(
						Vendor::Nvidia,
						$entity['uuid'],
						$entity['index'],
						$entity['pci_bus_id'],
						$entity['name'],
						$entity['driver'],
						$entity['mig_mode'],
					);
			} catch (\InvalidArgumentException $e) {
				throw new CollectionError(ExecutionState::InvalidOutput, 'DCGM entity rejected: ' . $e->getMessage(), 1);
			}

			$values = ThrottleMask::indicators($entity['mask']);

			foreach ($entity['values'] as $field => $value) {
				$values[$field] = $value;
			}

			// Compute mem_total from FB_USED + FB_FREE when FB_TOTAL/FB_CAPACITY was not exported.
			if (!isset($values['mem_total']) && isset($values['mem_used']) && $entity['mem_free'] !== null) {
				$values['mem_total'] = $values['mem_used'] + $entity['mem_free'];
			}

			$observedAt = $entity['observed_at'] > 0 ? $entity['observed_at'] : $fallbackTimestamp;

			if ($driver === '' && $report->driverVersion !== '') {
				$driver = $report->driverVersion;
			}

			$reports[]  = $report;
			$readings[] = new Reading($report, $observedAt, $values, $entity['mask'], EccMode::Unknown->value);
		}

		try {
			$snapshot = GpuSnapshot::create(self::BACKEND, $driver, $reports);
		} catch (\InvalidArgumentException $e) {
			throw new CollectionError(ExecutionState::InvalidOutput, $e->getMessage());
		}

		return new Collection($snapshot, $readings, self::BACKEND);
	}

	/**
	 * @return array{metric: string, labels: array<string, string>, value: ?float, timestamp: int}
	 *
	 * @throws CollectionError
	 */
	private function parseSampleLine(string $line, int $lineNumber): array {
		if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{([^}]*)\})?\s+(\S+)(?:\s+([0-9]{10,16}))?$/', $line, $m) !== 1) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is not a valid Prometheus sample', $lineNumber), 1);
		}

		$labels = $m[2] !== '' ? $this->parseLabels($m[2], $lineNumber) : [];
		$value  = $this->parseValue($m[3], $lineNumber);
		$ts     = 0;

		if (isset($m[4])) {
			$rawTs = (int) $m[4];
			// Prometheus exposition timestamps are milliseconds since Unix epoch.
			$ts = $rawTs > 100000000000 ? intdiv($rawTs, 1000) : $rawTs;
		}

		return ['metric' => $m[1], 'labels' => $labels, 'value' => $value, 'timestamp' => $ts];
	}

	/**
	 * @return array<string, string>
	 *
	 * @throws CollectionError
	 */
	private function parseLabels(string $raw, int $lineNumber): array {
		$labels = [];
		$length = strlen($raw);
		$pos    = 0;

		while ($pos < $length) {
			while ($pos < $length && ($raw[$pos] === ' ' || $raw[$pos] === "\t" || $raw[$pos] === ',')) {
				$pos++;
			}

			if ($pos >= $length) {
				break;
			}

			if (preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"/', $raw, $m, 0, $pos) !== 1) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has malformed metric labels', $lineNumber), 1);
			}

			$name = $m[1];
			$pos += strlen($m[0]);
			$val  = '';
			$closed = false;

			while ($pos < $length) {
				$ch = $raw[$pos];

				if ($ch === '\\' && $pos + 1 < $length) {
					$next = $raw[$pos + 1];
					$val .= match ($next) {
						'n'     => "\n",
						'\\'    => '\\',
						'"'     => '"',
						default => $next,
					};
					$pos += 2;

					continue;
				}

				if ($ch === '"') {
					$pos++;
					$closed = true;

					break;
				}

				$val .= $ch;
				$pos++;
			}

			if (!$closed) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has an unterminated label value', $lineNumber), 1);
			}

			$labels[$name] = $val;
		}

		return $labels;
	}

	/**
	 * @throws CollectionError
	 */
	private function parseValue(string $raw, int $lineNumber): ?float {
		$lower = strtolower($raw);

		if ($lower === 'nan' || $lower === '+inf' || $lower === '-inf' || $lower === 'inf') {
			return null;
		}

		if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/', $raw) !== 1) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has a non-numeric value', $lineNumber), 1);
		}

		$num = (float) $raw;

		return is_finite($num) ? $num : null;
	}

	private function extractParentUuid(string $migUuid): string {
		if (preg_match('/^MIG-(GPU-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/[0-9]+\/[0-9]+$/i', $migUuid, $m) === 1) {
			return $m[1];
		}

		return '';
	}

	private function extractMigInstanceId(string $migUuid): string {
		if (preg_match('/\/([0-9]+)\/[0-9]+$/', $migUuid, $m) === 1) {
			return $m[1];
		}

		return '0';
	}
}
