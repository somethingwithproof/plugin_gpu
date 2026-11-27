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
use Somethingwithproof\Gpu\CollectionRepository;
use Somethingwithproof\Gpu\Collector;
use Somethingwithproof\Gpu\ExtendOutput;
use Somethingwithproof\Gpu\ExtendReader;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\GpuRecord;
use Somethingwithproof\Gpu\GpuReport;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\GpuSnapshot;
use Somethingwithproof\Gpu\IdentityReconciler;
use Somethingwithproof\Gpu\Inventory;
use Somethingwithproof\Gpu\LockRepository;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\NodeDevice;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\NvidiaSmiParser;
use Somethingwithproof\Gpu\ObservationRepository;
use Somethingwithproof\Gpu\Poller;
use Somethingwithproof\Gpu\Presence;
use Somethingwithproof\Gpu\Provisioner;
use Somethingwithproof\Gpu\ReindexRepository;
use Somethingwithproof\Gpu\ReplicationRepository;
use Somethingwithproof\Gpu\ScriptServer;
use Somethingwithproof\Gpu\Settings;
use Somethingwithproof\Gpu\Vendor;

it('tracks MIG instance create, destroy, and recreate without inheriting history', function () {
	$node       = new Node(1, 101, AdminState::Enabled, 'mig-node', true, 1, 1);
	$parentUuid = 'GPU-00000000-0000-4000-8000-0000000000a1';
	$migOld     = 'MIG-00000000-0000-4000-8000-000000000101';
	$migNew     = 'MIG-00000000-0000-4000-8000-000000000102';
	$reconciler = new IdentityReconciler();

	$parent = GpuReport::create(Vendor::Nvidia, $parentUuid, 0, '0000:18:00.0', 'NVIDIA A100', '580.95.05', 'enabled');
	$inst1  = GpuReport::createMig($migOld, $parentUuid, '1', '3g.40gb', '0000:18:00.0', 'NVIDIA A100', '580.95.05');

	$snap1    = GpuSnapshot::create('dcgm-exporter', '4.9.0', [$parent, $inst1]);
	$changes1 = $reconciler->reconcile($node, [], $snap1, 1, 1000);

	expect($changes1->created)->toHaveCount(2);

	$known1 = [
		GpuRecord::fromRow(['id' => 1] + $changes1->created[0]->toRow()),
		GpuRecord::fromRow(['id' => 2] + $changes1->created[1]->toRow()),
	];

	// Destroy old MIG instance and create a new instance with the same profile.
	$inst2    = GpuReport::createMig($migNew, $parentUuid, '1', '3g.40gb', '0000:18:00.0', 'NVIDIA A100', '580.95.05');
	$snap2    = GpuSnapshot::create('dcgm-exporter', '4.9.0', [$parent, $inst2]);
	$changes2 = $reconciler->reconcile($node, $known1, $snap2, 1, 1300);

	expect($changes2->created)->toHaveCount(1)
		->and($changes2->created[0]->uuid)->toBe($migNew)
		->and($changes2->created[0]->migProfile)->toBe('3g.40gb')
		->and($changes2->created[0]->parentUuid)->toBe($parentUuid);

	$absent = array_values(array_filter($changes2->updated, static fn (GpuRecord $r): bool => $r->uuid === $migOld));
	expect($absent)->toHaveCount(1)
		->and($absent[0]->presence)->toBe(Presence::Absent);
});

it('allows ScriptServer lookups for MIG instance keys', function () {
	$migUuid = 'MIG-GPU-00000000-0000-4000-8000-0000000000a1/1/0';
	$GLOBALS['__gpu_db_rows'] = [
		'FROM plugin_gpu_gpu' => [[
			'id'                     => 5,
			'vendor'                 => 'nvidia',
			'uuid'                   => $migUuid,
			'gpu_index'              => 0,
			'name'                   => 'NVIDIA A100',
			'pci_bus_id'             => '00000000:18:00.0',
			'presence'               => 'present',
			'observed_at'            => 1000,
			'reported_at'            => 1000,
			'mem_used'               => 8589934592.0,
			'collection_state'       => 'ok',
			'collection_finished_at' => 1000,
		]],
	];

	$ss = new ScriptServer(new CacheRepository(false), new FixedClock(1010), 600);

	expect($ss->call('40', 'get', 'mem_used', 'nvidia/' . $migUuid))->toBe('8589934592');
});

it('scopes Poller execution to the matching poller_id and skips main-only provisioning on remote pollers', function () {
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_db_rows'] = [
		'GET_LOCK'               => [['l' => 1]],
		'FROM plugin_gpu_node'   => [
			['id' => 1, 'host_id' => 101, 'admin_state' => 'enabled', 'description' => 'local-node', 'device_id' => 101, 'created_at' => 1, 'updated_at' => 1],
			['id' => 2, 'host_id' => 102, 'admin_state' => 'enabled', 'description' => 'remote-node', 'device_id' => 102, 'created_at' => 1, 'updated_at' => 1],
		],
		'FROM host WHERE id' => function (string $sql, array $params): array {
			return ($params[0] ?? 0) === 101
				? [['id' => 101, 'description' => 'local-node', 'hostname' => 'h1', 'poller_id' => 1, 'status' => 3, 'disabled' => '']]
				: [['id' => 102, 'description' => 'remote-node', 'hostname' => 'h2', 'poller_id' => 2, 'status' => 3, 'disabled' => '']];
		},
		'FROM plugin_gpu_collection' => [],
		'FROM plugin_gpu_gpu'        => [],
	];

	$emptyReader = new class implements ExtendReader {
		/** @var list<int> */
		public array $hosts = [];

		public function read(NodeDevice $device, string $token): ExtendOutput {
			$this->hosts[] = $device->id;

			return new ExtendOutput(0, 0, []);
		}
	};

	$clock     = new FixedClock(2000);
	$gpus      = new GpuRepository(false);
	$collector = new Collector($emptyReader, new NvidiaSmiParser(), new Inventory($gpus, new IdentityReconciler(), $clock), $gpus, new ObservationRepository(false), new CollectionRepository(false), new ReindexRepository(false), new LockRepository(false), $clock);
	$poller    = new Poller(new NodeRepository(false, $clock), new CollectionRepository(false), $collector, new Provisioner(new CactiObjectRepository(false), new CacheRepository(false), new ReindexRepository(false), $clock), new ReindexRepository(false), $clock);

	$stats = $poller->run(Settings::fromRow([]), 60, 2);

	expect($stats['nodes'])->toBe(1)
		->and($stats['remote'])->toBe(1)
		->and($stats['ok'])->toBe(1)
		->and(array_unique($emptyReader->hosts))->toBe([102]);
});

it('replicates poller-scoped plugin configuration tables via ReplicationRepository', function () {
	$GLOBALS['__gpu_db_rows'] = [
		'FROM plugin_gpu_node'    => [['id' => 2, 'host_id' => 102, 'admin_state' => 'enabled']],
		'FROM plugin_gpu_gpu'     => [['id' => 9, 'node_id' => 2, 'uuid' => 'GPU-00000000-0000-4000-8000-0000000000a1']],
		'FROM plugin_gpu_retired' => [],
	];

	$tables = (new ReplicationRepository(false))->tablesForPoller(2);

	expect(array_keys($tables))->toBe(['plugin_gpu_node', 'plugin_gpu_gpu', 'plugin_gpu_retired'])
		->and($tables['plugin_gpu_node'])->toHaveCount(1)
		->and($tables['plugin_gpu_gpu'])->toHaveCount(1)
		->and((new ReplicationRepository(false))->tablesForPoller(0))->toBe([]);
});
