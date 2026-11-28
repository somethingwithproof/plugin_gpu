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

use Somethingwithproof\Gpu\AdminState;
use Somethingwithproof\Gpu\AmdSmiParser;
use Somethingwithproof\Gpu\CollectionError;
use Somethingwithproof\Gpu\GpuReport;
use Somethingwithproof\Gpu\GpuSnapshot;
use Somethingwithproof\Gpu\IdentityReconciler;
use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\Vendor;

const AMD_UUID_0 = '11111111-2222-3333-4444-555555555501';
const AMD_UUID_1 = '11111111-2222-3333-4444-555555555502';

it('normalizes AMD Instinct MI300 amd-smi JSON into MetricModel with separate throttle accumulators and partitions', function () {
	$listJson = json_encode([
		[
			'gpu'            => 0,
			'bdf'            => '0000:0c:00.0',
			'uuid'           => strtoupper(AMD_UUID_0),
			'kfd_id'         => 1001,
			'node_id'        => 2,
			'partition_id'   => 0,
			'market_name'    => 'AMD Instinct MI300X',
			'driver_version' => '6.8.5',
		],
	], JSON_THROW_ON_ERROR);

	$metricJson = json_encode([
		[
			'gpu'               => 0,
			'uuid'              => strtoupper(AMD_UUID_0),
			'bdf'               => '0000:0c:00.0',
			'compute_partition' => 'SPX',
			'memory_partition'  => 'NPS1',
			'usage'             => [
				'gfx_activity' => ['value' => 74, 'unit' => '%'],
				'umc_activity' => ['value' => 52, 'unit' => '%'],
			],
			'mem_usage' => [
				'total_vram' => ['value' => 196608, 'unit' => 'MiB'],
				'used_vram'  => ['value' => 32768, 'unit' => 'MiB'],
			],
			'power' => [
				'socket_power' => ['value' => 415.5, 'unit' => 'W'],
				'power_limit'  => ['value' => 750, 'unit' => 'W'],
			],
			'temperature' => [
				'edge'    => 'N/A',
				'hotspot' => ['value' => 68, 'unit' => 'C'],
			],
			'clock' => [
				'gfx_0' => ['clk' => ['value' => 2100, 'unit' => 'MHz']],
				'mem_0' => ['clk' => ['value' => 1300, 'unit' => 'MHz']],
			],
			'ecc' => [
				'total_correctable_count'   => ['value' => 12, 'unit' => 'count'],
				'total_uncorrectable_count' => ['value' => 0, 'unit' => 'count'],
			],
			'throttle' => [
				'accumulation_counter'             => ['value' => 1000, 'unit' => 'ticks'],
				'ppt_accumulated'                  => ['value' => 45, 'unit' => 'ticks'],
				'ppt_violation_status'             => true,
				'socket_thermal_violation_status'  => false,
				'hbm_thermal_violation_status'     => 'N/A',
				'vr_thermal_violation_status'      => false,
			],
		],
	], JSON_THROW_ON_ERROR);

	$collection = (new AmdSmiParser())->parseJson($listJson, $metricJson, 1800000500);

	expect($collection->backend)->toBe(AmdSmiParser::BACKEND)
		->and($collection->readings)->toHaveCount(1);

	$reading = $collection->readings[0];

	expect($reading->report->vendor)->toBe(Vendor::Amd)
		->and($reading->report->uuid)->toBe(AMD_UUID_0)
		->and($reading->report->key())->toBe('amd/' . AMD_UUID_0)
		->and($reading->report->pciBusId)->toBe('00000000:0C:00.0')
		->and($reading->report->partitionInfo)->toBe('partition=0,compute=SPX,memory=NPS1')
		->and($reading->values['util_gpu'])->toBe(74.0)
		->and($reading->values['util_mem'])->toBe(52.0)
		->and($reading->values['mem_used'])->toBe(32768.0 * MetricModel::MIB)
		->and($reading->values['mem_total'])->toBe(196608.0 * MetricModel::MIB)
		// Falls back to junction/hotspot when edge is N/A.
		->and($reading->values['temp_gpu'])->toBe(68.0)
		->and($reading->values['power'])->toBe(415.5)
		->and($reading->values['power_limit'])->toBe(750.0)
		->and($reading->values['clock_sm'])->toBe(2100.0)
		->and($reading->values['clock_mem'])->toBe(1300.0)
		->and($reading->values['ecc_corr'])->toBe(12.0)
		->and($reading->values['ecc_uncorr'])->toBe(0.0)
		->and($reading->values['thr_sw_power_cap'])->toBe(1.0)
		->and($reading->values['thr_sw_thermal'])->toBe(0.0)
		->and($reading->values['thr_hw_thermal'])->toBe(0.0)
		->and($reading->values['thr_hw_slowdown'])->toBeNull()
		->and($reading->mask)->toBeNull()
		->and($reading->vendorExtensions['ppt_accumulated'])->toBe(45.0)
		->and($reading->vendorExtensions['accumulation_counter'])->toBe(1000.0);
});

it('leaves ASIC-unsupported fields as unknown on older MI250 generations', function () {
	$listJson = json_encode([
		['gpu' => 0, 'bdf' => '0000:c1:00.0', 'uuid' => 'GPU-' . AMD_UUID_1, 'market_name' => 'AMD Instinct MI250X'],
	], JSON_THROW_ON_ERROR);

	$metricJson = json_encode([
		[
			'gpu'         => 0,
			'usage'       => ['gfx_activity' => ['value' => 10, 'unit' => '%'], 'umc_activity' => 'N/A'],
			'temperature' => ['edge' => ['value' => 42, 'unit' => 'C']],
			'ecc'         => ['total_correctable_count' => 'N/A', 'total_uncorrectable_count' => 'N/A'],
		],
	], JSON_THROW_ON_ERROR);

	$reading = (new AmdSmiParser())->parseJson($listJson, $metricJson, 1800000600)->readings[0];

	expect($reading->report->uuid)->toBe(AMD_UUID_1)
		->and($reading->values['util_gpu'])->toBe(10.0)
		->and($reading->values['util_mem'])->toBeNull()
		->and($reading->values['temp_gpu'])->toBe(42.0)
		->and($reading->values['ecc_corr'])->toBeNull()
		->and($reading->values['thr_sw_power_cap'])->toBeNull();
});

it('rejects identity mismatch between amd-smi list and metric invocations', function () {
	$listJson = json_encode([
		['gpu' => 0, 'bdf' => '0000:0c:00.0', 'uuid' => AMD_UUID_0],
	], JSON_THROW_ON_ERROR);
	$metricJson = json_encode([
		['gpu' => 0, 'bdf' => '0000:0d:00.0', 'uuid' => AMD_UUID_0],
	], JSON_THROW_ON_ERROR);

	expect(fn () => (new AmdSmiParser())->parseJson($listJson, $metricJson, 1800000700))
		->toThrow(CollectionError::class, 'BDF does not match');
});

it('reconciles a mixed NVIDIA and AMD node snapshot cleanly in IdentityReconciler', function () {
	$node = new Node(1, 10, AdminState::Enabled, 'mixed-node', true, 1, 1);
	$snap = GpuSnapshot::create('mixed', '1.0', [
		GpuReport::create(Vendor::Nvidia, 'GPU-00000000-0000-4000-8000-0000000000a1', 0, '0000:18:00.0', 'NVIDIA H100', '580.95.05'),
		GpuReport::create(Vendor::Amd, AMD_UUID_0, 1, '0000:c1:00.0', 'AMD Instinct MI300X', '6.8.5'),
	]);

	$changes = (new IdentityReconciler())->reconcile($node, [], $snap, 3, 1800000800);

	expect($changes->created)->toHaveCount(2)
		->and($changes->created[0]->vendor)->toBe(Vendor::Nvidia)
		->and($changes->created[1]->vendor)->toBe(Vendor::Amd);
});
