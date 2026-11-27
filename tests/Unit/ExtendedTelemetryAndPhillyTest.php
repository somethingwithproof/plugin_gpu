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

use Somethingwithproof\Gpu\AmdSmiParser;
use Somethingwithproof\Gpu\CollectionError;
use Somethingwithproof\Gpu\DcgmExporterParser;
use Somethingwithproof\Gpu\ExecutionState;
use Somethingwithproof\Gpu\ExtendedGpuTelemetry;
use Somethingwithproof\Gpu\GpuClusterUtilizationAnalyzer;
use Somethingwithproof\Gpu\GpuRecord;
use Somethingwithproof\Gpu\Presence;
use Somethingwithproof\Gpu\Vendor;

const EXT_NV_UUID_0  = 'GPU-00000000-0000-4000-8000-0000000000c1';
const EXT_AMD_UUID_0 = '22222222-3333-4444-5555-666666666601';

function philly_gpu(int $id, int $nodeId, Presence $presence = Presence::Present, string $migProfile = '', string $parentUuid = ''): GpuRecord {
	$uuid = $parentUuid !== ''
		? sprintf('MIG-00000000-0000-4000-8000-%012x', $id)
		: sprintf('GPU-00000000-0000-4000-8000-%012x', $id);

	return new GpuRecord(
		id: $id,
		vendor: Vendor::Nvidia,
		uuid: $uuid,
		nodeId: $nodeId,
		index: $id % 8,
		pciBusId: sprintf('00000000:%02X:00.0', 0x10 + ($id % 8)),
		name: 'NVIDIA H100 80GB HBM3',
		driverVersion: '580.95.05',
		tool: 'dcgm-exporter',
		toolVersion: '4.9.0',
		presence: $presence,
		missingCount: 0,
		replacesGpuId: 0,
		pendingNodeId: 0,
		pendingSince: 0,
		firstSeen: 1000,
		lastSeen: 1800000000,
		migMode: $parentUuid !== '' ? 'enabled' : '',
		migInstanceId: $parentUuid !== '' ? (string) ($id % 7 + 1) : '',
		migProfile: $migProfile,
		parentUuid: $parentUuid,
	);
}

it('hydrates and round-trips ExtendedGpuTelemetry without coercing unknown values to zero', function () {
	$empty = ExtendedGpuTelemetry::fromExtensions([]);

	expect($empty->nvlinkTxBytes)->toBeNull()
		->and($empty->tempMemory)->toBeNull()
		->and($empty->tensorActive)->toBeNull()
		->and($empty->lastXid)->toBeNull()
		->and($empty->hasInterconnectErrors())->toBeFalse()
		->and($empty->hasXidFault())->toBeFalse()
		->and($empty->hasMemoryThermalPressure())->toBeFalse()
		->and($empty->toExtensions())->toBe([]);

	$populated = ExtendedGpuTelemetry::fromExtensions([
		ExtendedGpuTelemetry::KEY_NVLINK_TX_BYTES      => 12500000000.0,
		ExtendedGpuTelemetry::KEY_NVLINK_RX_BYTES      => 11800000000.0,
		ExtendedGpuTelemetry::KEY_NVLINK_BANDWIDTH     => 24300000000.0,
		ExtendedGpuTelemetry::KEY_NVLINK_CRC_ERRORS    => 2.0,
		ExtendedGpuTelemetry::KEY_NVLINK_REPLAY_ERRORS => 5.0,
		ExtendedGpuTelemetry::KEY_PCIE_TX_BYTES        => 4096000.0,
		ExtendedGpuTelemetry::KEY_PCIE_RX_BYTES        => 8192000.0,
		ExtendedGpuTelemetry::KEY_PCIE_REPLAY_ERRORS   => 1.0,
		ExtendedGpuTelemetry::KEY_TEMP_MEMORY          => 88.0,
		ExtendedGpuTelemetry::KEY_TEMP_HOTSPOT         => 91.0,
		ExtendedGpuTelemetry::KEY_SM_ACTIVE            => 84.5,
		ExtendedGpuTelemetry::KEY_SM_OCCUPANCY         => 72.0,
		ExtendedGpuTelemetry::KEY_TENSOR_ACTIVE        => 66.2,
		ExtendedGpuTelemetry::KEY_LAST_XID             => 79.0,
		ExtendedGpuTelemetry::KEY_XID_ERROR_COUNT      => 3.0,
		ExtendedGpuTelemetry::KEY_FAN_SPEED_PCT        => 65.0,
	]);

	expect($populated->nvlinkTxBytes)->toBe(12500000000.0)
		->and($populated->tempMemory)->toBe(88.0)
		->and($populated->tensorActive)->toBe(66.2)
		->and($populated->lastXid)->toBe(79)
		->and($populated->xidErrorCount)->toBe(3)
		->and($populated->fanSpeedPct)->toBe(65.0)
		->and($populated->hasInterconnectErrors())->toBeTrue()
		->and($populated->hasXidFault())->toBeTrue()
		->and($populated->hasMemoryThermalPressure(85.0))->toBeTrue()
		->and($populated->hasMemoryThermalPressure(90.0))->toBeFalse();

	$roundTripped = ExtendedGpuTelemetry::fromExtensions($populated->toExtensions());
	expect($roundTripped)->toEqual($populated);

	// Non-finite and negative integer counters become null.
	$invalid = ExtendedGpuTelemetry::fromExtensions([
		ExtendedGpuTelemetry::KEY_TEMP_MEMORY => NAN,
		ExtendedGpuTelemetry::KEY_LAST_XID    => -1.0,
	]);
	expect($invalid->tempMemory)->toBeNull()
		->and($invalid->lastXid)->toBeNull();
});

it('parses NVLink, PCIe, HBM memory temp, SM/Tensor Core occupancy, XID errors, and fan speed from DCGM Exporter', function () {
	expect(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_MEMORY_TEMP'])->toBe(140)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_FAN_SPEED'])->toBe(190)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_PCIE_REPLAY_COUNTER'])->toBe(202)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_XID_ERRORS'])->toBe(230)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_NVLINK_CRC_FLIT_ERROR_COUNT_TOTAL'])->toBe(409)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_PROF_SM_ACTIVE'])->toBe(1002)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_PROF_PIPE_TENSOR_ACTIVE'])->toBe(1004)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_PROF_NVLINK_TX_BYTES'])->toBe(1011);

	$labels = 'gpu="0",UUID="' . EXT_NV_UUID_0 . '",modelName="NVIDIA H100 \"SXM5\" 80GB",pci_bus_id="0000:18:00.0"';
	$body   = implode("\n", [
		'DCGM_FI_DEV_GPU_TEMP{' . $labels . '} 62 1800000000000',
		'DCGM_FI_DEV_MEMORY_TEMP{' . $labels . '} 79 1800000000000',
		'DCGM_FI_DEV_FAN_SPEED{' . $labels . '} 55 1800000000000',
		'DCGM_FI_PROF_NVLINK_TX_BYTES{' . $labels . '} 9500000000 1800000000000',
		'DCGM_FI_PROF_NVLINK_RX_BYTES{' . $labels . '} 9200000000 1800000000000',
		'DCGM_FI_DEV_NVLINK_BANDWIDTH_TOTAL{' . $labels . '} 18700000000 1800000000000',
		'DCGM_FI_DEV_NVLINK_CRC_FLIT_ERROR_COUNT_TOTAL{' . $labels . '} 2 1800000000000',
		'DCGM_FI_DEV_NVLINK_CRC_DATA_ERROR_COUNT_TOTAL{' . $labels . '} 3 1800000000000',
		'DCGM_FI_DEV_NVLINK_REPLAY_ERROR_COUNT_TOTAL{' . $labels . '} 4 1800000000000',
		'DCGM_FI_DEV_NVLINK_RECOVERY_ERROR_COUNT_TOTAL{' . $labels . '} 1 1800000000000',
		'DCGM_FI_PROF_PCIE_TX_BYTES{' . $labels . '} 1200000 1800000000000',
		'DCGM_FI_PROF_PCIE_RX_BYTES{' . $labels . '} 2400000 1800000000000',
		'DCGM_FI_DEV_PCIE_REPLAY_COUNTER{' . $labels . '} 7 1800000000000',
		'DCGM_FI_PROF_SM_ACTIVE{' . $labels . '} 0.875 1800000000000',
		'DCGM_FI_PROF_SM_OCCUPANCY{' . $labels . '} 0.64 1800000000000',
		'DCGM_FI_PROF_PIPE_TENSOR_ACTIVE{' . $labels . '} 0.72 1800000000000',
		'DCGM_FI_DEV_XID_ERRORS{' . $labels . '} 48 1800000000000',
		'DCGM_EXP_XID_ERRORS_TOTAL{' . $labels . ',xid="48"} 2 1800000000000',
		'DCGM_EXP_XID_ERRORS_TOTAL{' . $labels . ',xid="79"} 1 1800000000000',
		'DCGM_UNSUPPORTED_CUSTOM_METRIC{' . $labels . '} 123 1800000000000',
	]);

	$reading = (new DcgmExporterParser())->parse($body)->readings[0];
	$ext     = $reading->extendedTelemetry();

	// GPU die temperature and HBM memory temperature remain strictly distinct.
	expect($reading->values['temp_gpu'])->toBe(62.0)
		->and($reading->report->name)->toBe('NVIDIA H100 "SXM5" 80GB')
		->and($ext->tempMemory)->toBe(79.0)
		->and($ext->fanSpeedPct)->toBe(55.0)
		->and($ext->nvlinkTxBytes)->toBe(9500000000.0)
		->and($ext->nvlinkRxBytes)->toBe(9200000000.0)
		->and($ext->nvlinkBandwidthTotal)->toBe(18700000000.0)
		->and($ext->nvlinkCrcErrors)->toBe(5.0)
		->and($ext->nvlinkReplayErrors)->toBe(5.0)
		->and($ext->pcieTxBytes)->toBe(1200000.0)
		->and($ext->pcieRxBytes)->toBe(2400000.0)
		->and($ext->pcieReplayErrors)->toBe(7.0)
		->and($ext->smActive)->toEqualWithDelta(87.5, 1e-6)
		->and($ext->smOccupancy)->toEqualWithDelta(64.0, 1e-6)
		->and($ext->tensorActive)->toEqualWithDelta(72.0, 1e-6)
		->and($ext->lastXid)->toBe(48)
		->and($ext->xidErrorCount)->toBe(3)
		->and($ext->hasInterconnectErrors())->toBeTrue()
		->and($ext->hasXidFault())->toBeTrue();

	// Duplicate XID series with the same xid label is still rejected.
	$dupXid = "DCGM_EXP_XID_ERRORS_TOTAL{{$labels},xid=\"48\"} 1\nDCGM_EXP_XID_ERRORS_TOTAL{{$labels},xid=\"48\"} 2\n";
	expect(fn () => (new DcgmExporterParser())->parse($dupXid))->toThrow(CollectionError::class, 'duplicates series');

	// Line limit truncation guard.
	$tooLong = str_repeat("# comment\n", 50001);
	try {
		(new DcgmExporterParser())->parse($tooLong);
		expect(false)->toBeTrue('Expected truncation error');
	} catch (CollectionError $e) {
		expect($e->state)->toBe(ExecutionState::Truncated);
	}
});

it('parses AMD HBM memory temperature, hotspot temperature, XGMI, PCIe, and fan speed into ExtendedGpuTelemetry', function () {
	$listJson = json_encode([
		['gpu' => 0, 'bdf' => '0000:0c:00.0', 'uuid' => EXT_AMD_UUID_0, 'market_name' => 'AMD Instinct MI300X', 'driver_version' => '6.8.5'],
	], JSON_THROW_ON_ERROR);

	$metricJson = json_encode([
		[
			'gpu'         => 0,
			'uuid'        => EXT_AMD_UUID_0,
			'bdf'         => '0000:0c:00.0',
			'temperature' => [
				'edge'    => ['value' => 54, 'unit' => 'C'],
				'hotspot' => ['value' => 77, 'unit' => 'C'],
				'mem'     => ['value' => 86, 'unit' => 'C'],
			],
			'fan' => [
				'usage' => ['value' => 48.5, 'unit' => '%'],
			],
			'pcie' => [
				'tx'           => ['value' => 512, 'unit' => 'MB/s'],
				'rx'           => ['value' => 256, 'unit' => 'MB/s'],
				'replay_count' => ['value' => 3, 'unit' => 'count'],
			],
			'xgmi' => [
				'tx'            => ['value' => 40, 'unit' => 'GB/s'],
				'rx'            => ['value' => 38, 'unit' => 'GB/s'],
				'crc_errors'    => ['value' => 1, 'unit' => 'count'],
				'replay_errors' => ['value' => 2, 'unit' => 'count'],
			],
		],
	], JSON_THROW_ON_ERROR);

	$reading = (new AmdSmiParser())->parseJson($listJson, $metricJson, 1800000900)->readings[0];
	$ext     = $reading->extendedTelemetry();

	expect($reading->values['temp_gpu'])->toBe(54.0)
		->and($ext->tempHotspot)->toBe(77.0)
		->and($ext->tempMemory)->toBe(86.0)
		->and($ext->hasMemoryThermalPressure())->toBeTrue()
		->and($ext->fanSpeedPct)->toBe(48.5)
		->and($ext->pcieTxBytes)->toBe(512.0 * 1048576.0)
		->and($ext->pcieRxBytes)->toBe(256.0 * 1048576.0)
		->and($ext->pcieReplayErrors)->toBe(3.0)
		->and($ext->nvlinkTxBytes)->toBe(40.0 * 1073741824.0)
		->and($ext->nvlinkRxBytes)->toBe(38.0 * 1073741824.0)
		->and($ext->nvlinkCrcErrors)->toBe(1.0)
		->and($ext->nvlinkReplayErrors)->toBe(2.0)
		->and($ext->hasInterconnectErrors())->toBeTrue();
});

it('quantifies Philly locality fragmentation, allocated-but-idle GPUs, MIG profiles, and gang fault stalls', function () {
	$gpus      = [];
	$telemetry = [];

	// Node 1 (8 GPUs): 4 active compute, 4 unallocated free -> 4 stranded free GPUs for an 8-GPU gang.
	for ($i = 1; $i <= 8; $i++) {
		$gpus[] = philly_gpu($i, 1);

		if ($i <= 4) {
			$telemetry[$i] = [
				'util_gpu'          => 90.0,
				'mem_used'          => 64.0 * 1073741824.0,
				'mem_total'         => 80.0 * 1073741824.0,
				'vendor_extensions' => [
					ExtendedGpuTelemetry::KEY_SM_ACTIVE     => 88.0,
					ExtendedGpuTelemetry::KEY_TENSOR_ACTIVE => 75.0,
				],
			];
		} else {
			$telemetry[$i] = [
				'util_gpu'  => 0.0,
				'mem_used'  => 0.0,
				'mem_total' => 80.0 * 1073741824.0,
			];
		}
	}

	// Node 2 (8 GPUs): 4 active compute, 4 unallocated free -> another 4 stranded free GPUs.
	// Together Nodes 1+2 have 8 free GPUs total (ideal_whole_gang_slots = 1),
	// yet whole_gang_slots_available = 0 (fragmentation_index = 1.0).
	for ($i = 9; $i <= 16; $i++) {
		$gpus[] = philly_gpu($i, 2);

		if ($i <= 12) {
			$telemetry[$i] = [
				'util_gpu'  => 82.0,
				'mem_used'  => 50.0 * 1073741824.0,
				'mem_total' => 80.0 * 1073741824.0,
			];
		} else {
			$telemetry[$i] = [
				'util_gpu'  => 0.0,
				'mem_used'  => 0.0,
				'mem_total' => 80.0 * 1073741824.0,
			];
		}
	}

	// Node 3 (8 GPUs): Gang job "train-llm-42" where GPU 17 faults (XID 79 + ECC DBE + NVLink CRC)
	// and peer GPUs 18..24 sit allocated-but-idle (holding 70 GiB VRAM, 1% util waiting on collective).
	for ($i = 17; $i <= 24; $i++) {
		$gpus[] = philly_gpu($i, 3);

		$telemetry[$i] = [
			'gang_id'           => 'train-llm-42',
			'util_gpu'          => $i === 17 ? 0.0 : 1.0,
			'mem_used'          => 70.0 * 1073741824.0,
			'mem_total'         => 80.0 * 1073741824.0,
			'ecc_uncorr_new'    => $i === 17 ? 2.0 : 0.0,
			'vendor_extensions' => $i === 17
				? [
					ExtendedGpuTelemetry::KEY_LAST_XID          => 79.0,
					ExtendedGpuTelemetry::KEY_NVLINK_CRC_ERRORS => 4.0,
				]
				: [],
		];
	}

	// Node 4: 2 MIG instances (3g.40gb) + 1 absent GPU + 1 GPU with unknown telemetry.
	$gpus[] = philly_gpu(25, 4, Presence::Present, '3g.40gb', EXT_NV_UUID_0);
	$gpus[] = philly_gpu(26, 4, Presence::Present, '3g.40gb', EXT_NV_UUID_0);
	$gpus[] = philly_gpu(27, 4, Presence::Absent);
	$gpus[] = philly_gpu(28, 4, Presence::Present);

	$telemetry[25] = ['util_gpu' => 60.0, 'mem_used' => 20.0 * 1073741824.0, 'mem_total' => 40.0 * 1073741824.0];
	$telemetry[26] = ['util_gpu' => 0.0, 'mem_used' => 15.0 * 1073741824.0, 'mem_total' => 40.0 * 1073741824.0];
	$telemetry[28] = [];

	$report = (new GpuClusterUtilizationAnalyzer())->analyze($gpus, $telemetry, gangSize: 8);

	expect($report['gang_size'])->toBe(8)
		->and($report['totals']['nodes'])->toBe(4)
		->and($report['totals']['gpus_present'])->toBe(27)
		->and($report['totals']['gpus_absent'])->toBe(1)
		->and($report['totals']['mig_instances'])->toBe(2)
		->and($report['totals']['active_compute'])->toBe(9)
		->and($report['totals']['allocated_idle'])->toBe(9)
		->and($report['totals']['unallocated_free'])->toBe(8)
		->and($report['totals']['unknown_telemetry'])->toBe(1)
		->and($report['totals']['allocated_but_idle_ratio'])->toBe(0.5)
		->and($report['totals']['mean_tensor_core_util'])->toBe(75.0)
		->and($report['fragmentation']['total_free_gpus'])->toBe(8)
		->and($report['fragmentation']['stranded_free_gpus'])->toBe(8)
		->and($report['fragmentation']['whole_gang_slots_available'])->toBe(0)
		->and($report['fragmentation']['ideal_whole_gang_slots'])->toBe(1)
		->and($report['fragmentation']['fragmented_nodes'])->toBe(2)
		->and($report['fragmentation']['fragmentation_index'])->toBe(1.0)
		->and($report['fragmentation']['mig_profiles'])->toBe(['3g.40gb' => 2]);

	expect($report['fault_correlations'])->toHaveCount(1);

	$corr = $report['fault_correlations'][0];
	expect($corr['scope'])->toBe('gang:train-llm-42')
		->and($corr['faulted_gpu_ids'])->toBe([17])
		->and($corr['stalled_peer_gpu_ids'])->toBe([18, 19, 20, 21, 22, 23, 24])
		->and($corr['blast_radius_gpus'])->toBe(8)
		->and($corr['severity'])->toBe('critical')
		->and($corr['fault_signals'])->toBe(['ecc_uncorrectable', 'nvlink_crc_errors', 'xid_fault']);
});

it('builds analyzer telemetry entries directly from parsed Reading objects', function () {
	$labels = 'gpu="0",UUID="' . EXT_NV_UUID_0 . '",pci_bus_id="0000:18:00.0"';
	$body   = implode("\n", [
		'DCGM_FI_DEV_GPU_UTIL{' . $labels . '} 1',
		'DCGM_FI_DEV_FB_USED{' . $labels . '} 40960',
		'DCGM_FI_DEV_FB_TOTAL{' . $labels . '} 81920',
		'DCGM_FI_DEV_XID_ERRORS{' . $labels . '} 48',
	]);

	$reading = (new DcgmExporterParser())->parse($body, 1800000000)->readings[0];
	$entry   = GpuClusterUtilizationAnalyzer::telemetryFromReading($reading, 'job-99');

	$report = (new GpuClusterUtilizationAnalyzer())->analyze([philly_gpu(1, 1)], [1 => $entry], gangSize: 1);

	expect($report['totals']['allocated_idle'])->toBe(1)
		->and($report['fault_correlations'])->toHaveCount(1)
		->and($report['fault_correlations'][0]['gang_id'])->toBe('job-99')
		->and($report['fault_correlations'][0]['fault_signals'])->toBe(['xid_fault']);
});
