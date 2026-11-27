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
 * and 4.9+ (_RATIO, _BYTES, _WATTS, _CELSIUS, _CLOCKS_EVENT_REASONS) series,
 * as well as extended enterprise telemetry (NVLink, PCIe, HBM temperature,
 * SM/Tensor Core occupancy, XID errors, and fan speed) stored in
 * Reading::$vendorExtensions.
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
		'DCGM_FI_DEV_UUID'                               => 54,
		'DCGM_FI_DEV_GPU_UUID'                           => 54,
		'DCGM_FI_DEV_PCI_BUSID'                          => 57,
		'DCGM_FI_DEV_PCI_BUS_ID'                         => 57,
		'DCGM_FI_DEV_MIG_MODE'                           => 67,
		'DCGM_FI_DEV_SM_CLOCK'                           => 100,
		'DCGM_FI_DEV_SM_CLOCK_HERTZ'                     => 1603,
		'DCGM_FI_DEV_MEM_CLOCK'                          => 101,
		'DCGM_FI_DEV_MEMORY_CLOCK_HERTZ'                 => 1604,
		'DCGM_FI_DEV_CLOCK_THROTTLE_REASONS'             => 112,
		'DCGM_FI_DEV_CLOCKS_EVENT_REASONS'               => 112,
		'DCGM_FI_DEV_MEMORY_TEMP'                        => 140,
		'DCGM_FI_DEV_MEMORY_TEMP_CELSIUS'                => 140,
		'DCGM_FI_DEV_GPU_TEMP'                           => 150,
		'DCGM_FI_DEV_GPU_TEMP_CELSIUS'                   => 150,
		'DCGM_FI_DEV_POWER_USAGE'                        => 155,
		'DCGM_FI_DEV_BOARD_POWER_WATTS'                  => 155,
		'DCGM_FI_DEV_POWER_MGMT_LIMIT'                   => 160,
		'DCGM_FI_DEV_BOARD_POWER_LIMIT_REQUESTED_WATTS'  => 160,
		'DCGM_FI_DEV_ENFORCED_POWER_LIMIT'               => 164,
		'DCGM_FI_DEV_BOARD_POWER_LIMIT_ENFORCED_WATTS'   => 164,
		'DCGM_FI_DEV_FAN_SPEED'                          => 190,
		'DCGM_FI_DEV_PCIE_TX_THROUGHPUT'                 => 200,
		'DCGM_FI_DEV_PCIE_RX_THROUGHPUT'                 => 201,
		'DCGM_FI_DEV_PCIE_REPLAY_COUNTER'                => 202,
		'DCGM_FI_DEV_GPU_UTIL'                           => 203,
		'DCGM_FI_DEV_GPU_UTIL_RATIO'                     => 1613,
		'DCGM_FI_DEV_MEM_COPY_UTIL'                      => 204,
		'DCGM_FI_DEV_MEMORY_UTIL_RATIO'                  => 1614,
		'DCGM_FI_DEV_XID_ERRORS'                         => 230,
		'DCGM_FI_DEV_XID_ERROR'                          => 230,
		'DCGM_FI_DEV_FB_TOTAL'                           => 250,
		'DCGM_FI_DEV_FB_CAPACITY_BYTES'                  => 1625,
		'DCGM_FI_DEV_FB_FREE'                            => 251,
		'DCGM_FI_DEV_FB_FREE_BYTES'                      => 1626,
		'DCGM_FI_DEV_FB_USED'                            => 252,
		'DCGM_FI_DEV_FB_USED_BYTES'                      => 1627,
		'DCGM_FI_DEV_ECC_SBE_VOL_TOTAL'                  => 310,
		'DCGM_FI_DEV_ECC_DBE_VOL_TOTAL'                  => 311,
		'DCGM_FI_DEV_RETIRED_SBE'                        => 390,
		'DCGM_FI_DEV_RETIRED_DBE'                        => 391,
		'DCGM_FI_DEV_RETIRED_PENDING'                    => 392,
		'DCGM_FI_DEV_PAGE_RETIRED_PENDING'               => 392,
		'DCGM_FI_DEV_NVLINK_CRC_FLIT_ERROR_COUNT_TOTAL'  => 409,
		'DCGM_FI_DEV_NVLINK_CRC_DATA_ERROR_COUNT_TOTAL'  => 419,
		'DCGM_FI_DEV_NVLINK_REPLAY_ERROR_COUNT_TOTAL'    => 429,
		'DCGM_FI_DEV_NVLINK_RECOVERY_ERROR_COUNT_TOTAL'  => 439,
		'DCGM_FI_DEV_NVLINK_BANDWIDTH_TOTAL'             => 449,
		'DCGM_FI_PROF_SM_ACTIVE'                         => 1002,
		'DCGM_FI_PROF_SM_OCCUPANCY'                      => 1003,
		'DCGM_FI_PROF_PIPE_TENSOR_ACTIVE'                => 1004,
		'DCGM_FI_PROF_PCIE_TX_BYTES'                     => 1009,
		'DCGM_FI_PROF_PCIE_RX_BYTES'                     => 1010,
		'DCGM_FI_PROF_NVLINK_TX_BYTES'                   => 1011,
		'DCGM_FI_PROF_NVLINK_RX_BYTES'                   => 1012,
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

	private const EXTENDED_METRIC_MAP = [
		'DCGM_FI_DEV_MEMORY_TEMP'                       => ['field' => ExtendedGpuTelemetry::KEY_TEMP_MEMORY, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_DEV_MEMORY_TEMP_CELSIUS'               => ['field' => ExtendedGpuTelemetry::KEY_TEMP_MEMORY, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_DEV_FAN_SPEED'                         => ['field' => ExtendedGpuTelemetry::KEY_FAN_SPEED_PCT, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_DEV_PCIE_TX_THROUGHPUT'                => ['field' => ExtendedGpuTelemetry::KEY_PCIE_TX_BYTES, 'scale' => 1024.0, 'aggregate' => false],
		'DCGM_FI_DEV_PCIE_RX_THROUGHPUT'                => ['field' => ExtendedGpuTelemetry::KEY_PCIE_RX_BYTES, 'scale' => 1024.0, 'aggregate' => false],
		'DCGM_FI_PROF_PCIE_TX_BYTES'                    => ['field' => ExtendedGpuTelemetry::KEY_PCIE_TX_BYTES, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_PROF_PCIE_RX_BYTES'                    => ['field' => ExtendedGpuTelemetry::KEY_PCIE_RX_BYTES, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_DEV_PCIE_REPLAY_COUNTER'               => ['field' => ExtendedGpuTelemetry::KEY_PCIE_REPLAY_ERRORS, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_DEV_XID_ERRORS'                        => ['field' => ExtendedGpuTelemetry::KEY_LAST_XID, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_DEV_XID_ERROR'                         => ['field' => ExtendedGpuTelemetry::KEY_LAST_XID, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_DEV_NVLINK_CRC_FLIT_ERROR_COUNT_TOTAL' => ['field' => ExtendedGpuTelemetry::KEY_NVLINK_CRC_ERRORS, 'scale' => 1.0, 'aggregate' => true],
		'DCGM_FI_DEV_NVLINK_CRC_DATA_ERROR_COUNT_TOTAL' => ['field' => ExtendedGpuTelemetry::KEY_NVLINK_CRC_ERRORS, 'scale' => 1.0, 'aggregate' => true],
		'DCGM_FI_DEV_NVLINK_REPLAY_ERROR_COUNT_TOTAL'   => ['field' => ExtendedGpuTelemetry::KEY_NVLINK_REPLAY_ERRORS, 'scale' => 1.0, 'aggregate' => true],
		'DCGM_FI_DEV_NVLINK_RECOVERY_ERROR_COUNT_TOTAL' => ['field' => ExtendedGpuTelemetry::KEY_NVLINK_REPLAY_ERRORS, 'scale' => 1.0, 'aggregate' => true],
		'DCGM_FI_DEV_NVLINK_BANDWIDTH_TOTAL'            => ['field' => ExtendedGpuTelemetry::KEY_NVLINK_BANDWIDTH, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_PROF_NVLINK_TX_BYTES'                  => ['field' => ExtendedGpuTelemetry::KEY_NVLINK_TX_BYTES, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_PROF_NVLINK_RX_BYTES'                  => ['field' => ExtendedGpuTelemetry::KEY_NVLINK_RX_BYTES, 'scale' => 1.0, 'aggregate' => false],
		'DCGM_FI_PROF_SM_ACTIVE'                        => ['field' => ExtendedGpuTelemetry::KEY_SM_ACTIVE, 'scale' => 100.0, 'aggregate' => false],
		'DCGM_FI_PROF_SM_OCCUPANCY'                     => ['field' => ExtendedGpuTelemetry::KEY_SM_OCCUPANCY, 'scale' => 100.0, 'aggregate' => false],
		'DCGM_FI_PROF_PIPE_TENSOR_ACTIVE'               => ['field' => ExtendedGpuTelemetry::KEY_TENSOR_ACTIVE, 'scale' => 100.0, 'aggregate' => false],
	];

	private const XID_COUNT_METRICS = [
		'DCGM_EXP_XID_ERRORS_COUNT' => true,
		'DCGM_EXP_XID_ERRORS_TOTAL' => true,
	];

	private const THROTTLE_METRICS = [
		'DCGM_FI_DEV_CLOCK_THROTTLE_REASONS' => true,
		'DCGM_FI_DEV_CLOCKS_EVENT_REASONS'   => true,
	];

	private const MAX_LINES = 50000;

	private const METRIC_NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_:';
	private const LABEL_NAME_CHARS  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

	/**
	 * Parse Prometheus text exposition from dcgm-exporter.
	 *
	 * Scans the payload line by line without duplicating the full response body
	 * and caches parsed label sets so repeated series for the same GPU avoid
	 * redundant label decoding.
	 *
	 * @param string $body              raw Prometheus text exposition body
	 * @param int    $fallbackTimestamp Unix seconds to stamp samples that omit an explicit
	 *                                  Prometheus millisecond timestamp (0 keeps age unknown)
	 * @param string $toolVersion       optional exporter/driver version override
	 *
	 * @return Collection
	 *
	 * @throws CollectionError
	 */
	public function parse(string $body, int $fallbackTimestamp = 0, string $toolVersion = ''): Collection {
		/** @var array<string, array{uuid: string, parent_uuid: string, index: ?int, pci_bus_id: string, name: string, driver: string, mig_mode: string, mig_instance_id: string, mig_profile: string, values: array<string, ?float>, extensions: array<string, ?float>, mem_free: ?float, mask: ?int, observed_at: int, seen_metrics: array<string, true>}> $entities */
		$entities       = [];
		$parentsWithMig = [];

		/** @var array<string, array{entity_key: string, uuid: string, parent_uuid: string, is_mig: bool, index: ?int, pci_bus_id: string, name: string, driver: string, mig_mode: string, mig_instance_id: string, mig_profile: string, xid_label: string}> $labelCache */
		$labelCache = [];

		/** @var array<string, true> $validatedRawLabels */
		$validatedRawLabels = [];

		$bodyLen    = strlen($body);
		$offset     = 0;
		$lineNumber = 0;

		while ($offset <= $bodyLen) {
			$newline = strpos($body, "\n", $offset);

			if ($newline === false) {
				$rawLine = substr($body, $offset);
				$offset  = $bodyLen + 1;
			} else {
				$rawLine = substr($body, $offset, $newline - $offset);
				$offset  = $newline + 1;
			}

			$lineNumber++;

			if ($lineNumber > self::MAX_LINES) {
				throw new CollectionError(ExecutionState::Truncated, 'DCGM exporter output exceeded line limit');
			}

			$line = trim($rawLine);

			if ($line === '' || $line[0] === '#') {
				continue;
			}

			$parsed = $this->parseSampleLine($line, $lineNumber);
			$metric = $parsed['metric'];
			$rawLbl = $parsed['raw_labels'];

			$isCore     = isset(self::METRIC_MAP[$metric]);
			$isExtended = !$isCore && isset(self::EXTENDED_METRIC_MAP[$metric]);
			$isThrottle = !$isCore && !$isExtended && isset(self::THROTTLE_METRICS[$metric]);
			$isXidCount = !$isCore && !$isExtended && !$isThrottle && isset(self::XID_COUNT_METRICS[$metric]);
			$isMigMode  = !$isCore && !$isExtended && !$isThrottle && !$isXidCount && $metric === 'DCGM_FI_DEV_MIG_MODE';

			if (!$isCore && !$isExtended && !$isThrottle && !$isXidCount && !$isMigMode) {
				if ($rawLbl !== '' && !isset($validatedRawLabels[$rawLbl])) {
					$this->parseLabels($rawLbl, $lineNumber);
					$validatedRawLabels[$rawLbl] = true;
				}

				continue;
			}

			if (isset($labelCache[$rawLbl])) {
				$meta = $labelCache[$rawLbl];
			} else {
				$labels = $rawLbl !== '' ? $this->parseLabels($rawLbl, $lineNumber) : [];
				$validatedRawLabels[$rawLbl] = true;
				$meta = $this->resolveEntityMeta($labels, $lineNumber);
				$labelCache[$rawLbl] = $meta;
			}

			$entityKey = $meta['entity_key'];

			if ($meta['is_mig'] && $meta['parent_uuid'] !== '') {
				$parentsWithMig[strtolower($meta['parent_uuid'])] = true;
			}

			if (!isset($entities[$entityKey])) {
				$entities[$entityKey] = [
					'uuid'            => $meta['uuid'],
					'parent_uuid'     => $meta['parent_uuid'],
					'index'           => $meta['index'],
					'pci_bus_id'      => $meta['pci_bus_id'],
					'name'            => $meta['name'],
					'driver'          => $meta['driver'],
					'mig_mode'        => $meta['mig_mode'],
					'mig_instance_id' => $meta['mig_instance_id'],
					'mig_profile'     => $meta['mig_profile'],
					'values'          => [],
					'extensions'      => [],
					'mem_free'        => null,
					'mask'            => null,
					'observed_at'     => 0,
					'seen_metrics'    => [],
				];
			}

			$dedupKey = ($isXidCount && $meta['xid_label'] !== '') ? $metric . "\0" . $meta['xid_label'] : $metric;

			if (isset($entities[$entityKey]['seen_metrics'][$dedupKey])) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d duplicates series %s for one GPU', $lineNumber, $metric), 1);
			}

			$entities[$entityKey]['seen_metrics'][$dedupKey] = true;

			if ($parsed['timestamp'] > 0 && $parsed['timestamp'] > $entities[$entityKey]['observed_at']) {
				$entities[$entityKey]['observed_at'] = $parsed['timestamp'];
			}

			if ($isMigMode) {
				if ($parsed['value'] !== null) {
					$entities[$entityKey]['mig_mode'] = $parsed['value'] >= 1.0 ? 'enabled' : 'disabled';
				}

				continue;
			}

			if ($isThrottle) {
				if ($parsed['value'] !== null && $parsed['value'] >= 0.0 && $parsed['value'] <= 4503599627370495.0) {
					$entities[$entityKey]['mask'] = (int) round($parsed['value']);
				}

				continue;
			}

			if ($isXidCount) {
				if ($parsed['value'] !== null && $parsed['value'] >= 0.0) {
					$existing = $entities[$entityKey]['extensions'][ExtendedGpuTelemetry::KEY_XID_ERROR_COUNT] ?? 0.0;
					$entities[$entityKey]['extensions'][ExtendedGpuTelemetry::KEY_XID_ERROR_COUNT] = $existing + $parsed['value'];
				} elseif (!array_key_exists(ExtendedGpuTelemetry::KEY_XID_ERROR_COUNT, $entities[$entityKey]['extensions'])) {
					$entities[$entityKey]['extensions'][ExtendedGpuTelemetry::KEY_XID_ERROR_COUNT] = null;
				}

				continue;
			}

			if ($isExtended) {
				$extSpec = self::EXTENDED_METRIC_MAP[$metric];
				$scaled  = Metric::scale($parsed['value'], $extSpec['scale']);
				$extKey  = $extSpec['field'];

				if ($extSpec['aggregate']) {
					if ($scaled !== null) {
						$prev = $entities[$entityKey]['extensions'][$extKey] ?? 0.0;
						$entities[$entityKey]['extensions'][$extKey] = $prev + $scaled;
					} elseif (!array_key_exists($extKey, $entities[$entityKey]['extensions'])) {
						$entities[$entityKey]['extensions'][$extKey] = null;
					}
				} else {
					$entities[$entityKey]['extensions'][$extKey] = $scaled;
				}

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
			$readings[] = new Reading($report, $observedAt, $values, $entity['mask'], EccMode::Unknown->value, $entity['extensions']);
		}

		try {
			$snapshot = GpuSnapshot::create(self::BACKEND, $driver, $reports);
		} catch (\InvalidArgumentException $e) {
			throw new CollectionError(ExecutionState::InvalidOutput, $e->getMessage());
		}

		return new Collection($snapshot, $readings, self::BACKEND);
	}

	/**
	 * Resolve normalized entity identity metadata from a parsed label map.
	 *
	 * @param array<string, string> $labels     parsed Prometheus label map
	 * @param int                   $lineNumber 1-based line number for error reporting
	 *
	 * @return array{entity_key: string, uuid: string, parent_uuid: string, is_mig: bool, index: ?int, pci_bus_id: string, name: string, driver: string, mig_mode: string, mig_instance_id: string, mig_profile: string, xid_label: string}
	 *
	 * @throws CollectionError
	 */
	private function resolveEntityMeta(array $labels, int $lineNumber): array {
		$uuid = trim($labels['UUID'] ?? $labels['uuid'] ?? '');

		if ($uuid === '') {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is missing the UUID label', $lineNumber), 1);
		}

		$migInstanceId = trim($labels['GPU_I_ID'] ?? '');
		$migProfile    = trim($labels['GPU_I_PROFILE'] ?? '');
		$migComputeId  = trim($labels['GPU_CI_ID'] ?? '0');

		$isMigPrefix = stripos($uuid, 'MIG-') === 0;
		$isMig       = $migInstanceId !== '' || $isMigPrefix;

		if ($isMig) {
			$parentUuid = $isMigPrefix ? $this->extractParentUuid($uuid) : $uuid;
			$migUuid    = $isMigPrefix
				? $uuid
				: sprintf('MIG-%s/%s/%s', $parentUuid, $migInstanceId, $migComputeId !== '' ? $migComputeId : '0');
			$entityKey  = 'mig:' . strtolower($migUuid);
		} else {
			$parentUuid = '';
			$migUuid    = '';
			$entityKey  = 'gpu:' . strtolower($uuid);
		}

		$busId = trim($labels['pci_bus_id'] ?? $labels['PCI_BUS_ID'] ?? '');

		if ($busId === '') {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is missing the pci_bus_id label', $lineNumber), 1);
		}

		$gpuLabel = trim($labels['gpu'] ?? '');

		return [
			'entity_key'      => $entityKey,
			'uuid'            => $isMig ? $migUuid : $uuid,
			'parent_uuid'     => $parentUuid,
			'is_mig'          => $isMig,
			'index'           => $gpuLabel !== '' && ctype_digit($gpuLabel) ? (int) $gpuLabel : null,
			'pci_bus_id'      => $busId,
			'name'            => trim($labels['modelName'] ?? ''),
			'driver'          => trim($labels['driver_version'] ?? $labels['DCGM_FI_DRIVER_VERSION'] ?? ''),
			'mig_mode'        => $isMig ? 'enabled' : '',
			'mig_instance_id' => $migInstanceId !== '' ? $migInstanceId : ($isMig ? $this->extractMigInstanceId($migUuid) : ''),
			'mig_profile'     => $migProfile,
			'xid_label'       => trim($labels['xid'] ?? ''),
		];
	}

	/**
	 * Parse a single Prometheus text exposition sample line using O(L) string
	 * scanning rather than backtracking regex matching.
	 *
	 * @param string $line       trimmed non-comment line
	 * @param int    $lineNumber 1-based line number for error reporting
	 *
	 * @return array{metric: string, raw_labels: string, value: ?float, timestamp: int}
	 *
	 * @throws CollectionError
	 */
	private function parseSampleLine(string $line, int $lineNumber): array {
		$len       = strlen($line);
		$metricLen = strspn($line, self::METRIC_NAME_CHARS);

		if ($metricLen === 0 || ($line[0] >= '0' && $line[0] <= '9')) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is not a valid Prometheus sample', $lineNumber), 1);
		}

		$metric    = substr($line, 0, $metricLen);
		$pos       = $metricLen;
		$rawLabels = '';

		if ($pos < $len && $line[$pos] === '{') {
			$close = strpos($line, '}', $pos + 1);

			if ($close === false) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is not a valid Prometheus sample', $lineNumber), 1);
			}

			$rawLabels = substr($line, $pos + 1, $close - $pos - 1);
			$pos       = $close + 1;
		}

		$ws = strspn($line, " \t", $pos);

		if ($ws === 0 || $pos + $ws >= $len) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is not a valid Prometheus sample', $lineNumber), 1);
		}

		$pos    += $ws;
		$valLen  = strcspn($line, " \t", $pos);
		$rawVal  = substr($line, $pos, $valLen);
		$pos    += $valLen;
		$ts      = 0;

		if ($pos < $len) {
			$ws2 = strspn($line, " \t", $pos);
			$pos += $ws2;

			if ($pos < $len) {
				$rawTs = substr($line, $pos);
				$tsLen = strlen($rawTs);

				if ($tsLen < 10 || $tsLen > 16 || !ctype_digit($rawTs)) {
					throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d is not a valid Prometheus sample', $lineNumber), 1);
				}

				$intTs = (int) $rawTs;
				// Prometheus exposition timestamps are milliseconds since Unix epoch.
				$ts = $intTs > 100000000000 ? intdiv($intTs, 1000) : $intTs;
			}
		}

		$value = $this->parseValue($rawVal, $lineNumber);

		return ['metric' => $metric, 'raw_labels' => $rawLabels, 'value' => $value, 'timestamp' => $ts];
	}

	/**
	 * Parse a Prometheus label block using span-based slicing instead of
	 * per-character loops and per-label regex calls.
	 *
	 * @param string $raw        contents between `{` and `}`
	 * @param int    $lineNumber 1-based line number for error reporting
	 *
	 * @return array<string, string>
	 *
	 * @throws CollectionError
	 */
	private function parseLabels(string $raw, int $lineNumber): array {
		$labels = [];
		$length = strlen($raw);
		$pos    = 0;

		while ($pos < $length) {
			$pos += strspn($raw, " \t,", $pos);

			if ($pos >= $length) {
				break;
			}

			$nameLen = strspn($raw, self::LABEL_NAME_CHARS, $pos);

			if ($nameLen === 0 || ($raw[$pos] >= '0' && $raw[$pos] <= '9')) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has malformed metric labels', $lineNumber), 1);
			}

			$name = substr($raw, $pos, $nameLen);
			$pos += $nameLen;
			$pos += strspn($raw, " \t", $pos);

			if ($pos >= $length || $raw[$pos] !== '=') {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has malformed metric labels', $lineNumber), 1);
			}

			$pos++;
			$pos += strspn($raw, " \t", $pos);

			if ($pos >= $length || $raw[$pos] !== '"') {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has malformed metric labels', $lineNumber), 1);
			}

			$pos++;
			$chunkLen = strcspn($raw, '"\\', $pos);

			// Fast path: unescaped label values (common case) extract in one substr().
			if ($pos + $chunkLen < $length && $raw[$pos + $chunkLen] === '"') {
				$labels[$name] = substr($raw, $pos, $chunkLen);
				$pos          += $chunkLen + 1;

				continue;
			}

			$val    = substr($raw, $pos, $chunkLen);
			$pos   += $chunkLen;
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
				} elseif ($ch === '"') {
					$pos++;
					$closed = true;

					break;
				} else {
					$span  = strcspn($raw, '"\\', $pos);
					$val  .= substr($raw, $pos, $span);
					$pos  += $span;
				}
			}

			if (!$closed) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has an unterminated label value', $lineNumber), 1);
			}

			$labels[$name] = $val;
		}

		return $labels;
	}

	/**
	 * Parse a Prometheus numeric sample value, returning null for NaN or Inf.
	 *
	 * @param string $raw        unquoted numeric token
	 * @param int    $lineNumber 1-based line number for error reporting
	 *
	 * @return ?float
	 *
	 * @throws CollectionError
	 */
	private function parseValue(string $raw, int $lineNumber): ?float {
		if (is_numeric($raw)) {
			$num = (float) $raw;

			return is_finite($num) ? $num : null;
		}

		$lower = strtolower($raw);

		if ($lower === 'nan' || $lower === '+inf' || $lower === '-inf' || $lower === 'inf') {
			return null;
		}

		throw new CollectionError(ExecutionState::InvalidOutput, sprintf('DCGM line %d has a non-numeric value', $lineNumber), 1);
	}

	/**
	 * Extract the physical parent GPU UUID from a MIG-GPU-<uuid>/<gi>/<ci> string.
	 *
	 * @param string $migUuid MIG instance UUID
	 *
	 * @return string
	 */
	private function extractParentUuid(string $migUuid): string {
		if (preg_match('/^MIG-(GPU-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/[0-9]+\/[0-9]+$/i', $migUuid, $m) === 1) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Extract the numeric MIG GPU instance ID from a MIG-GPU-<uuid>/<gi>/<ci> string.
	 *
	 * @param string $migUuid MIG instance UUID
	 *
	 * @return string
	 */
	private function extractMigInstanceId(string $migUuid): string {
		if (preg_match('/\/([0-9]+)\/[0-9]+$/', $migUuid, $m) === 1) {
			return $m[1];
		}

		return '0';
	}
}
