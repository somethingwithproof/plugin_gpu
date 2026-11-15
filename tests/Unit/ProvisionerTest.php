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
use Somethingwithproof\Gpu\CacheRepository;
use Somethingwithproof\Gpu\CactiObjectRepository;
use Somethingwithproof\Gpu\CollectionStatus;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\NodeDevice;
use Somethingwithproof\Gpu\NodeAccess;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\NodesPage;
use Somethingwithproof\Gpu\Provisioner;
use Somethingwithproof\Gpu\ReindexRepository;

const PROV_KEY_A = 'nvidia/GPU-00000000-0000-4000-8000-000000000001';
const PROV_KEY_B = 'nvidia/GPU-00000000-0000-4000-8000-000000000002';

beforeEach(function () {
	$GLOBALS['__gpu_db_exec']       = [];
	$GLOBALS['__gpu_log']           = [];
	$GLOBALS['__gpu_cacti']         = [];
	$GLOBALS['__gpu_affected_rows'] = 1;
	$GLOBALS['__gpu_db_fail']       = [];
	$GLOBALS['__gpu_db_rows']       = [
		'FROM `snmp_query`'         => [['id' => 78]],
		'FROM host_snmp_query'      => [['reindex_method' => 0]],
		'FROM snmp_query_graph'     => [['id' => 11, 'graph_template_id' => 343], ['id' => 12, 'graph_template_id' => 344]],
		'FROM graph_local'          => [],
		'FROM host_snmp_cache'      => [['snmp_index' => PROV_KEY_B], ['snmp_index' => PROV_KEY_A]],
		'SELECT status, disabled FROM host' => [['status' => 3, 'disabled' => '']],
		'LEFT JOIN plugin_gpu_retired' => [
			['vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-000000000001', 'presence' => 'present'],
			['vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-000000000002', 'presence' => 'absent'],
		],
	];
});

function prov(): Provisioner {
	return new Provisioner(new CactiObjectRepository(false), new CacheRepository(false), new ReindexRepository(false), new FixedClock(1000));
}

function prov_node(): Node {
	return new Node(3, 40, AdminState::Enabled, 'gpu-node', true, 0, 0);
}

function prov_device(int $status = 3, string $disabled = ''): NodeDevice {
	return NodeDevice::fromRow(['id' => 40, 'hostname' => 'gpu-node', 'status' => $status, 'disabled' => $disabled]);
}

function prov_request(): array {
	return ['requested_at' => 900, 'reason' => 'new GPU', 'attempts' => 0, 'detail' => '', 'generation' => 4];
}

function prov_calls(string $function): array {
	return array_values(array_filter($GLOBALS['__gpu_cacti'], fn ($c) => $c[0] === $function));
}

it('attaches the data query with reindex method None through Cacti\'s API, and asks for a reindex', function () {
	$reads = 0;
	$GLOBALS['__gpu_db_rows']['FROM host_snmp_query'] = function () use (&$reads) {
		return $reads++ === 0 ? [] : [['reindex_method' => 0]];
	};

	expect(prov()->attach(prov_node()))->toBeTrue()
		->and(prov_calls('api_device_dq_add'))->toBe([['api_device_dq_add', 40, 78, 0]]);

	$request = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'plugin_gpu_reindex')));

	expect($request[0]['params'])->toBe([3, 1000, 'data query attached']);
});

it('resets a reindex method a device template change set, and leaves a correct attachment alone', function () {
	$reads = 0;
	$GLOBALS['__gpu_db_rows']['FROM host_snmp_query'] = function () use (&$reads) {
		return $reads++ === 0 ? [['reindex_method' => 1]] : [['reindex_method' => 0]];
	};

	expect(prov()->attach(prov_node()))->toBeTrue()
		->and(prov_calls('api_device_dq_add'))->toHaveCount(1)
		->and(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'plugin_gpu_reindex')))->toBe([]);

	$GLOBALS['__gpu_cacti'] = [];
	$GLOBALS['__gpu_db_rows']['FROM host_snmp_query'] = [['reindex_method' => 0]];

	expect(prov()->attach(prov_node()))->toBeTrue()
		->and($GLOBALS['__gpu_cacti'])->toBe([]);
});

it('keeps a reindex request while Cacti holds the device down or disabled, without calling run_data_query', function (int $status, string $disabled) {
	$result = prov()->provision(prov_node(), prov_device($status, $disabled), prov_request());

	expect($result['deferred'])->toBeTrue()
		->and($result['reindexed'])->toBeFalse()
		->and(prov_calls('run_data_query'))->toBe([]);

	$deferred = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'attempts = attempts + 1')));

	expect($deferred)->toHaveCount(1)
		->and(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'DELETE FROM plugin_gpu_reindex')))->toBe([]);
})->with([
	'down'     => [1, ''],
	'disabled' => [3, 'on'],
]);

it('completes a reindex only once Cacti lists exactly the plugin\'s GPUs', function () {
	$result = prov()->provision(prov_node(), prov_device(), prov_request());

	expect($result['reindexed'])->toBeTrue()
		->and(prov_calls('run_data_query'))->toBe([['run_data_query', 40, 78, true]]);

	$done = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'DELETE FROM plugin_gpu_reindex')));

	expect($done[0]['params'])->toBe([3, 4]);

	// run_data_query() returned true but the cache still lacks a GPU.
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_db_rows']['FROM host_snmp_cache'] = [['snmp_index' => PROV_KEY_A]];

	$result = prov()->provision(prov_node(), prov_device(), prov_request());

	expect($result['deferred'])->toBeTrue()
		->and(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'DELETE FROM plugin_gpu_reindex')))->toBe([]);
});

it('keeps the request when the device went down while Cacti reindexed it', function () {
	$GLOBALS['__gpu_db_rows']['SELECT status, disabled FROM host'] = [['status' => 1, 'disabled' => '']];

	$result = prov()->provision(prov_node(), prov_device(), prov_request());

	expect($result['deferred'])->toBeTrue()
		->and(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'DELETE FROM plugin_gpu_reindex')))->toBe([]);
});

it('keeps the request when the GPU records cannot be read, instead of reading them as none', function () {
	$GLOBALS['__gpu_db_rows']['LEFT JOIN plugin_gpu_retired'] = fn () => false;

	$result = prov()->provision(prov_node(), prov_device(), prov_request());

	expect($result['deferred'])->toBeTrue()
		->and(prov_calls('run_data_query'))->toBe([])
		->and(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'DELETE FROM host_snmp_cache')))->toBe([]);
});

it('clears the cache and orphans the data sources when the listing has no GPU left', function () {
	$cleared = false;
	$GLOBALS['__gpu_db_rows']['LEFT JOIN plugin_gpu_retired'] = [];
	$GLOBALS['__gpu_db_rows']['FROM host_snmp_cache']         = function () use (&$cleared) {
		$cleared = array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'DELETE FROM host_snmp_cache')) !== [];

		return $cleared ? [] : [['snmp_index' => PROV_KEY_A]];
	};

	$result = prov()->provision(prov_node(), prov_device(), prov_request());
	$writes = array_column($GLOBALS['__gpu_db_exec'], 'sql');

	expect($result['reindexed'])->toBeTrue()
		->and(prov_calls('run_data_query'))->toBe([])
		->and($writes)->toContain('DELETE FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ?')
		->and($writes)->toContain('UPDATE data_local SET orphan = 1 WHERE host_id = ? AND snmp_query_id = ?');
});

it('asks for a reindex when no request waits but the listing differs from Cacti\'s cache', function () {
	$GLOBALS['__gpu_db_rows']['FROM host_snmp_cache'] = [['snmp_index' => PROV_KEY_A]];

	prov()->provision(prov_node(), prov_device(), null);

	$request = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'INSERT INTO plugin_gpu_reindex')));

	expect($request)->toHaveCount(1)
		->and($request[0]['params'])->toBe([3, 1000, 'listing differs from the cache']);

	// Matching listings ask for nothing; neither does a device Cacti holds down.
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_db_rows']['FROM host_snmp_cache'] = [['snmp_index' => PROV_KEY_B], ['snmp_index' => PROV_KEY_A]];
	prov()->provision(prov_node(), prov_device(), null);

	$GLOBALS['__gpu_db_rows']['FROM host_snmp_cache'] = [['snmp_index' => PROV_KEY_A]];
	prov()->provision(prov_node(), prov_device(1), null);

	expect(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_starts_with($e['sql'], 'INSERT INTO plugin_gpu_reindex')))->toBe([]);
});

it('creates each graph type once for every present, cached GPU, and none for an absent one', function () {
	$GLOBALS['__gpu_db_rows']['FROM graph_local'] = [['snmp_query_graph_id' => 12, 'snmp_index' => PROV_KEY_A]];

	$result = prov()->provision(prov_node(), prov_device(), null);
	$graphs = prov_calls('create_complete_graph_from_template');

	expect($result['graphs'])->toBe(1)
		->and($graphs)->toHaveCount(1)
		->and($graphs[0][1])->toBe(343)
		->and($graphs[0][3])->toBe(['snmp_query_id' => 78, 'snmp_index_on' => 'gpuKey', 'snmp_query_graph_id' => 11, 'snmp_index' => PROV_KEY_A])
		->and(prov_calls('push_out_host'))->toBe([['push_out_host', 40, 1]]);
});

it('does nothing when the templates are not installed', function () {
	$GLOBALS['__gpu_db_rows']['FROM `snmp_query`'] = [];

	expect(prov()->provision(prov_node(), prov_device(), prov_request()))->toBe(['reindexed' => false, 'deferred' => false, 'graphs' => 0])
		->and($GLOBALS['__gpu_cacti'])->toBe([]);
});

it('disables the nodes of deleted devices and keeps their records', function () {
	expect((new NodeRepository(false, new FixedClock(5)))->disableForHosts([40, 41]))->toBe(1);

	$sql = $GLOBALS['__gpu_db_exec'][0];

	expect($sql['sql'])->toStartWith("UPDATE plugin_gpu_node SET admin_state = 'disabled'")
		->and($sql['params'])->toBe([5, 40, 41])
		->and(implode(' ', array_column($GLOBALS['__gpu_db_exec'], 'sql')))->not->toContain('DELETE');
});

it('describes the latest collection without tool output', function () {
	$page = new NodesPage(new NodeRepository(false, new FixedClock(0)), new GpuRepository(false), new NodeAccess(fn (int $id): bool => true), null, new FixedClock(1100));

	$ok     = CollectionStatus::fromRow(['node_id' => 3, 'state' => 'ok', 'finished_at' => 1000, 'gpus' => 8, 'new_gpus' => 1]);
	$failed = CollectionStatus::fromRow(['node_id' => 3, 'state' => 'timed_out', 'detail' => 'The GPU metrics command exited with status 124', 'finished_at' => 1000, 'skipped' => 2]);

	expect($page->collection(null))->toBe('Not collected yet')
		->and($page->collection($ok))->toBe('Collected: 8 GPUs; 1 new GPUs recorded (100 s ago)')
		->and($page->collection($failed))->toBe('Timed out: The GPU metrics command exited with status 124 (100 s ago); 2 runs skipped while one was still running');
});

it('offers only devices the acting user may see', function () {
	$GLOBALS['__gpu_db_rows'] = ['FROM host AS h LEFT JOIN' => [['id' => '5', 'description' => 'visible'], ['id' => '6', 'description' => 'hidden']]];

	$page = new NodesPage(new NodeRepository(false, new FixedClock(0)), new GpuRepository(false), new NodeAccess(fn (int $id): bool => $id === 5));

	ob_start();
	$page->renderEdit(null);
	$html = (string) ob_get_clean();

	expect($html)->toContain('<option value="5">visible</option>')
		->and($html)->not->toContain('value="6"');
});
