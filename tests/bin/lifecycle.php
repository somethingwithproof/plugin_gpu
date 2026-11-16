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

/*
 * Drive the plugin lifecycle through Cacti's own plugin API in a fresh PHP
 * process, the way plugins.php and cli/plugin_manage.php do, and print the
 * resulting state as JSON. Used by tests/Integration. Disposable databases
 * only.
 *
 *   php plugins/gpu/tests/bin/lifecycle.php <action> [argument]
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

if (getenv('GPU_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set GPU_TEST_DATABASE=disposable\n");
	exit(2);
}

$action   = isset($argv[1]) ? $argv[1] : 'state';
$argument = isset($argv[2]) ? $argv[2] : '';
$result   = null;

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('gpu');

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_gpu\\_%'") as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'gpu\\_%'");

		// What the template removal tests add outside the plugin.
		db_execute("DELETE FROM host_template_graph WHERE host_template_id IN (SELECT id FROM host_template WHERE name = 'gpu-lifecycle-other')");
		db_execute("DELETE FROM host_template WHERE name = 'gpu-lifecycle-other'");
		db_execute("DELETE FROM host WHERE description = 'gpu-lifecycle-device'");
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'gpu'");

		break;
	case 'install':
		api_plugin_install('gpu');

		break;
	case 'enable':
		api_plugin_enable('gpu');

		break;
	case 'disable':
		api_plugin_disable('gpu');

		break;
	case 'uninstall':
		api_plugin_uninstall('gpu');

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/gpu/setup.php');
		$result = plugin_gpu_upgrade();

		break;
	case 'migrate_through':
		// Stop after the given migration, as an upgrade killed part way would.
		include_once($config['base_path'] . '/plugins/gpu/setup.php');
		gpu_boot();
		$result = (new \Somethingwithproof\Gpu\Schema(false))->migrate((int) $argument);

		break;
	case 'set_version':
		db_execute_prepared("UPDATE plugin_config SET version = ? WHERE directory = 'gpu'", array($argument));

		break;
	case 'set_schema':
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('gpu_schema_version', ?)", array($argument));

		break;
	case 'set_setting':
		list($name, $value) = explode('=', $argument, 2);
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));

		break;
	case 'drop_registrations':
		// What a failed hook registration on an earlier request leaves.
		db_execute("DELETE FROM plugin_hooks WHERE name = 'gpu'");
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'gpu'");

		break;
	case 'drop_hook':
		db_execute_prepared("DELETE FROM plugin_hooks WHERE name = 'gpu' AND hook = ?", array($argument));

		break;
	case 'drop_table':
		// As if the table had never been created: no table, no tracking row.
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');
		db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = 'gpu' AND `table` = ?", array($argument));

		break;
	case 'drop_columns':
		// As a version 2 schema had it: the observation table without the
		// columns migration 3 adds, and one stored sample.
		gpu_boot();

		foreach (\Somethingwithproof\Gpu\Schema::OBSERVATION_METRICS_3 + array(99 => 'ecc_mode') as $column) {
			db_execute('ALTER TABLE plugin_gpu_observation DROP COLUMN `' . $column . '`');
		}

		db_execute_prepared("INSERT INTO plugin_gpu_observation (gpu_id, node_id, observed_at, ingested_at, reported_at, backend, tool_version, temp_gpu) SELECT id, node_id, 1, 1, 1, 'nvidia-smi', '580.95.05', 40 FROM plugin_gpu_gpu LIMIT 1");

		break;
	case 'seed':
		// A node and a GPU record, so table drops and keeps are visible.
		db_execute("INSERT INTO plugin_gpu_node (host_id, admin_state) VALUES (9001, 'enabled')");
		db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, pci_bus_id) VALUES ('nvidia', 'GPU-00000000-0000-4000-8000-0000000000a1', ?, '00000000:3B:00.0')", array(db_fetch_insert_id()));

		break;
	case 'ecc_gap':
		include_once($config['base_path'] . '/plugins/gpu/setup.php');
		gpu_boot();
		$report = \Somethingwithproof\Gpu\GpuReport::create(\Somethingwithproof\Gpu\Vendor::Nvidia, 'GPU-00000000-0000-4000-8000-0000000000a1', 0, '00000000:3B:00.0', 'Test GPU', '580.95.05');
		$observations = new \Somethingwithproof\Gpu\ObservationRepository(false);
		$result = array();
		// Consecutive success, a failed read, recovery, then a missing GPU
		// in a successful read: neither gap may become a multi-poll delta.
		foreach (array(array(1000, 10, 0), array(1060, 12, 1000), array(1180, 20, 0), array(1240, 23, 1180), array(1360, 30, 1300), array(1420, 32, 1360)) as $sample) {
			$reading = new \Somethingwithproof\Gpu\Reading($report, $sample[0], array('ecc_uncorr' => (float) $sample[1]), null);
			if (!$observations->store(99, 1, $reading, 'nvidia-smi', '580.95.05', $sample[0], $sample[2])) {
				throw new RuntimeException('ECC observation write failed');
			}
			$row = db_fetch_row('SELECT ecc_uncorr_new FROM plugin_gpu_observation WHERE gpu_id = 99');
			$result[] = $row['ecc_uncorr_new'] === null ? null : (float) $row['ecc_uncorr_new'];
		}
		break;
	case 'identity_flow':
		// The identity rules against MariaDB through the real repositories:
		// a replacement, a pending move, its confirmation, the unique key,
		// and the node delete guard (REQ-ID-001 to REQ-ID-005).
		include_once($config['base_path'] . '/plugins/gpu/setup.php');
		gpu_boot();

		$clock     = new \Somethingwithproof\Gpu\FixedClock(1800000000);
		$nodes     = new \Somethingwithproof\Gpu\NodeRepository(false, $clock);
		$gpus      = new \Somethingwithproof\Gpu\GpuRepository(false);
		$inventory = new \Somethingwithproof\Gpu\Inventory($gpus, new \Somethingwithproof\Gpu\IdentityReconciler(), $clock);
		$enabled   = \Somethingwithproof\Gpu\AdminState::Enabled;

		$one   = $nodes->find($nodes->save(0, 9001, $enabled));
		$two   = $nodes->find($nodes->save(0, 9002, $enabled));
		$three = $nodes->save(0, 9003, $enabled);

		$uuid = function ($suffix) {
			return 'GPU-00000000-0000-4000-8000-0000000000' . $suffix;
		};

		$snapshot = function (array $gpus) {
			$reports = array();

			foreach ($gpus as $gpu) {
				$reports[] = \Somethingwithproof\Gpu\GpuReport::create(\Somethingwithproof\Gpu\Vendor::Nvidia, $gpu[0], $gpu[1], $gpu[2], 'NVIDIA Test GPU', '580.95.05');
			}

			return \Somethingwithproof\Gpu\GpuSnapshot::create('nvidia-smi', '580.95.05', $reports);
		};

		$steps = array();

		$steps[] = count($inventory->record($one, $snapshot(array(array($uuid('a1'), 0, '00000000:3B:00.0'), array($uuid('b2'), 1, '00000000:3C:00.0'))), 2)->created);
		$clock->advance(60);
		$steps[] = count($inventory->record($one, $snapshot(array(array($uuid('c3'), 0, '0000:3b:00.0'), array($uuid('b2'), 1, '00000000:3C:00.0'))), 2)->created);
		$clock->advance(60);
		$steps[] = count($inventory->record($two, $snapshot(array(array($uuid('b2'), 0, '00000000:5E:00.0'))), 2)->created);

		$b = null;

		foreach ($gpus->pendingMoves() as $record) {
			$b = $record;
		}

		$confirmed      = $b !== null && $gpus->confirmMove($b->id, $b->nodeId, $b->pendingNodeId);
		$confirmedTwice = $b !== null && $gpus->confirmMove($b->id, $b->nodeId, $b->pendingNodeId);

		// A second record for a stored UUID must fail at the unique key.
		$copy      = $snapshot(array(array($uuid('a1'), 3, '00000000:AF:00.0')));
		$duplicate = $gpus->apply(new \Somethingwithproof\Gpu\Reconciliation(array(\Somethingwithproof\Gpu\GpuRecord::discovered($copy->reports[0], $copy, $two->id, 0, 0))));

		$rows = array();
		$ids  = array_column(db_fetch_assoc('SELECT id, uuid FROM plugin_gpu_gpu'), 'uuid', 'id');

		foreach ($gpus->all() as $record) {
			$rows[substr($record->uuid, -2)] = array(
				'node'     => $record->nodeId === $one->id ? 'one' : ($record->nodeId === $two->id ? 'two' : 'other'),
				'presence' => $record->presence->value,
				'missing'  => $record->missingCount,
				'replaces' => $record->replacesGpuId > 0 ? substr($ids[$record->replacesGpuId], -2) : '',
				'pending'  => $record->pendingNodeId,
				'bus'      => $record->pciBusId,
			);
		}

		ksort($rows);

		$result = array(
			'created'         => $steps,
			'confirmed'       => $confirmed,
			'confirmed_twice' => $confirmedTwice,
			'duplicate_ok'    => $duplicate,
			'rows'            => $rows,
			'delete_owner'    => $nodes->delete($one->id),
			'delete_empty'    => $nodes->delete($three),
			'nodes_left'      => count($nodes->all()),
		);

		break;
	case 'uninstall_unbooted':
		// What core does on uninstall when gpu_boot() refuses: the PHP 8.0
		// fallback, then core drops whatever is still tracked.
		include_once($config['base_path'] . '/plugins/gpu/include/unbooted-uninstall.php');
		gpu_uninstall_unbooted();
		api_plugin_db_changes_remove('gpu');
		api_plugin_remove_hooks('gpu');
		api_plugin_remove_realms('gpu');
		db_execute("DELETE FROM plugin_config WHERE directory = 'gpu'");

		break;
	case 'repair_as_viewer':
		// A viewer opens a plugin page after the realm rows went missing.
		db_execute("DELETE FROM user_auth WHERE username = 'gpuviewer'");
		db_execute("INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('gpuviewer', '', 0, 'viewer', 'on')");
		$viewer = (int) db_fetch_insert_id();
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'gpu'");
		db_execute("DELETE FROM plugin_hooks WHERE name = 'gpu'");
		$_SESSION['sess_user_id'] = $viewer;
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '0')");
		include_once($config['base_path'] . '/plugins/gpu/setup.php');
		plugin_gpu_upgrade();
		$result = array('viewer_realms' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = ?', array($viewer)));
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '1')");

		break;
	case 'link_graph_template':
		// Another device template uses one GPU graph template.
		db_execute("INSERT INTO host_template (hash, name) VALUES ('', 'gpu-lifecycle-other')");
		db_execute_prepared("INSERT INTO host_template_graph (host_template_id, graph_template_id) SELECT ?, id FROM graph_templates WHERE name = 'GPU - Temperature'", array(db_fetch_insert_id()));

		break;
	case 'use_device_template':
		// A device of the GPU Node template.
		db_execute("INSERT INTO host (description, hostname, host_template_id) SELECT 'gpu-lifecycle-device', 'gpu-lifecycle.invalid', id FROM host_template WHERE name = 'GPU Node'");

		break;
	case 'edit_data_query_file':
		file_put_contents($config['base_path'] . '/resource/script_server/plugin_gpu.xml', "<!-- changed by an operator -->\n", FILE_APPEND);

		break;
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

$tables = array();

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_gpu\\_%' ORDER BY TABLE_NAME") as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

print json_encode(array(
	'result'  => $result,
	'output'  => $output,
	'plugin'  => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'gpu'"),
	'schema'  => db_fetch_cell("SELECT value FROM settings WHERE name = 'gpu_schema_version'"),
	'hooks'   => array_column(db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'gpu' ORDER BY hook"), 'status', 'hook'),
	'realms'  => array_column(db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'gpu' ORDER BY file"), 'display', 'file'),
	'admin'   => (int) db_fetch_cell("SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE pr.plugin = 'gpu' AND uar.user_id = 1"),
	'tracked' => array_column(db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'gpu' AND method = 'create' ORDER BY `table`"), 'table'),
	'tables'  => $tables,
	'observation_columns' => array_column(db_fetch_assoc("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_gpu_observation' ORDER BY ORDINAL_POSITION"), 'c'),
	// The shipped Cacti objects, by kind, so a test sees duplicates.
	'objects' => array(
		'data_query'      => (int) db_fetch_cell("SELECT COUNT(*) FROM snmp_query WHERE name = 'GPU - Devices'"),
		'data_template'   => (int) db_fetch_cell("SELECT COUNT(*) FROM data_template WHERE name = 'GPU - Metrics'"),
		'graph_templates' => (int) db_fetch_cell("SELECT COUNT(*) FROM graph_templates WHERE name LIKE 'GPU - %'"),
		'graph_items'     => (int) db_fetch_cell("SELECT COUNT(*) FROM graph_templates_item AS i INNER JOIN graph_templates AS t ON t.id = i.graph_template_id WHERE t.name LIKE 'GPU - %' AND i.local_graph_id = 0"),
		'device_template' => (int) db_fetch_cell("SELECT COUNT(*) FROM host_template WHERE name = 'GPU Node'"),
		'dq_graphs'       => (int) db_fetch_cell("SELECT COUNT(*) FROM snmp_query_graph AS g INNER JOIN snmp_query AS q ON q.id = g.snmp_query_id WHERE q.name = 'GPU - Devices'"),
		'dq_file'         => is_file($config['base_path'] . '/resource/script_server/plugin_gpu.xml'),
	),
	// What a template removal must never touch.
	'cacti' => array(
		'devices'      => (int) db_fetch_cell('SELECT COUNT(*) FROM host'),
		'data_sources' => (int) db_fetch_cell('SELECT COUNT(*) FROM data_local'),
		'graphs'       => (int) db_fetch_cell('SELECT COUNT(*) FROM graph_local'),
	),
), JSON_PRETTY_PRINT) . PHP_EOL;
