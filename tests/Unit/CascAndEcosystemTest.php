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

use Somethingwithproof\Gpu\CascProvider;
use Somethingwithproof\Gpu\CollectionRepository;
use Somethingwithproof\Gpu\EcosystemIntegration;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\ObservationRepository;
use Somethingwithproof\Gpu\Schema;
use Somethingwithproof\Gpu\SettingsRepository;

function gpu_test_casc(): CascProvider {
	$clock = new FixedClock(1800000100);

	return new CascProvider(
		new SettingsRepository(false),
		new NodeRepository(false, $clock),
		new GpuRepository(false),
		new CollectionRepository(false),
		new ObservationRepository(false),
		$clock,
		300,
	);
}

function gpu_test_ecosystem(bool $hasView = true, array $allowedHosts = [101]): EcosystemIntegration {
	$clock = new FixedClock(1800000100);

	return new EcosystemIntegration(
		new Schema(false),
		new SettingsRepository(false),
		new NodeRepository(false, $clock),
		new GpuRepository(false),
		new CollectionRepository(false),
		new ObservationRepository(false),
		$clock,
		static fn (int $userId): bool => $hasView,
		static fn (int $userId, int $hostId): bool => in_array($hostId, $allowedHosts, true),
		300,
	);
}

beforeEach(function () {
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_db_rows'] = [
		'FROM settings WHERE name IN' => [
			['name' => 'gpu_absent_after', 'value' => '3'],
			['name' => 'gpu_stale_after', 'value' => '600'],
			['name' => 'gpu_uninstall_keep_tables', 'value' => 'on'],
			['name' => 'gpu_uninstall_remove_objects', 'value' => ''],
		],
		'FROM settings WHERE name =' => [['value' => (string) Schema::VERSION]],
		'FROM plugin_gpu_node'       => [
			['id' => 1, 'host_id' => 101, 'admin_state' => 'enabled', 'description' => 'gpu-01', 'device_id' => 101, 'created_at' => 100, 'updated_at' => 100],
			['id' => 2, 'host_id' => 102, 'admin_state' => 'disabled', 'description' => 'gpu-02', 'device_id' => 102, 'created_at' => 100, 'updated_at' => 100],
		],
		'FROM plugin_gpu_gpu' => [
			['id' => 1, 'vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-0000000000a1', 'node_id' => 1, 'gpu_index' => 0, 'pci_bus_id' => '00000000:18:00.0', 'name' => 'NVIDIA H100', 'driver_version' => '580.95.05', 'tool' => 'nvidia-smi', 'tool_version' => '580.95.05', 'presence' => 'present', 'missing_count' => 0, 'replaces_gpu_id' => 0, 'pending_node_id' => 0, 'pending_since' => 0, 'first_seen' => 100, 'last_seen' => 1800000050, 'mig_mode' => 'enabled', 'mig_instance_id' => '', 'mig_profile' => '', 'parent_uuid' => '', 'partition_info' => ''],
			['id' => 2, 'vendor' => 'nvidia', 'uuid' => 'MIG-00000000-0000-4000-8000-000000000101', 'node_id' => 1, 'gpu_index' => 0, 'pci_bus_id' => '00000000:18:00.0', 'name' => 'NVIDIA H100', 'driver_version' => '580.95.05', 'tool' => 'dcgm-exporter', 'tool_version' => '4.9.0', 'presence' => 'present', 'missing_count' => 0, 'replaces_gpu_id' => 0, 'pending_node_id' => 0, 'pending_since' => 0, 'first_seen' => 100, 'last_seen' => 1800000050, 'mig_mode' => 'enabled', 'mig_instance_id' => '1', 'mig_profile' => '3g.40gb', 'parent_uuid' => 'GPU-00000000-0000-4000-8000-0000000000a1', 'partition_info' => ''],
		],
		'FROM plugin_gpu_collection' => [
			['node_id' => 1, 'state' => 'ok', 'detail' => '', 'started_at' => 1800000040, 'finished_at' => 1800000050, 'observed_at' => 1800000050, 'gpus' => 2, 'new_gpus' => 0, 'rejected_lines' => 0, 'skipped' => 0, 'last_ok_at' => 1800000050],
		],
		'FROM plugin_gpu_observation' => [
			['gpu_id' => 1, 'observed_at' => 1800000050, 'backend' => 'nvidia-smi', 'tool_version' => '580.95.05', 'thr_mask' => '0', 'ecc_mode' => 'enabled'],
		],
	];
});

it('exports and round-trips CasC YAML and XML documents and rejects XXE', function () {
	$casc = gpu_test_casc();
	$yaml = $casc->export('yaml');
	$xml  = $casc->export('xml');

	$parsedYaml = CascProvider::parse($yaml, 'yaml');
	$parsedXml  = CascProvider::parse($xml, 'xml');

	expect($casc->validate($parsedYaml))->toBe([])
		->and($casc->validate($parsedXml))->toBe([])
		->and($parsedYaml['settings']['gpu_absent_after'])->toBe(3)
		->and($parsedXml['settings']['gpu_stale_after'])->toBe(600)
		->and($parsedYaml['nodes'])->toHaveCount(2)
		->and($parsedXml['nodes'])->toHaveCount(2);

	expect(fn () => CascProvider::parse('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><casc/>', 'xml'))
		->toThrow(InvalidArgumentException::class, 'DOCTYPE');
});

it('plans deterministic diffs and enforces confirmation hash on apply', function () {
	$casc    = gpu_test_casc();
	$desired = $casc->snapshot();

	$desired['settings']['gpu_stale_after'] = 900;
	$desired['nodes'][]                     = ['host_id' => 103, 'description' => 'gpu-03', 'admin_state' => 'enabled'];

	$plan = $casc->plan($desired);

	expect($plan['valid'])->toBeTrue()
		->and($plan['plan_hash'])->toMatch('/^[0-9a-f]{64}$/')
		->and($plan['changes']['settings'])->toHaveKey('gpu_stale_after')
		->and($plan['changes']['nodes'])->toBe([['action' => 'create', 'host_id' => 103, 'from' => null, 'to' => 'enabled']]);

	$rejected = $casc->apply($desired, 'wrong-hash');
	expect($rejected['applied'])->toBeFalse()
		->and($GLOBALS['__gpu_db_exec'])->toBe([]);

	$applied = $casc->apply($desired, $plan['plan_hash']);
	expect($applied['applied'])->toBeTrue()
		->and($applied['settings_updated'])->toBe(1)
		->and($applied['nodes_created'])->toBe(1);
});

it('formats operator health report in text and json', function () {
	$casc = gpu_test_casc();
	$text = $casc->formatHealth('text');
	$json = json_decode($casc->formatHealth('json'), true, 512, JSON_THROW_ON_ERROR);

	expect($text)->toContain('plugin_gpu health: OK')
		->and($text)->toContain('1 MIG instances')
		->and($json['status'])->toBe('ok')
		->and($json['summary']['mig_instances'])->toBe(1);
});

it('registers read-only View-realm-gated MCP abilities, Doctor checks, CasC provider, and remote_agent actions', function () {
	$eco = gpu_test_ecosystem(hasView: true, allowedHosts: [101]);

	$registered = [];
	$registrar  = new class($registered) {
		/** @var array<string, array<string, mixed>> */
		public array $items;

		/** @param array<string, array<string, mixed>> $items */
		public function __construct(array &$items) {
			$this->items = &$items;
		}

		/** @param array<string, mixed> $definition */
		public function register(string $id, array $definition): void {
			$this->items[$id] = $definition;
		}
	};

	$eco->registerAbilities($registrar);

	expect(array_keys($registered))->toBe(['gpu-list-nodes', 'gpu-list-devices', 'gpu-get-health']);

	foreach ($registered as $ability) {
		expect($ability['annotations'])->toBe(['readOnly' => true, 'destructive' => false, 'idempotent' => true])
			->and(($ability['permission'])(1))->toBeTrue();
	}

	// Host 102 is not permitted to this user, so only host 101 appears.
	$nodesResult   = ($registered['gpu-list-nodes']['execute'])(1, new stdClass());
	$devicesResult = ($registered['gpu-list-devices']['execute'])(1, new stdClass());
	$healthResult  = ($registered['gpu-get-health']['execute'])(1, new stdClass());

	expect($nodesResult['nodes'])->toHaveCount(1)
		->and($nodesResult['nodes'][0]['hostId'])->toBe(101)
		->and($devicesResult['devices'])->toHaveCount(2)
		->and($healthResult['status'])->toBe('ok')
		->and($healthResult['migInstances'])->toBe(1);

	// Denied user cannot pass permission check.
	$denied = gpu_test_ecosystem(hasView: false, allowedHosts: []);
	expect(($denied->abilities()['gpu-list-nodes']['permission'])(99))->toBeFalse();

	// Doctor checks (evaluated canonical descriptors).
	$checks = $eco->doctorChecks();
	expect(array_column($checks, 'id'))->toBe(['gpu.schema', 'gpu.collection_freshness', 'gpu.identity_anomalies']);

	foreach ($checks as $check) {
		expect($check)->toHaveKeys(['id', 'plugin', 'category', 'title', 'status', 'message', 'details', 'remediation'])
			->and($check['plugin'])->toBe('gpu')
			->and(str_starts_with($check['id'], 'gpu.'))->toBeTrue()
			->and(['ok', 'warn', 'fail', 'info'])->toContain($check['status'])
			->and($check['status'])->toBe('ok')
			->and($check['message'])->not->toBe('');
	}

	// setup.php doctor_register_checks must not echo $context.
	$hookChecks = gpu_doctor_register_checks(['mode' => 'run', 'plugin' => 'gpu']);
	expect(array_is_list($hookChecks))->toBeTrue()
		->and(array_key_exists('mode', $hookChecks))->toBeFalse()
		->and(array_column($hookChecks, 'id'))->toBe(['gpu.schema', 'gpu.collection_freshness', 'gpu.identity_anomalies']);

	// CasC provider descriptor (canonical 1-arg ProviderRegistrar contract).
	$cascDesc = $eco->cascDescriptor();
	expect($cascDesc['slug'])->toBe('gpu')
		->and($cascDesc['schemaVersion'])->toBe('1.0');

	$exported = ($cascDesc['export'])([]);
	expect($exported)->toHaveKeys(['config', 'reference'])
		->and($exported['config']['plugin'])->toBe('gpu')
		->and(($cascDesc['validate'])($exported['config']))->toBe([])
		->and(($cascDesc['validate'])($exported))->toBe([])
		->and(($cascDesc['validate'])(['plugin' => 'other']))->not->toBeEmpty()
		->and(($cascDesc['plan'])($exported['config'], []))->toBe([]);

	$planErr = ($cascDesc['plan'])(['plugin' => 'other'], []);
	expect($planErr[0]['operation'])->toBe('error');

	$desired = $exported['config'];
	$desired['settings']['gpu_stale_after'] = 900;
	$desired['nodes'][]                     = ['host_id' => 103, 'description' => 'gpu-03', 'admin_state' => 'enabled'];
	expect(count(($cascDesc['plan'])($desired, [])))->toBe(2)
		->and(($cascDesc['apply'])($desired, 'wrong-hash', [])['ok'])->toBeFalse();

	$cascRegistrar = new class {
		/** @var list<array<string, mixed>> */
		public array $registered = [];

		/** @param array<string, mixed> $descriptor */
		public function register(array $descriptor): void {
			$this->registered[] = $descriptor;
		}
	};
	expect(gpu_casc_register_providers($cascRegistrar))->toBe($cascRegistrar)
		->and($cascRegistrar->registered)->toHaveCount(1)
		->and($cascRegistrar->registered[0]['slug'])->toBe('gpu')
		->and(gpu_casc_register_providers(null)['slug'])->toBe('gpu');

	// Remote agent read-only actions.
	expect($eco->handleRemoteAgent('gpu_health')['status'])->toBe('ok')
		->and($eco->handleRemoteAgent('gpu_inventory')['plugin'])->toBe('gpu')
		->and($eco->handleRemoteAgent('exec_shell'))->toBeNull();

	// Decoupled Doctor debug info (plain list, no secrets, never echoing $context).
	$debugInfo = $eco->doctorDebugInfo('0.1.0');
	expect(array_is_list($debugInfo))->toBeTrue()
		->and($debugInfo)->toHaveCount(1)
		->and($debugInfo[0]['id'])->toBe('gpu.overview')
		->and($debugInfo[0]['plugin'])->toBe('gpu')
		->and($debugInfo[0]['label'])->toBe('GPU Plugin')
		->and($debugInfo[0]['fields']['plugin_version']['value'])->toBe('0.1.0')
		->and($debugInfo[0]['fields']['schema_version']['value'])->toBe(Schema::VERSION)
		->and($debugInfo[0]['fields']['mig_instances']['value'])->toBe(1);

	$hookDebug = gpu_doctor_register_debug_info(['plugin' => 'gpu']);
	expect(array_is_list($hookDebug))->toBeTrue()
		->and(array_key_exists('plugin', $hookDebug))->toBeFalse()
		->and($hookDebug[0]['id'])->toBe('gpu.overview');

	// Decoupled REST/API resource descriptors (canonical lowerCamelCase collections, realm_read, handlers).
	$resources = $eco->apiResources();
	expect(array_is_list($resources))->toBeTrue()
		->and(array_column($resources, 'collection'))->toBe(['gpuNodes', 'gpuDevices']);

	foreach ($resources as $resource) {
		expect($resource['plugin'])->toBe('gpu')
			->and($resource['collection'])->toMatch('/^[a-z][A-Za-z0-9]{2,63}$/')
			->and($resource['realm_read'])->toBe('gpu_inventory.php')
			->and($resource['read_only'])->toBeTrue()
			->and(array_keys($resource['handlers']))->toBe(['list', 'get']);
	}

	expect(($resources[0]['handlers']['list'])(['user_id' => 1, 'query' => []]))->toHaveCount(1)
		->and(($resources[0]['handlers']['get'])('gpuNodes/1', ['user_id' => 1])['host_id'])->toBe(101)
		->and(($resources[0]['handlers']['get'])('gpuNodes/2', ['user_id' => 1]))->toBeNull()
		->and(($resources[0]['handlers']['get'])('bad', ['user_id' => 1]))->toBeNull()
		->and(($resources[1]['handlers']['list'])(['user_id' => 1, 'query' => ['vendor' => 'nvidia']]))->toHaveCount(2)
		->and(($resources[1]['handlers']['get'])('gpuDevices/1', ['user_id' => 1])['vendor'])->toBe('nvidia')
		->and(($resources[1]['handlers']['get'])('gpuDevices/999', ['user_id' => 1]))->toBeNull();

	// Denied user receives empty lists and null on get.
	$deniedResources = $denied->apiResources();
	expect(($deniedResources[0]['handlers']['list'])(['user_id' => 99, 'query' => []]))->toBe([])
		->and(($deniedResources[0]['handlers']['get'])('1', ['user_id' => 99]))->toBeNull()
		->and(($deniedResources[1]['handlers']['list'])(['user_id' => 99, 'query' => []]))->toBe([])
		->and(($deniedResources[1]['handlers']['get'])('1', ['user_id' => 99]))->toBeNull();

	$mergedResources = gpu_api_register_resources([['plugin' => 'other', 'collection' => 'otherItems']]);
	expect($mergedResources)->toHaveCount(3)
		->and($mergedResources[1]['collection'])->toBe('gpuNodes')
		->and($mergedResources[2]['collection'])->toBe('gpuDevices');

	// RealmRepository resolves plugin_realms IDs (+100 offset) for stateless auth.
	$GLOBALS['__gpu_db_rows']['FROM plugin_realms'] = [['id' => 21], ['id' => 22]];
	expect((new Somethingwithproof\Gpu\RealmRepository(false))->viewRealmIds())->toBe([121, 122])
		->and(gpu_user_view_allowed(0))->toBeFalse();
});

it('ships schema/gpu.schema.yaml with strict Config vs State separation and SPDX metadata', function () {
	$path = dirname(__DIR__, 2) . '/schema/gpu.schema.yaml';
	expect(is_file($path))->toBeTrue();

	$yaml = (string) file_get_contents($path);
	expect($yaml)->toContain('SPDX-License-' . 'Identifier: GPL-2.0-or-later')
		->and($yaml)->toContain('config:')
		->and($yaml)->toContain('state:')
		->and($yaml)->toContain('read_only_in_casc: true')
		->and($yaml)->toContain('cross_plugin_php_imports: false')
		->and($yaml)->toContain('doctor_register_debug_info')
		->and($yaml)->toContain('api_register_resources');
});
