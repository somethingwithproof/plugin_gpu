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

use Somethingwithproof\Gpu\CollectionError;
use Somethingwithproof\Gpu\DcgmExporterClient;
use Somethingwithproof\Gpu\DcgmExporterParser;
use Somethingwithproof\Gpu\ExecutionState;
use Somethingwithproof\Gpu\MetricModel;

const DCGM_UUID_0 = 'GPU-00000000-0000-4000-8000-0000000000a1';
const DCGM_UUID_1 = 'GPU-00000000-0000-4000-8000-0000000000b2';

it('maps confirmed numeric DCGM field IDs for every supported metric', function () {
	expect(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_GPU_UTIL'])->toBe(203)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_GPU_UTIL_RATIO'])->toBe(1613)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_FB_USED'])->toBe(252)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_FB_USED_BYTES'])->toBe(1627)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_POWER_USAGE'])->toBe(155)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_GPU_TEMP'])->toBe(150)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_ECC_DBE_VOL_TOTAL'])->toBe(311)
		->and(DcgmExporterParser::FIELD_IDS['DCGM_FI_DEV_CLOCK_THROTTLE_REASONS'])->toBe(112);
});

it('parses legacy DCGM Exporter Prometheus text into the unified MetricModel', function () {
	$labels = 'gpu="0",UUID="' . DCGM_UUID_0 . '",device="nvidia0",modelName="NVIDIA H100 80GB HBM3",pci_bus_id="0000:18:00.0"';
	$body   = implode("\n", [
		'# HELP DCGM_FI_DEV_GPU_UTIL GPU utilization (in %).',
		'# TYPE DCGM_FI_DEV_GPU_UTIL gauge',
		'DCGM_FI_DEV_GPU_UTIL{' . $labels . '} 67 1800000000000',
		'DCGM_FI_DEV_MEM_COPY_UTIL{' . $labels . '} 42 1800000000000',
		'DCGM_FI_DEV_FB_USED{' . $labels . '} 10240 1800000000000',
		'DCGM_FI_DEV_FB_FREE{' . $labels . '} 71680 1800000000000',
		'DCGM_FI_DEV_POWER_USAGE{' . $labels . '} 312.5 1800000000000',
		'DCGM_FI_DEV_POWER_MGMT_LIMIT{' . $labels . '} 700 1800000000000',
		'DCGM_FI_DEV_GPU_TEMP{' . $labels . '} 58 1800000000000',
		'DCGM_FI_DEV_ECC_SBE_VOL_TOTAL{' . $labels . '} 3 1800000000000',
		'DCGM_FI_DEV_ECC_DBE_VOL_TOTAL{' . $labels . '} 1 1800000000000',
		'DCGM_FI_DEV_CLOCK_THROTTLE_REASONS{' . $labels . '} 36 1800000000000',
	]);

	$collection = (new DcgmExporterParser())->parse($body);

	expect($collection->backend)->toBe(DcgmExporterParser::BACKEND)
		->and($collection->readings)->toHaveCount(1);

	$reading = $collection->readings[0];

	expect($reading->report->uuid)->toBe(DCGM_UUID_0)
		->and($reading->report->pciBusId)->toBe('00000000:18:00.0')
		->and($reading->report->name)->toBe('NVIDIA H100 80GB HBM3')
		->and($reading->observedAt)->toBe(1800000000)
		->and($reading->values['util_gpu'])->toBe(67.0)
		->and($reading->values['util_mem'])->toBe(42.0)
		->and($reading->values['mem_used'])->toBe(10240.0 * MetricModel::MIB)
		->and($reading->values['mem_total'])->toBe(81920.0 * MetricModel::MIB)
		->and($reading->values['power'])->toBe(312.5)
		->and($reading->values['power_limit'])->toBe(700.0)
		->and($reading->values['temp_gpu'])->toBe(58.0)
		->and($reading->values['ecc_corr'])->toBe(3.0)
		->and($reading->values['ecc_uncorr'])->toBe(1.0)
		->and($reading->mask)->toBe(0x24)
		->and($reading->values['thr_sw_power_cap'])->toBe(1.0)
		->and($reading->values['thr_sw_thermal'])->toBe(1.0)
		->and($reading->values['thr_hw_slowdown'])->toBe(0.0);
});

it('parses DCGM Exporter 4.9+ ratio, byte, watt, and hertz metrics and treats NaN as unknown', function () {
	$labels = 'gpu="0",UUID="' . DCGM_UUID_0 . '",modelName="NVIDIA B200",pci_bus_id="0000:3b:00.0"';
	$body   = implode("\n", [
		'DCGM_FI_DEV_GPU_UTIL_RATIO{' . $labels . '} 0.85',
		'DCGM_FI_DEV_FB_USED_BYTES{' . $labels . '} 4294967296',
		'DCGM_FI_DEV_FB_CAPACITY_BYTES{' . $labels . '} 85899345920',
		'DCGM_FI_DEV_BOARD_POWER_WATTS{' . $labels . '} 450.25',
		'DCGM_FI_DEV_GPU_TEMP_CELSIUS{' . $labels . '} NaN',
		'DCGM_FI_DEV_SM_CLOCK_HERTZ{' . $labels . '} 1980000000',
		'DCGM_FI_DEV_CLOCKS_EVENT_REASONS{' . $labels . '} 64',
	]);

	$collection = (new DcgmExporterParser())->parse($body, 1800000200);
	$reading    = $collection->readings[0];

	expect($reading->observedAt)->toBe(1800000200)
		->and($reading->values['util_gpu'])->toEqualWithDelta(85.0, 1e-9)
		->and($reading->values['mem_used'])->toBe(4294967296.0)
		->and($reading->values['mem_total'])->toBe(85899345920.0)
		->and($reading->values['power'])->toBe(450.25)
		->and($reading->values['temp_gpu'])->toBeNull()
		->and($reading->values['clock_sm'])->toEqualWithDelta(1980.0, 1e-6)
		->and($reading->values['thr_hw_thermal'])->toBe(1.0);
});

it('tracks MIG instances via GPU_I_ID and GPU_I_PROFILE without copying parent metrics', function () {
	$parent = 'gpu="0",UUID="' . DCGM_UUID_0 . '",modelName="NVIDIA A100-SXM4-80GB",pci_bus_id="0000:18:00.0"';
	$mig1   = $parent . ',GPU_I_ID="1",GPU_CI_ID="0",GPU_I_PROFILE="3g.40gb"';
	$mig2   = $parent . ',GPU_I_ID="2",GPU_CI_ID="0",GPU_I_PROFILE="4g.40gb"';
	$body   = implode("\n", [
		'DCGM_FI_DEV_MIG_MODE{' . $parent . '} 1',
		'DCGM_FI_DEV_GPU_TEMP{' . $parent . '} 61',
		'DCGM_FI_DEV_POWER_USAGE{' . $parent . '} 220',
		'DCGM_FI_DEV_FB_USED{' . $mig1 . '} 8192',
		'DCGM_FI_DEV_FB_FREE{' . $mig1 . '} 32768',
		'DCGM_FI_DEV_FB_USED{' . $mig2 . '} 4096',
		'DCGM_FI_DEV_FB_FREE{' . $mig2 . '} 36864',
	]);

	$collection = (new DcgmExporterParser())->parse($body, 1800000300);

	expect($collection->readings)->toHaveCount(3);

	$byKey = [];

	foreach ($collection->readings as $r) {
		$byKey[$r->report->uuid] = $r;
	}

	expect($byKey[DCGM_UUID_0]->report->migMode)->toBe('enabled')
		->and($byKey[DCGM_UUID_0]->report->isMig())->toBeFalse()
		->and($byKey[DCGM_UUID_0]->values['temp_gpu'])->toBe(61.0);

	$migKey1 = 'MIG-' . DCGM_UUID_0 . '/1/0';
	$migKey2 = 'MIG-' . DCGM_UUID_0 . '/2/0';

	expect($byKey[$migKey1]->report->isMig())->toBeTrue()
		->and($byKey[$migKey1]->report->parentUuid)->toBe(DCGM_UUID_0)
		->and($byKey[$migKey1]->report->migInstanceId)->toBe('1')
		->and($byKey[$migKey1]->report->migProfile)->toBe('3g.40gb')
		->and($byKey[$migKey1]->values['mem_used'])->toBe(8192.0 * MetricModel::MIB)
		// Parent-only metrics must stay unknown on the MIG instance (REQ-MIG-004).
		->and($byKey[$migKey1]->values['temp_gpu'] ?? null)->toBeNull()
		->and($byKey[$migKey1]->values['power'] ?? null)->toBeNull()
		->and($byKey[$migKey2]->report->migProfile)->toBe('4g.40gb');
});

it('rejects duplicate series and malformed lines from untrusted exporter output', function () {
	$labels = 'gpu="0",UUID="' . DCGM_UUID_0 . '",pci_bus_id="0000:18:00.0"';
	$dup    = "DCGM_FI_DEV_GPU_TEMP{{$labels}} 50\nDCGM_FI_DEV_GPU_TEMP{{$labels}} 55\n";

	expect(fn () => (new DcgmExporterParser())->parse($dup))->toThrow(CollectionError::class, 'duplicates series')
		->and(fn () => (new DcgmExporterParser())->parse('not a prometheus line'))->toThrow(CollectionError::class, 'not a valid Prometheus sample');
});

it('enforces endpoint allowlist, HTTPS/TLS verification, no redirects, timeout, and size cap', function () {
	$captured = [];
	$client   = new DcgmExporterClient(
		allowlist: ['gpu-01.internal:9400', '10.10.0.5'],
		timeoutSeconds: 7,
		maxBytes: 2048,
		allowPlainHttp: false,
		transport: function (string $url, array $options, int $maxBytes) use (&$captured): array {
			$captured = ['url' => $url, 'options' => $options, 'maxBytes' => $maxBytes];

			return ['status' => 200, 'body' => "# ok\n", 'truncated' => false];
		},
	);

	expect($client->fetch('https://gpu-01.internal:9400/metrics'))->toBe("# ok\n")
		->and($captured['options']['ssl']['verify_peer'])->toBeTrue()
		->and($captured['options']['ssl']['verify_peer_name'])->toBeTrue()
		->and($captured['options']['ssl']['allow_self_signed'])->toBeFalse()
		->and($captured['options']['http']['follow_location'])->toBe(0)
		->and($captured['options']['http']['max_redirects'])->toBe(0)
		->and($captured['options']['http']['timeout'])->toBe(7.0)
		->and($captured['maxBytes'])->toBe(2048);

	expect(fn () => $client->fetch('http://gpu-01.internal:9400/metrics'))->toThrow(CollectionError::class, 'HTTPS')
		->and(fn () => $client->fetch('https://evil.example:9400/metrics'))->toThrow(CollectionError::class, 'allowlist')
		->and(fn () => $client->fetch('https://gpu-01.internal:9400/admin'))->toThrow(CollectionError::class, '/metrics')
		->and(fn () => $client->fetch('https://user:pass@gpu-01.internal:9400/metrics'))->toThrow(CollectionError::class, 'credentials');

	$truncating = new DcgmExporterClient(
		allowlist: ['gpu-01.internal:9400'],
		transport: static fn (): array => ['status' => 200, 'body' => 'partial', 'truncated' => true],
	);

	try {
		$truncating->fetch('https://gpu-01.internal:9400/metrics');
		expect(false)->toBeTrue('Expected truncation error');
	} catch (CollectionError $e) {
		expect($e->state)->toBe(ExecutionState::Truncated);
	}
});
