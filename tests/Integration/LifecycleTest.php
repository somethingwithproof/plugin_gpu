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

// The version INFO declares, which Cacti records as the installed version.
define('GPU_VERSION', (string) (parse_ini_file(dirname(__DIR__, 2) . '/INFO', true)['info']['version'] ?? ''));

const GPU_TABLES = ['plugin_gpu_collection', 'plugin_gpu_gpu', 'plugin_gpu_node', 'plugin_gpu_observation', 'plugin_gpu_reindex', 'plugin_gpu_retired'];

// Row counts after the seed action: one node and one GPU record.
const GPU_SEEDED = ['plugin_gpu_collection' => 0, 'plugin_gpu_gpu' => 1, 'plugin_gpu_node' => 1, 'plugin_gpu_observation' => 0, 'plugin_gpu_reindex' => 0, 'plugin_gpu_retired' => 0];

const GPU_HOOKS = ['api_device_new', 'config_arrays', 'config_settings', 'device_remove', 'draw_navigation_text', 'poller_bottom'];

beforeEach(function () {
	lifecycle('reset');
});

afterAll(function () {
	lifecycle('reset');
});

it('installs fresh with tracked tables, hooks, and both realms, leaving the plugin disabled', function () {
	$state = lifecycle('install');

	expect($state['plugin']['status'])->toEqual('4')
		->and($state['plugin']['version'])->toEqual(GPU_VERSION)
		->and($state['schema'])->toEqual('2')
		->and(array_keys($state['tables']))->toBe(GPU_TABLES)
		->and($state['tracked'])->toBe(GPU_TABLES)
		->and(array_keys($state['hooks']))->toBe(GPU_HOOKS)
		->and($state['realms'])->toEqual([
			'gpu_inventory.php' => 'GPU View',
			'gpu_nodes.php'     => 'GPU Admin',
		])
		->and($state['admin'])->toBe(2);
});

it('enables and disables through core, keeping records on disable', function () {
	lifecycle('install');
	lifecycle('seed');

	$enabled = lifecycle('enable');
	expect($enabled['plugin']['status'])->toEqual('1')
		->and(array_values(array_unique(array_map('strval', $enabled['hooks']))))->toBe(['1']);

	$disabled = lifecycle('disable');
	expect($disabled['plugin']['status'])->toEqual('4')
		->and($disabled['tables'])->toBe(GPU_SEEDED);
});

it('upgrades from an older recorded version and is idempotent on repeated runs', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_version', '0.0');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['plugin']['version'])->toEqual(GPU_VERSION);

	foreach ([lifecycle('upgrade'), lifecycle('upgrade')] as $run) {
		expect($run['result'])->toBeTrue()
			->and($run['tables'])->toBe($first['tables'])
			->and($run['hooks'])->toBe($first['hooks'])
			->and($run['realms'])->toBe($first['realms'])
			->and($run['tracked'])->toBe(GPU_TABLES)
			->and($run['plugin'])->toBe($first['plugin']);
	}

	expect($first['tables'])->toBe(GPU_SEEDED);
});

it('imports the shipped templates once, and a forced re-import updates them in place', function () {
	$objects = ['data_query' => 1, 'data_template' => 1, 'graph_templates' => 5, 'graph_items' => 36, 'device_template' => 1, 'dq_graphs' => 5, 'dq_file' => true];

	expect(lifecycle('install')['objects'])->toBe($objects);

	lifecycle('set_setting', 'gpu_templates_sha256=');
	lifecycle('set_version', '0.0');

	expect(lifecycle('upgrade')['objects'])->toBe($objects)
		->and(lifecycle('upgrade')['objects'])->toBe($objects);
});

it('re-registers hooks and realms that went missing', function () {
	lifecycle('install');

	$missing = lifecycle('drop_registrations');
	expect($missing['hooks'])->toBe([]);

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['hooks']))->toBe(GPU_HOOKS)
		->and($state['realms'])->toHaveCount(2);
});

it('makes a hook repaired while the plugin is enabled active, and one repaired while disabled not', function () {
	lifecycle('install');
	lifecycle('enable');
	lifecycle('drop_hook', 'draw_navigation_text');

	$enabled = lifecycle('upgrade');
	expect($enabled['hooks'])->toEqual(array_fill_keys(GPU_HOOKS, '1'));

	lifecycle('disable');
	lifecycle('drop_hook', 'draw_navigation_text');

	$disabled = lifecycle('upgrade');
	expect($disabled['hooks']['draw_navigation_text'])->toEqual('0');
});

it('finishes an upgrade that was interrupted after creating only some tables', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_table', 'plugin_gpu_gpu');
	lifecycle('set_schema', '0');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(GPU_TABLES)
		->and($state['tables']['plugin_gpu_node'])->toBe(1)
		->and($state['schema'])->toEqual('2')
		->and($state['plugin']['version'])->toEqual(GPU_VERSION);
});

it('does not advance past a migration that did not run', function () {
	lifecycle('install');
	lifecycle('set_schema', '0');

	$stopped = lifecycle('migrate_through', '0');
	expect($stopped['result'])->toBeTrue()
		->and($stopped['schema'])->toEqual('0');

	$finished = lifecycle('upgrade');
	expect($finished['schema'])->toEqual('2');
});

it('upgrades a version 1 schema by adding the collection tables and keeping the records', function () {
	lifecycle('install');
	lifecycle('seed');

	foreach (['plugin_gpu_observation', 'plugin_gpu_collection', 'plugin_gpu_reindex', 'plugin_gpu_retired'] as $table) {
		lifecycle('drop_table', $table);
	}

	lifecycle('set_schema', '1');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and($state['schema'])->toEqual('2')
		->and(array_keys($state['tables']))->toBe(GPU_TABLES)
		->and($state['tracked'])->toBe(GPU_TABLES)
		->and($state['tables']['plugin_gpu_node'])->toBe(1);
});

it('refuses a schema newer than the code and leaves the recorded version alone', function () {
	lifecycle('install');
	lifecycle('set_schema', '99');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeFalse()
		->and($state['plugin']['version'])->toEqual('0.0')
		->and($state['schema'])->toEqual('99');
});

it('keeps tables on uninstall by default, and drops them only when the box was cleared', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall');
	expect(array_keys($kept['tables']))->toBe(GPU_TABLES)
		->and($kept['tables']['plugin_gpu_gpu'])->toBe(1)
		->and($kept['tracked'])->toBe([])
		->and($kept['hooks'])->toBe([])
		->and($kept['realms'])->toBe([]);

	// A reinstall reuses the kept tables and their rows.
	$reinstalled = lifecycle('install');
	expect($reinstalled['plugin']['status'])->toEqual('4')
		->and($reinstalled['tables']['plugin_gpu_gpu'])->toBe(1)
		->and($reinstalled['tracked'])->toBe(GPU_TABLES);

	lifecycle('set_setting', 'gpu_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall');
	expect($dropped['plugin'])->toBeEmpty()
		->and($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('honours keep tables on uninstall when the runtime is refused', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall_unbooted');
	expect($kept['tables']['plugin_gpu_gpu'])->toBe(1)
		->and($kept['tracked'])->toBe([]);

	lifecycle('reset');
	lifecycle('install');
	lifecycle('set_setting', 'gpu_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall_unbooted');
	expect($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('grants realms on install but never on a repair a viewer triggers', function () {
	expect(lifecycle('install')['admin'])->toBe(2);

	$repair = lifecycle('repair_as_viewer');
	expect($repair['realms'])->toHaveCount(2)
		->and($repair['result'])->toBe(['viewer_realms' => 0]);
});

it('keeps GPU identity rules against the real schema', function () {
	lifecycle('install');

	$r = lifecycle('identity_flow')['result'];

	expect($r['created'])->toBe([2, 1, 0])
		->and($r['confirmed'])->toBeTrue()
		->and($r['confirmed_twice'])->toBeFalse()
		// The unique key refuses a second record for a stored UUID.
		->and($r['duplicate_ok'])->toBeFalse()
		->and($r['rows'])->toBe([
			'a1' => ['node' => 'one', 'presence' => 'present', 'missing' => 1, 'replaces' => '', 'pending' => 0, 'bus' => '00000000:3B:00.0'],
			'b2' => ['node' => 'two', 'presence' => 'present', 'missing' => 0, 'replaces' => '', 'pending' => 0, 'bus' => '00000000:3C:00.0'],
			'c3' => ['node' => 'one', 'presence' => 'present', 'missing' => 0, 'replaces' => 'a1', 'pending' => 0, 'bus' => '00000000:3B:00.0'],
		])
		// A node that owns GPU records is not deleted; an empty one is.
		->and($r['delete_owner'])->toBeFalse()
		->and($r['delete_empty'])->toBeTrue()
		->and($r['nodes_left'])->toBe(2);
});
