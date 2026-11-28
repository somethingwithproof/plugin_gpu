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
 * Multi-tenant GPU cluster utilization, locality fragmentation, and
 * fault-to-stall correlation analyzer.
 *
 * Grounded in Jeon et al., "Analysis of Large-Scale Multi-Tenant GPU Clusters
 * for DNN Training Workloads" (USENIX ATC 2019, Microsoft Philly cluster):
 * - Locality fragmentation: multi-GPU gang-scheduled training jobs require
 *   colocated GPUs on the same host (or NVLink island). Partial allocations
 *   across hosts strand free GPUs that cannot satisfy full-gang requests.
 * - Allocated-but-idle separation: training frameworks hold frame-buffer
 *   memory even while compute units sit idle during input-pipeline stalls,
 *   checkpointing, or blocked collective barriers.
 * - Fault-to-stall correlation: a single GPU fault (XID error, uncorrectable
 *   ECC, interconnect CRC/replay storm, or hardware thermal/power-brake
 *   throttling) stalls peer GPUs in the same gang-scheduled job.
 *
 * Runs in O(G + N) linear time across G GPUs and N nodes without database I/O.
 */
final class GpuClusterUtilizationAnalyzer {
	public const STATE_ACTIVE_COMPUTE    = 'active_compute';
	public const STATE_ALLOCATED_IDLE    = 'allocated_idle';
	public const STATE_UNALLOCATED_FREE  = 'unallocated_free';
	public const STATE_UNKNOWN_TELEMETRY = 'unknown_telemetry';

	public const DEFAULT_GANG_SIZE           = 8;
	public const DEFAULT_IDLE_UTIL_THRESHOLD = 5.0;
	public const DEFAULT_ALLOCATED_MEM_RATIO = 0.10;

	/**
	 * Analyze cluster-wide GPU/MIG utilization, locality fragmentation, and
	 * gang-scheduled fault correlations in a single O(G + N) pass.
	 *
	 * Each entry in $telemetry is keyed by GPU record id (GpuRecord::$id) and
	 * carries optional numeric core metrics (util_gpu, mem_used, mem_total,
	 * ecc_uncorr, ecc_uncorr_new, thr_hw_thermal, thr_hw_power_brake), an
	 * optional vendor_extensions map or ExtendedGpuTelemetry object, and an
	 * optional gang_id string to group multi-GPU jobs explicitly (defaulting
	 * to node-level gang scope when omitted).
	 *
	 * @param list<GpuRecord>                  $gpus                inventory records (present GPUs are analyzed; absent GPUs are counted separately)
	 * @param array<int, array<string, mixed>> $telemetry           gpu_id => latest sample metrics and optional vendor_extensions
	 * @param int                              $gangSize            target multi-GPU gang size for locality fragmentation (minimum 1)
	 * @param float                            $idleUtilThreshold   GPU/SM utilization percent below which an allocated GPU is classified as idle
	 * @param float                            $allocatedMemRatio   minimum mem_used / mem_total ratio indicating an allocated GPU
	 *
	 * @return array{
	 *     gang_size: int,
	 *     totals: array{
	 *         nodes: int,
	 *         gpus_present: int,
	 *         gpus_absent: int,
	 *         physical_gpus: int,
	 *         mig_instances: int,
	 *         active_compute: int,
	 *         allocated_idle: int,
	 *         unallocated_free: int,
	 *         unknown_telemetry: int,
	 *         allocated_but_idle_ratio: float,
	 *         mean_active_compute_util: ?float,
	 *         mean_allocated_compute_util: ?float,
	 *         mean_tensor_core_util: ?float
	 *     },
	 *     fragmentation: array{
	 *         total_free_gpus: int,
	 *         stranded_free_gpus: int,
	 *         whole_gang_slots_available: int,
	 *         ideal_whole_gang_slots: int,
	 *         fragmented_nodes: int,
	 *         fragmentation_index: float,
	 *         mig_profiles: array<string, int>
	 *     },
	 *     nodes: list<array{
	 *         node_id: int,
	 *         gpus_present: int,
	 *         mig_instances: int,
	 *         active_compute: int,
	 *         allocated_idle: int,
	 *         unallocated_free: int,
	 *         unknown_telemetry: int,
	 *         stranded_free_gpus: int,
	 *         whole_gang_slots: int,
	 *         faulted_gpus: int
	 *     }>,
	 *     fault_correlations: list<array{
	 *         scope: string,
	 *         node_id: int,
	 *         gang_id: string,
	 *         faulted_gpu_ids: list<int>,
	 *         stalled_peer_gpu_ids: list<int>,
	 *         blast_radius_gpus: int,
	 *         fault_signals: list<string>,
	 *         severity: string
	 *     }>
	 * }
	 */
	public function analyze(
		array $gpus,
		array $telemetry,
		int $gangSize = self::DEFAULT_GANG_SIZE,
		float $idleUtilThreshold = self::DEFAULT_IDLE_UTIL_THRESHOLD,
		float $allocatedMemRatio = self::DEFAULT_ALLOCATED_MEM_RATIO,
	): array {
		$gangSize          = max(1, $gangSize);
		$idleUtilThreshold = max(0.0, min(100.0, $idleUtilThreshold));
		$allocatedMemRatio = max(0.0, min(1.0, $allocatedMemRatio));

		$gpusPresent      = 0;
		$gpusAbsent       = 0;
		$physicalGpus     = 0;
		$migInstances     = 0;
		$activeCompute    = 0;
		$allocatedIdle    = 0;
		$unallocatedFree  = 0;
		$unknownTelemetry = 0;

		$activeUtilSum    = 0.0;
		$activeUtilCount  = 0;
		$allocUtilSum     = 0.0;
		$allocUtilCount   = 0;
		$tensorSum        = 0.0;
		$tensorCount      = 0;

		/** @var array<string, int> $migProfiles */
		$migProfiles = [];

		/** @var array<int, array{node_id: int, gpus_present: int, mig_instances: int, active_compute: int, allocated_idle: int, unallocated_free: int, unknown_telemetry: int, faulted_gpus: int}> $nodeBuckets */
		$nodeBuckets = [];

		/** @var array<string, array{node_id: int, gang_id: string, faulted_gpu_ids: list<int>, stalled_peer_gpu_ids: list<int>, all_allocated_ids: list<int>, signals: array<string, true>}> $gangBuckets */
		$gangBuckets = [];

		foreach ($gpus as $gpu) {
			if (!isset($nodeBuckets[$gpu->nodeId])) {
				$nodeBuckets[$gpu->nodeId] = [
					'node_id'           => $gpu->nodeId,
					'gpus_present'      => 0,
					'mig_instances'     => 0,
					'active_compute'    => 0,
					'allocated_idle'    => 0,
					'unallocated_free'  => 0,
					'unknown_telemetry' => 0,
					'faulted_gpus'      => 0,
				];
			}

			if ($gpu->presence !== Presence::Present) {
				$gpusAbsent++;

				continue;
			}

			$gpusPresent++;
			$nodeBuckets[$gpu->nodeId]['gpus_present']++;

			if ($gpu->isMig()) {
				$migInstances++;
				$nodeBuckets[$gpu->nodeId]['mig_instances']++;
				$profile = $gpu->migProfile !== '' ? $gpu->migProfile : 'unknown';
				$migProfiles[$profile] = ($migProfiles[$profile] ?? 0) + 1;
			} else {
				$physicalGpus++;
			}

			$sample     = $telemetry[$gpu->id] ?? [];
			$extended   = $this->resolveExtended($sample);
			$util       = $this->extractComputeUtil($sample, $extended);
			$memRatio   = $this->extractMemoryRatio($sample);
			$allocated  = $this->isAllocated($sample, $memRatio, $allocatedMemRatio, $util, $idleUtilThreshold);
			$state      = $this->classifyState($util, $memRatio, $allocated, $idleUtilThreshold);

			if ($state === self::STATE_ACTIVE_COMPUTE) {
				$activeCompute++;
				$nodeBuckets[$gpu->nodeId]['active_compute']++;
			} elseif ($state === self::STATE_ALLOCATED_IDLE) {
				$allocatedIdle++;
				$nodeBuckets[$gpu->nodeId]['allocated_idle']++;
			} elseif ($state === self::STATE_UNALLOCATED_FREE) {
				$unallocatedFree++;
				$nodeBuckets[$gpu->nodeId]['unallocated_free']++;
			} else {
				$unknownTelemetry++;
				$nodeBuckets[$gpu->nodeId]['unknown_telemetry']++;
			}

			if ($util !== null) {
				if ($state === self::STATE_ACTIVE_COMPUTE) {
					$activeUtilSum += $util;
					$activeUtilCount++;
				}

				if ($state === self::STATE_ACTIVE_COMPUTE || $state === self::STATE_ALLOCATED_IDLE) {
					$allocUtilSum += $util;
					$allocUtilCount++;
				}
			}

			if ($extended->tensorActive !== null) {
				$tensorSum += $extended->tensorActive;
				$tensorCount++;
			}

			$faultSignals = $this->detectFaultSignals($sample, $extended);
			$gangId       = isset($sample['gang_id']) && is_string($sample['gang_id']) && trim($sample['gang_id']) !== ''
				? trim($sample['gang_id'])
				: '';
			$scopeKey     = $gangId !== '' ? 'gang:' . $gangId : 'node:' . $gpu->nodeId;

			if (!isset($gangBuckets[$scopeKey])) {
				$gangBuckets[$scopeKey] = [
					'node_id'              => $gpu->nodeId,
					'gang_id'              => $gangId,
					'faulted_gpu_ids'      => [],
					'stalled_peer_gpu_ids' => [],
					'all_allocated_ids'    => [],
					'signals'              => [],
				];
			}

			if ($state === self::STATE_ACTIVE_COMPUTE || $state === self::STATE_ALLOCATED_IDLE) {
				$gangBuckets[$scopeKey]['all_allocated_ids'][] = $gpu->id;
			}

			if ($faultSignals !== []) {
				$nodeBuckets[$gpu->nodeId]['faulted_gpus']++;
				$gangBuckets[$scopeKey]['faulted_gpu_ids'][] = $gpu->id;

				foreach ($faultSignals as $signal) {
					$gangBuckets[$scopeKey]['signals'][$signal] = true;
				}
			} elseif ($state === self::STATE_ALLOCATED_IDLE) {
				$gangBuckets[$scopeKey]['stalled_peer_gpu_ids'][] = $gpu->id;
			}
		}

		ksort($nodeBuckets, SORT_NUMERIC);
		ksort($migProfiles, SORT_STRING);

		$totalFreeGpus   = 0;
		$strandedFree    = 0;
		$wholeGangSlots  = 0;
		$fragmentedNodes = 0;
		$nodeRows        = [];

		foreach ($nodeBuckets as $bucket) {
			$nodeFree     = $bucket['unallocated_free'];
			$nodeSlots    = intdiv($nodeFree, $gangSize);
			$nodeStranded = $nodeFree % $gangSize;

			$totalFreeGpus  += $nodeFree;
			$wholeGangSlots += $nodeSlots;
			$strandedFree   += $nodeStranded;

			if ($nodeStranded > 0) {
				$fragmentedNodes++;
			}

			$nodeRows[] = [
				'node_id'            => $bucket['node_id'],
				'gpus_present'       => $bucket['gpus_present'],
				'mig_instances'      => $bucket['mig_instances'],
				'active_compute'     => $bucket['active_compute'],
				'allocated_idle'     => $bucket['allocated_idle'],
				'unallocated_free'   => $nodeFree,
				'unknown_telemetry'  => $bucket['unknown_telemetry'],
				'stranded_free_gpus' => $nodeStranded,
				'whole_gang_slots'   => $nodeSlots,
				'faulted_gpus'       => $bucket['faulted_gpus'],
			];
		}

		$faultCorrelations = [];

		foreach ($gangBuckets as $scopeKey => $gang) {
			if ($gang['faulted_gpu_ids'] === []) {
				continue;
			}

			$signals = array_keys($gang['signals']);
			sort($signals, SORT_STRING);

			$stalledCount = count($gang['stalled_peer_gpu_ids']);
			$faultedCount = count($gang['faulted_gpu_ids']);
			$blastRadius  = max($faultedCount + $stalledCount, count($gang['all_allocated_ids']));

			$severity = 'warning';

			if ($stalledCount > 0 || isset($gang['signals']['xid_fault']) || isset($gang['signals']['ecc_uncorrectable'])) {
				$severity = 'critical';
			}

			$faultCorrelations[] = [
				'scope'                => $scopeKey,
				'node_id'              => $gang['node_id'],
				'gang_id'              => $gang['gang_id'],
				'faulted_gpu_ids'      => $gang['faulted_gpu_ids'],
				'stalled_peer_gpu_ids' => $gang['stalled_peer_gpu_ids'],
				'blast_radius_gpus'    => $blastRadius,
				'fault_signals'        => $signals,
				'severity'             => $severity,
			];
		}

		usort($faultCorrelations, static fn (array $a, array $b): int => [$b['blast_radius_gpus'], $a['scope']] <=> [$a['blast_radius_gpus'], $b['scope']]);

		$allocatedTotal      = $activeCompute + $allocatedIdle;
		$allocatedIdleRatio  = $allocatedTotal > 0 ? round($allocatedIdle / $allocatedTotal, 4) : 0.0;
		$fragmentationIndex  = $totalFreeGpus > 0 ? round($strandedFree / $totalFreeGpus, 4) : 0.0;
		$idealWholeGangSlots = intdiv($totalFreeGpus, $gangSize);

		return [
			'gang_size' => $gangSize,
			'totals'    => [
				'nodes'                       => count($nodeRows),
				'gpus_present'                => $gpusPresent,
				'gpus_absent'                 => $gpusAbsent,
				'physical_gpus'               => $physicalGpus,
				'mig_instances'               => $migInstances,
				'active_compute'              => $activeCompute,
				'allocated_idle'              => $allocatedIdle,
				'unallocated_free'            => $unallocatedFree,
				'unknown_telemetry'           => $unknownTelemetry,
				'allocated_but_idle_ratio'    => $allocatedIdleRatio,
				'mean_active_compute_util'    => $activeUtilCount > 0 ? round($activeUtilSum / $activeUtilCount, 2) : null,
				'mean_allocated_compute_util' => $allocUtilCount > 0 ? round($allocUtilSum / $allocUtilCount, 2) : null,
				'mean_tensor_core_util'       => $tensorCount > 0 ? round($tensorSum / $tensorCount, 2) : null,
			],
			'fragmentation' => [
				'total_free_gpus'            => $totalFreeGpus,
				'stranded_free_gpus'         => $strandedFree,
				'whole_gang_slots_available' => $wholeGangSlots,
				'ideal_whole_gang_slots'     => $idealWholeGangSlots,
				'fragmented_nodes'           => $fragmentedNodes,
				'fragmentation_index'        => $fragmentationIndex,
				'mig_profiles'               => $migProfiles,
			],
			'nodes'              => $nodeRows,
			'fault_correlations' => $faultCorrelations,
		];
	}

	/**
	 * Build a telemetry entry suitable for analyze() directly from a Reading.
	 *
	 * @param Reading $reading parsed GPU reading
	 * @param string  $gangId  optional explicit multi-GPU job/gang identifier
	 *
	 * @return array<string, mixed>
	 */
	public static function telemetryFromReading(Reading $reading, string $gangId = ''): array {
		$entry                      = $reading->values;
		$entry['vendor_extensions'] = $reading->vendorExtensions;

		if ($gangId !== '') {
			$entry['gang_id'] = $gangId;
		}

		return $entry;
	}

	/**
	 * Resolve typed ExtendedGpuTelemetry from a sample payload.
	 *
	 * @param array<string, mixed> $sample per-GPU observation payload
	 *
	 * @return ExtendedGpuTelemetry
	 */
	private function resolveExtended(array $sample): ExtendedGpuTelemetry {
		$raw = $sample['vendor_extensions'] ?? null;

		if ($raw instanceof ExtendedGpuTelemetry) {
			return $raw;
		}

		if (is_array($raw)) {
			/** @var array<string, ?float> $typed */
			$typed = [];

			foreach ($raw as $k => $v) {
				if (is_string($k) && (is_float($v) || is_int($v) || $v === null)) {
					$typed[$k] = $v === null ? null : (float) $v;
				}
			}

			return ExtendedGpuTelemetry::fromExtensions($typed);
		}

		return new ExtendedGpuTelemetry();
	}

	/**
	 * Prefer fine-grained SM active occupancy when available, falling back to util_gpu.
	 *
	 * @param array<string, mixed> $sample   per-GPU observation payload
	 * @param ExtendedGpuTelemetry $extended typed extended telemetry
	 *
	 * @return ?float
	 */
	private function extractComputeUtil(array $sample, ExtendedGpuTelemetry $extended): ?float {
		if ($extended->smActive !== null) {
			return $extended->smActive;
		}

		return $this->numericField($sample, 'util_gpu');
	}

	/**
	 * Compute frame-buffer memory allocation ratio in [0.0, 1.0], or null when unknown.
	 *
	 * @param array<string, mixed> $sample per-GPU observation payload
	 *
	 * @return ?float
	 */
	private function extractMemoryRatio(array $sample): ?float {
		$used  = $this->numericField($sample, 'mem_used');
		$total = $this->numericField($sample, 'mem_total');

		if ($used === null || $total === null || $total <= 0.0) {
			return null;
		}

		return max(0.0, min(1.0, $used / $total));
	}

	/**
	 * Determine whether a GPU is currently allocated to a workload.
	 *
	 * @param array<string, mixed> $sample            per-GPU observation payload
	 * @param ?float               $memRatio          mem_used / mem_total ratio
	 * @param float                $allocatedMemRatio threshold ratio indicating allocation
	 * @param ?float               $util              compute utilization percentage
	 * @param float                $idleUtilThreshold active compute threshold
	 *
	 * @return bool
	 */
	private function isAllocated(array $sample, ?float $memRatio, float $allocatedMemRatio, ?float $util, float $idleUtilThreshold): bool {
		if (isset($sample['allocated']) && is_bool($sample['allocated'])) {
			return $sample['allocated'];
		}

		if ($util !== null && $util >= $idleUtilThreshold) {
			return true;
		}

		return $memRatio !== null && $memRatio >= $allocatedMemRatio;
	}

	/**
	 * Classify a GPU into one of the four mutually exclusive Philly utilization states.
	 *
	 * @param ?float $util              compute utilization percentage
	 * @param ?float $memRatio          memory allocation ratio
	 * @param bool   $allocated         whether the GPU is allocated to a job
	 * @param float  $idleUtilThreshold compute threshold separating active vs idle
	 *
	 * @return string
	 */
	private function classifyState(?float $util, ?float $memRatio, bool $allocated, float $idleUtilThreshold): string {
		if ($util === null && $memRatio === null && !$allocated) {
			return self::STATE_UNKNOWN_TELEMETRY;
		}

		if ($util !== null && $util >= $idleUtilThreshold) {
			return self::STATE_ACTIVE_COMPUTE;
		}

		if ($allocated) {
			return self::STATE_ALLOCATED_IDLE;
		}

		return self::STATE_UNALLOCATED_FREE;
	}

	/**
	 * Identify active hardware fault or stall signals on one GPU.
	 *
	 * @param array<string, mixed> $sample   per-GPU observation payload
	 * @param ExtendedGpuTelemetry $extended typed extended telemetry
	 *
	 * @return list<string>
	 */
	private function detectFaultSignals(array $sample, ExtendedGpuTelemetry $extended): array {
		$signals = [];

		if ($extended->hasXidFault()) {
			$signals[] = 'xid_fault';
		}

		$eccUncorr    = $this->numericField($sample, 'ecc_uncorr');
		$eccUncorrNew = $this->numericField($sample, 'ecc_uncorr_new');

		if (($eccUncorrNew !== null && $eccUncorrNew > 0.0) || ($eccUncorr !== null && $eccUncorr > 0.0)) {
			$signals[] = 'ecc_uncorrectable';
		}

		if ($extended->nvlinkCrcErrors !== null && $extended->nvlinkCrcErrors > 0.0) {
			$signals[] = 'nvlink_crc_errors';
		}

		if ($extended->nvlinkReplayErrors !== null && $extended->nvlinkReplayErrors > 0.0) {
			$signals[] = 'nvlink_replay_errors';
		}

		if ($extended->pcieReplayErrors !== null && $extended->pcieReplayErrors > 0.0) {
			$signals[] = 'pcie_replay_errors';
		}

		$hwThermal = $this->numericField($sample, 'thr_hw_thermal');
		$hwBrake   = $this->numericField($sample, 'thr_hw_power_brake');

		if ($hwThermal !== null && $hwThermal >= 1.0) {
			$signals[] = 'hw_thermal_throttle';
		}

		if ($hwBrake !== null && $hwBrake >= 1.0) {
			$signals[] = 'hw_power_brake';
		}

		if ($extended->hasMemoryThermalPressure()) {
			$signals[] = 'hbm_thermal_pressure';
		}

		return $signals;
	}

	/**
	 * Read a finite float value from a sample array, or null when missing/non-numeric.
	 *
	 * @param array<string, mixed> $sample per-GPU observation payload
	 * @param string               $key    metric key
	 *
	 * @return ?float
	 */
	private function numericField(array $sample, string $key): ?float {
		if (!isset($sample[$key])) {
			return null;
		}

		$value = $sample[$key];

		if (is_int($value) || is_float($value)) {
			$f = (float) $value;

			return is_finite($f) ? $f : null;
		}

		if (is_string($value) && is_numeric($value)) {
			$f = (float) $value;

			return is_finite($f) ? $f : null;
		}

		return null;
	}
}
