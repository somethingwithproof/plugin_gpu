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

use Somethingwithproof\Gpu\CactiObjectRepository;
use Somethingwithproof\Gpu\CacheRepository;
use Somethingwithproof\Gpu\CollectionRepository;
use Somethingwithproof\Gpu\Collector;
use Somethingwithproof\Gpu\ExtendOutput;
use Somethingwithproof\Gpu\ExtendReader;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\HealthPage;
use Somethingwithproof\Gpu\IdentityReconciler;
use Somethingwithproof\Gpu\Inventory;
use Somethingwithproof\Gpu\InventoryPage;
use Somethingwithproof\Gpu\LockRepository;
use Somethingwithproof\Gpu\NodeDevice;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\NvidiaSmiParser;
use Somethingwithproof\Gpu\ObservationRepository;
use Somethingwithproof\Gpu\Poller;
use Somethingwithproof\Gpu\Provisioner;
use Somethingwithproof\Gpu\ReindexRepository;
use Somethingwithproof\Gpu\Schema;
use Somethingwithproof\Gpu\Settings;
use Somethingwithproof\Gpu\SnmpdConfig;
use Somethingwithproof\Gpu\SystemClock;
use Somethingwithproof\Gpu\Vendor;

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) {
		$GLOBALS['__gpu_db_exec'][] = ['sql' => 'CREATE TABLE ' . $table, 'params' => []];

		return true;
	}
}

if (!function_exists('db_add_column')) {
	function db_add_column($table, $column, $log = true, $db_conn = false) {
		$GLOBALS['__gpu_db_exec'][] = ['sql' => 'ALTER TABLE ' . $table . ' ADD COLUMN ' . ($column['name'] ?? ''), 'params' => []];

		return true;
	}
}

beforeEach(function () {
	$GLOBALS['__gpu_db_exec']       = [];
	$GLOBALS['__gpu_db_rows']       = [];
	$GLOBALS['__gpu_db_fail']       = [];
	$GLOBALS['__gpu_log']           = [];
	$GLOBALS['__gpu_cacti']         = [];
	$GLOBALS['__gpu_affected_rows'] = 1;
});

it('covers Schema migrations from v0 to v4, v3 to v4 upgrade, downgrade refusal, and registration checks', function () {
	$schema = new Schema(false);

	// Fresh install from v0 to v4.
	$GLOBALS['__gpu_db_rows'] = [
		'FROM settings WHERE name ='    => [['value' => '0']],
		'FROM information_schema.TABLES'  => [['c' => 1]],
		'FROM information_schema.COLUMNS' => [['c' => 1]],
		'SELECT version FROM plugin_config' => [['version' => '0.1.0']],
		'SELECT status FROM plugin_config'  => [['status' => 1]],
		'FROM plugin_hooks'               => [['c' => count(Schema::HOOKS)]],
		'FROM plugin_realms'              => [['c' => 3]],
	];

	expect($schema->storedVersion())->toBe(0)
		->and($schema->isCurrent())->toBeFalse()
		->and($schema->migrate())->toBeTrue()
		->and($schema->recordedPluginVersion())->toBe('0.1.0')
		->and($schema->pluginEnabled())->toBeTrue()
		->and($schema->hooksRegistered())->toBeTrue();

	$sqls = array_column($GLOBALS['__gpu_db_exec'], 'sql');
	expect($sqls)->toContain('CREATE TABLE plugin_gpu_node')
		->and($sqls)->toContain('CREATE TABLE plugin_gpu_observation')
		->and($sqls)->toContain('ALTER TABLE plugin_gpu_gpu ADD COLUMN mig_mode')
		->and($sqls)->toContain('ALTER TABLE plugin_gpu_gpu ADD COLUMN partition_info');

	// Upgrade specifically from prior main v3 -> PR #85 v4.
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_db_rows']['FROM settings WHERE name ='] = [['value' => '3']];

	expect($schema->migrate())->toBeTrue();

	$v4Sqls = array_column($GLOBALS['__gpu_db_exec'], 'sql');
	expect($v4Sqls)->toContain('ALTER TABLE plugin_gpu_gpu ADD COLUMN mig_mode')
		->and($v4Sqls)->toContain('ALTER TABLE plugin_gpu_gpu ADD COLUMN mig_instance_id')
		->and($v4Sqls)->toContain('ALTER TABLE plugin_gpu_gpu ADD COLUMN mig_profile')
		->and($v4Sqls)->toContain('ALTER TABLE plugin_gpu_gpu ADD COLUMN parent_uuid')
		->and($v4Sqls)->toContain('ALTER TABLE plugin_gpu_gpu ADD COLUMN partition_info')
		->and($v4Sqls)->not->toContain('CREATE TABLE plugin_gpu_node');

	// Track tables, record plugin version, forget version.
	$schema->trackTables();
	$schema->recordPluginVersion('0.1.0', 'Thomas Vincent', 'https://example.invalid');
	$schema->forgetVersion();

	// Refuse schema downgrade when stored version > Schema::VERSION.
	$GLOBALS['__gpu_db_rows']['FROM settings WHERE name ='] = [['value' => '99']];
	expect($schema->migrate())->toBeFalse();

	// Fail closed if table creation or column addition fails verification.
	$GLOBALS['__gpu_db_rows']['FROM settings WHERE name ='] = [['value' => '0']];
	$GLOBALS['__gpu_db_rows']['FROM information_schema.TABLES'] = [['c' => 0]];
	expect($schema->migrate())->toBeFalse();

	$GLOBALS['__gpu_db_rows']['FROM settings WHERE name ='] = [['value' => '3']];
	$GLOBALS['__gpu_db_rows']['FROM information_schema.COLUMNS'] = [['c' => 0]];
	expect($schema->migrate())->toBeFalse();
});

it('covers ReindexRepository pending() and primary-key find()', function () {
	$repo = new ReindexRepository(false);

	$GLOBALS['__gpu_db_rows'] = [
		'FROM plugin_gpu_reindex WHERE node_id' => [
			['node_id' => 7, 'requested_at' => 1000, 'reason' => 'new GPU', 'attempts' => 2, 'detail' => 'deferred', 'generation' => 3],
		],
		'FROM plugin_gpu_reindex ORDER BY' => [
			['node_id' => 7, 'requested_at' => 1000, 'reason' => 'new GPU', 'attempts' => 2, 'detail' => 'deferred', 'generation' => 3],
		],
	];

	$one = $repo->find(7);
	$all = $repo->pending();

	expect($one)->toBe(['requested_at' => 1000, 'reason' => 'new GPU', 'attempts' => 2, 'detail' => 'deferred', 'generation' => 3])
		->and($all)->toHaveKey(7)
		->and($all[7])->toBe($one);

	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_reindex WHERE node_id'] = [];
	expect($repo->find(999))->toBeNull();
});

it('covers SystemClock, Vendor labels, and HealthPage and InventoryPage HTML rendering without N+1 queries', function () {
	expect((new SystemClock())->now())->toBeGreaterThan(1700000000)
		->and(Vendor::Nvidia->label())->toBe('NVIDIA')
		->and(Vendor::Amd->label())->toBe('AMD');

	$collectionQueries = 0;
	$GLOBALS['__gpu_db_rows'] = [
		'FROM plugin_gpu_node' => [
			['id' => 1, 'host_id' => 101, 'admin_state' => 'enabled', 'description' => 'gpu-01', 'device_id' => 101, 'created_at' => 1, 'updated_at' => 1],
			['id' => 2, 'host_id' => 102, 'admin_state' => 'enabled', 'description' => 'gpu-02', 'device_id' => 102, 'created_at' => 1, 'updated_at' => 1],
		],
		'FROM plugin_gpu_retired' => [],
		'FROM plugin_gpu_gpu'     => [
			['id' => 10, 'vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-0000000000a1', 'node_id' => 1, 'gpu_index' => 0, 'pci_bus_id' => '00000000:18:00.0', 'name' => 'NVIDIA H100', 'driver_version' => '580.95.05', 'tool' => 'nvidia-smi', 'tool_version' => '580.95.05', 'presence' => 'present', 'missing_count' => 0, 'replaces_gpu_id' => 0, 'pending_node_id' => 0, 'pending_since' => 0, 'first_seen' => 100, 'last_seen' => 99990],
		],
		'FROM plugin_gpu_observation' => [
			['gpu_id' => 10, 'observed_at' => 99990, 'backend' => 'nvidia-smi', 'tool_version' => '580.95.05', 'thr_mask' => '0', 'ecc_mode' => 'enabled'],
		],
		'FROM plugin_gpu_collection' => function (string $sql) use (&$collectionQueries): array {
			$collectionQueries++;

			return [
				['node_id' => 1, 'state' => 'ok', 'detail' => '', 'started_at' => 99980, 'finished_at' => 99990, 'observed_at' => 99990, 'gpus' => 1, 'new_gpus' => 0, 'rejected_lines' => 0, 'skipped' => 0, 'last_ok_at' => 99990],
				['node_id' => 2, 'state' => 'ok', 'detail' => '', 'started_at' => 99980, 'finished_at' => 99990, 'observed_at' => 99990, 'gpus' => 0, 'new_gpus' => 0, 'rejected_lines' => 0, 'skipped' => 0, 'last_ok_at' => 99990],
			];
		},
	];

	$clock = new FixedClock(100000);
	$health = new HealthPage(
		new NodeRepository(false, $clock),
		new GpuRepository(false),
		new CollectionRepository(false),
		new ObservationRepository(false),
		static fn (int $h): bool => true,
		static fn (int $h): ?bool => false,
		$clock,
		300,
		600,
	);

	ob_start();
	$health->render();
	$healthHtml = (string) ob_get_clean();

	expect($collectionQueries)->toBe(1)
		->and($healthHtml)->toContain('gpu-01')
		->and($healthHtml)->toContain('gpu-02')
		->and($healthHtml)->toContain('NVIDIA H100');

	$inv = new InventoryPage(
		new NodeRepository(false, $clock),
		new GpuRepository(false),
		static fn (int $h): bool => true,
		$clock,
	);

	ob_start();
	$inv->render();
	$invHtml = (string) ob_get_clean();

	expect($invHtml)->toContain('GPU-00000000-0000-4000-8000-0000000000a1')
		->and($invHtml)->toContain('NVIDIA H100');

	// Empty state rendering for both pages when no devices are visible.
	$emptyHealth = new HealthPage(
		new NodeRepository(false, $clock),
		new GpuRepository(false),
		new CollectionRepository(false),
		new ObservationRepository(false),
		static fn (int $h): bool => false,
		static fn (int $h): ?bool => false,
		$clock,
		300,
		600,
	);
	ob_start();
	$emptyHealth->render();
	expect((string) ob_get_clean())->toContain('No GPU nodes you may see.');

	$emptyInv = new InventoryPage(
		new NodeRepository(false, $clock),
		new GpuRepository(false),
		static fn (int $h): bool => false,
		$clock,
	);
	ob_start();
	$emptyInv->render();
	expect((string) ob_get_clean())->toContain('No GPUs recorded yet.');
});

it('covers Poller main-poller collection and primary-key reindex provisioning path', function () {
	$reader = new class implements ExtendReader {
		public function read(NodeDevice $device, string $token): ExtendOutput {
			if ($token === SnmpdConfig::NAMES) {
				return new ExtendOutput(0, 1, ['GPU-00000000-0000-4000-8000-0000000000a1, NVIDIA H100']);
			}

			return new ExtendOutput(0, 1, [
				'2026/11/26 12:00:00.000, GPU-00000000-0000-4000-8000-0000000000a1, 0, 00000000:18:00.0, 580.95.05, Enabled, 50, 40, 1024, 81920, 60, 250, 700, 1800, 2600, 0, 0, No, 0, 0, 0x0000000000000000',
			]);
		}
	};

	$clock = new FixedClock(1795694410);
	$gpus  = new GpuRepository(false);

	$GLOBALS['__gpu_db_rows'] = [
		'_LOCK('                                 => [['l' => 1]],
		'FROM plugin_gpu_node'                   => [['id' => 1, 'host_id' => 101, 'admin_state' => 'enabled', 'description' => 'gpu-01', 'device_id' => 101, 'created_at' => 1, 'updated_at' => 1]],
		'FROM host WHERE id'                     => [['id' => 101, 'description' => 'gpu-01', 'hostname' => 'gpu-01', 'poller_id' => 1, 'status' => 3, 'disabled' => '']],
		'FROM plugin_gpu_collection'             => [],
		'FROM plugin_gpu_gpu'                    => [
			['id' => 1, 'vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-0000000000a1', 'node_id' => 1, 'gpu_index' => 0, 'pci_bus_id' => '00000000:18:00.0', 'name' => 'NVIDIA H100', 'driver_version' => '580.95.05', 'tool' => 'nvidia-smi', 'tool_version' => '580.95.05', 'presence' => 'present', 'missing_count' => 0, 'replaces_gpu_id' => 0, 'pending_node_id' => 0, 'pending_since' => 0, 'first_seen' => 1, 'last_seen' => 1],
		],
		'FROM plugin_gpu_reindex WHERE node_id'  => [],
		'FROM `snmp_query`'                      => [],
	];

	$collector = new Collector(
		$reader,
		new NvidiaSmiParser(),
		new Inventory($gpus, new IdentityReconciler(), $clock),
		$gpus,
		new ObservationRepository(false),
		new CollectionRepository(false),
		new ReindexRepository(false),
		new LockRepository(false),
		$clock,
	);

	$poller = new Poller(
		new NodeRepository(false, $clock),
		new CollectionRepository(false),
		$collector,
		new Provisioner(new CactiObjectRepository(false), new CacheRepository(false), new ReindexRepository(false), $clock),
		new ReindexRepository(false),
		$clock,
	);

	$stats = $poller->run(Settings::fromRow([]), 60, Poller::MAIN_POLLER);

	expect($stats['nodes'])->toBe(1)
		->and($stats['ok'])->toBe(1)
		->and($stats['failed'])->toBe(0);
});
