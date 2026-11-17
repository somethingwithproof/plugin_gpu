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

use Somethingwithproof\Gpu\CacheRepository;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\Freshness;
use Somethingwithproof\Gpu\ScriptServer;

const SS_NOW = 1800000600;

beforeEach(function () {
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_db_rows'] = [];
	$GLOBALS['__gpu_log']     = [];
});

/**
 * A GPU sampled 60 s ago by the node's latest, successful collection.
 */
function ss_row(array $override = []): array {
	return array_merge([
		'id' => 9, 'vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-000000000001', 'gpu_index' => '0', 'name' => 'NVIDIA H100 80GB HBM3',
		'pci_bus_id' => '00000000:18:00.0', 'presence' => 'present', 'observed_at' => SS_NOW - 60, 'reported_at' => SS_NOW - 30,
		'collection_state' => 'ok', 'collection_finished_at' => SS_NOW - 30, 'util_gpu' => '37', 'mem_used' => '2147483648', 'power' => null,
		'temp_gpu' => '-5.5', 'thr_hw_slowdown' => '0',
	], $override);
}

function ss(): ScriptServer {
	return new ScriptServer(new CacheRepository(false), new FixedClock(SS_NOW), 600);
}

it('prints a current value, and unknown for a missing one, never zero', function () {
	expect(Freshness::format(Freshness::value('util_gpu', ss_row(), SS_NOW, 600)))->toBe('37')
		->and(Freshness::format(Freshness::value('mem_used', ss_row(), SS_NOW, 600)))->toBe('2147483648')
		->and(Freshness::format(Freshness::value('temp_gpu', ss_row(), SS_NOW, 600)))->toBe('-5.5')
		->and(Freshness::format(Freshness::value('thr_hw_slowdown', ss_row(), SS_NOW, 600)))->toBe('0')
		->and(Freshness::format(Freshness::value('power', ss_row(), SS_NOW, 600)))->toBe('U')
		->and(Freshness::format(Freshness::value('age', ss_row(), SS_NOW, 600)))->toBe('60')
		->and(Freshness::format(Freshness::value('no_such_field', ss_row(), SS_NOW, 600)))->toBe('U');
});

it('computes power percentage precisely and leaves missing or nonpositive limits unknown', function () {
	expect(Freshness::format(Freshness::value('power_pct', ss_row(['power' => '200.25', 'power_limit' => '200.50']), SS_NOW, 600)))->toBe('99.875312')
		->and(Freshness::format(Freshness::value('power_pct', ss_row(['power' => '200.25', 'power_limit' => '0']), SS_NOW, 600)))->toBe('U')
		->and(Freshness::format(Freshness::value('power_pct', ss_row(['power' => 'N/A', 'power_limit' => '200.50']), SS_NOW, 600)))->toBe('U');
});

it('prints unknown for stale, absent, unreported, failed, and never sampled GPUs, keeping age where a sample exists', function (array $row, string $value, string $age) {
	expect(Freshness::format(Freshness::value('util_gpu', ss_row($row), SS_NOW, 600)))->toBe($value)
		->and(Freshness::format(Freshness::value('age', ss_row($row), SS_NOW, 600)))->toBe($age);
})->with([
	'stale'                    => [['observed_at' => SS_NOW - 601], 'U', '601'],
	'at the limit'             => [['observed_at' => SS_NOW - 600], '37', '600'],
	'absent'                   => [['presence' => 'absent'], 'U', '60'],
	'not in the latest result' => [['reported_at' => SS_NOW - 330], 'U', '60'],
	'latest collection failed' => [['collection_state' => 'timed_out'], 'U', '60'],
	'empty latest collection'  => [['collection_state' => 'empty', 'reported_at' => SS_NOW - 330], 'U', '60'],
	'never collected'          => [['collection_state' => null], 'U', '60'],
	'never sampled'            => [['observed_at' => null, 'reported_at' => null], 'U', 'U'],
	'clock slightly ahead'     => [['observed_at' => SS_NOW + 30], '37', '0'],
	'clock far ahead'          => [['observed_at' => SS_NOW + 121], 'U', 'U'],
]);

it('formats values as plain decimals RRDtool accepts', function () {
	expect(Freshness::format(1e15))->toBe('1000000000000000')
		->and(Freshness::format(85899345920.0))->toBe('85899345920')
		->and(Freshness::format(0.000001))->toBe('0.000001')
		->and(Freshness::format(-0.0))->toBe('0')
		->and(Freshness::format(NAN))->toBe('U')
		->and(Freshness::format(INF))->toBe('U')
		->and(Freshness::format(null))->toBe('U');
});

it('lists every GPU at the node by identity key, and counts them', function () {
	$GLOBALS['__gpu_db_rows']['LEFT JOIN plugin_gpu_retired'] = [ss_row(), ss_row(['uuid' => 'GPU-00000000-0000-4000-8000-000000000002', 'gpu_index' => '1', 'presence' => 'absent'])];

	expect(ss()->call('40', 'index'))->toBe("nvidia/GPU-00000000-0000-4000-8000-000000000001\nnvidia/GPU-00000000-0000-4000-8000-000000000002")
		->and(ss()->call('40', 'num_indexes'))->toBe('2')
		->and(ss()->call('40', 'query', 'index'))->toBe("nvidia/GPU-00000000-0000-4000-8000-000000000001!nvidia/GPU-00000000-0000-4000-8000-000000000001\nnvidia/GPU-00000000-0000-4000-8000-000000000002!nvidia/GPU-00000000-0000-4000-8000-000000000002")
		->and(ss()->call('40', 'query', 'gpu_index'))->toBe("nvidia/GPU-00000000-0000-4000-8000-000000000001!0\nnvidia/GPU-00000000-0000-4000-8000-000000000002!1")
		->and(ss()->call('40', 'query', 'util_gpu'))->toBe("nvidia/GPU-00000000-0000-4000-8000-000000000001!37\nnvidia/GPU-00000000-0000-4000-8000-000000000002!U")
		->and(ss()->call('40', 'get', 'name', 'nvidia/GPU-00000000-0000-4000-8000-000000000002'))->toBe('NVIDIA H100 80GB HBM3');

	// Reads only.
	expect($GLOBALS['__gpu_db_exec'])->toBe([]);
});

it('gets one value by identity key, with the key checked before any read', function () {
	$GLOBALS['__gpu_db_rows']['LEFT JOIN plugin_gpu_retired'] = [ss_row()];

	expect(ss()->call('40', 'get', 'util_gpu', 'nvidia/GPU-00000000-0000-4000-8000-000000000001'))->toBe('37')
		->and(ss()->call('40', 'get', 'age', 'nvidia/GPU-00000000-0000-4000-8000-000000000001'))->toBe('60');

	$GLOBALS['__gpu_db_rows'] = [];

	expect(ss()->call('40', 'get', 'util_gpu', 'nvidia/GPU-00000000-0000-4000-8000-000000000009'))->toBe('U');

	$GLOBALS['__gpu_db_rows']['LEFT JOIN plugin_gpu_retired'] = function () {
		throw new LogicException('a malformed argument reached the database');
	};

	foreach (['', 'GPU-1', 'amd/GPU-1', "nvidia/GPU-1' OR 1=1", 'nvidia/GPU-1/2'] as $index) {
		expect(ss()->call('40', 'get', 'util_gpu', $index))->toBe('U', $index);
	}

	foreach (['', '0', '-1', '4 OR 1', '1e3'] as $host) {
		expect(ss()->call($host, 'get', 'util_gpu', 'nvidia/GPU-1'))->toBe('U', $host)
			->and(ss()->call($host, 'index'))->toBe('', $host);
	}

	expect(ss()->call('40', 'get', 'no_such_field', 'nvidia/GPU-1'))->toBe('U')
		->and(ss()->call('40', 'drop', 'x'))->toBe('');
});

it('prints an empty listing when the cache cannot be read', function () {
	$GLOBALS['__gpu_db_rows']['LEFT JOIN plugin_gpu_retired'] = false;

	expect(ss()->call('40', 'index'))->toBe('')
		->and(ss()->call('40', 'num_indexes'))->toBe('')
		->and(ss()->call('40', 'query', 'util_gpu'))->toBe('');
});

it('keeps a name with line breaks on one listing line', function () {
	$GLOBALS['__gpu_db_rows']['LEFT JOIN plugin_gpu_retired'] = [ss_row(['name' => "NVIDIA\nnvidia/GPU-x!0"])];

	expect(ss()->call('40', 'query', 'name'))->toBe('nvidia/GPU-00000000-0000-4000-8000-000000000001!NVIDIA nvidia/GPU-x!0');
});

it('never throws from the script server entry point', function () {
	// As Cacti's script server includes it.
	$called_by_script_server = true;

	require_once dirname(__DIR__, 2) . '/ss_gpu.php';

	$GLOBALS['__gpu_db_rows']['FROM settings'] = function () {
		throw new RuntimeException('database gone');
	};

	expect(ss_gpu('40', 'get', 'util_gpu', 'nvidia/GPU-1'))->toBe('U')
		->and(ss_gpu('40', 'index'))->toBe('')
		->and(end($GLOBALS['__gpu_log'])['message'])->toBe('ERROR: The GPU script server input failed: RuntimeException');
});
