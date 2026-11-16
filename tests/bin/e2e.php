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
 * End-to-end collection test against a real snmpd. Run from the Cacti root
 * once the plugin is installed and enabled (tests/bin/php-guard-check.php):
 *
 *   php plugins/gpu/tests/bin/e2e.php
 *
 * The agent is net-snmp's snmpd, configured by tests/bin/snmpd-conf.php
 * from the extend lines in docs/node-setup.md, with tests/bin/fake-nvidia-
 * smi installed as /usr/bin/nvidia-smi. Nothing in the SNMP path is
 * stubbed: the plugin reads the agent over SNMPv3 with Cacti's own SNMP
 * functions, and Cacti's poller reads the plugin's script server input and
 * writes the RRD files.
 *
 * Environment: GPU_TEST_DATABASE=disposable; GPU_E2E_AGENT, the agent's
 * host or host:port; GPU_E2E_SECRETS, the file snmpd-conf.php read; and
 * GPU_E2E_FIXTURES, the directory the fake tool reads as /var/lib/gpu-e2e.
 *
 * snmpd caches each extend result for its -cacheTime, 30 seconds for the
 * metrics, so each step that changes the tool's output waits that long.
 */

require(__DIR__ . '/e2e-lib.php');

use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\TemplateXml;

$e2e_per_gpu = count(TemplateXml::graphs());

/**
 * @return array<string, mixed>
 */
function e2e_state(int $host): array {
	global $e2e_node_id;

	$query = gpu_provisioner()->dataQueryId();

	return array(
		'collection' => db_fetch_row_prepared('SELECT * FROM plugin_gpu_collection WHERE node_id = ?', array($e2e_node_id)),
		'presence'   => array_column(db_fetch_assoc_prepared('SELECT uuid, presence FROM plugin_gpu_gpu WHERE node_id = ? ORDER BY uuid', array($e2e_node_id)), 'presence', 'uuid'),
		'cached'     => array_column(db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ? ORDER BY snmp_index', array($host, $query)), 'snmp_index'),
		'graphs'     => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ? AND snmp_query_id = ?', array($host, $query)),
		'sources'    => array_column(db_fetch_assoc_prepared('SELECT id, snmp_index FROM data_local WHERE host_id = ? AND snmp_query_id = ? ORDER BY id', array($host, $query)), 'id', 'snmp_index'),
		'reindex'    => db_fetch_row_prepared('SELECT * FROM plugin_gpu_reindex WHERE node_id = ?', array($e2e_node_id)),
	);
}

/* Setup: an SNMPv3 device with the GPU Node template, mapped to a node. */

db_execute("REPLACE INTO settings (name, value) VALUES ('gpu_absent_after', '1')");

list($e2e_host, $e2e_node_id) = e2e_node('gpu-node-e2e');

/* Boot: eight GPUs. */

$e2e_metrics = e2e_fixture('r580-8gpu', 'metrics');
$e2e_names   = e2e_fixture('r580-8gpu', 'names');

e2e_tool($e2e_metrics, $e2e_names);

$e2e_stats = e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check($e2e_stats['ok'] === 1 && $e2e_state['collection']['state'] === 'ok' && (int) $e2e_state['collection']['gpus'] === 8, 'boot: the collection read eight GPUs over SNMPv3');
e2e_check(count($e2e_state['presence']) === 8 && array_unique($e2e_state['presence']) === array('GPU-00000000-0000-4000-8000-000000000001' => 'present'), 'boot: eight GPU records, all present');
e2e_check($e2e_stats['reindexed'] === 1 && count($e2e_state['cached']) === 8 && $e2e_state['reindex'] === array(), 'boot: the reindex listed the eight identity keys and its request was cleared');
e2e_check($e2e_state['graphs'] === 8 * $e2e_per_gpu && count($e2e_state['sources']) === 8, "boot: $e2e_per_gpu graphs per GPU, sharing one data source each");
e2e_check(e2e_ss($e2e_host, 'get', 'util_gpu', e2e_key(1)) === '30' && e2e_ss($e2e_host, 'get', 'mem_used', e2e_key(1)) === (string) (2048 * 1048576), 'boot: the script server input prints the cached values in model units');

// Cacti creates a new data source's RRD file in the first cycle that polls
// it and writes values from the next.
e2e_poll();
e2e_poll();

$e2e_rrd   = e2e_rrd((int) $e2e_state['sources'][e2e_key(2)]);
$e2e_first = $e2e_rrd;

e2e_check(($e2e_rrd['util_gpu'] ?? '') === '31' && ($e2e_rrd['thr_sw_power_cap'] ?? '') === '1' && ($e2e_rrd['thr_hw_slowdown'] ?? '') === '0' && ($e2e_rrd['mem_total'] ?? '') === (string) (81559 * 1048576) && is_numeric($e2e_rrd['age'] ?? ''), 'boot: the Cacti poller wrote the GPU values to its RRD file');
e2e_check(count($e2e_rrd) - 1 === count(MetricModel::names()), 'boot: the RRD file holds every model field');

if (count($e2e_rrd) - 1 !== count(MetricModel::names())) {
	print '  RRD fields: ' . implode(' ', array_keys($e2e_rrd)) . PHP_EOL;
}

foreach (TemplateXml::graphs() as $e2e_graph_name => $e2e_graph) {
	$e2e_graph_id = (int) db_fetch_cell_prepared('SELECT gl.id FROM graph_local AS gl INNER JOIN graph_templates AS gt ON gt.id = gl.graph_template_id WHERE gl.host_id = ? AND gl.snmp_index = ? AND gt.name = ?', array($e2e_host, e2e_key(2), $e2e_graph_name));
	$e2e_meta     = array();
	$e2e_image    = rrdtool_function_graph($e2e_graph_id, 0, array('graph_start' => -3600, 'graph_end' => time()), false, $e2e_meta, 1);

	e2e_check(is_string($e2e_image) && e2e_image($e2e_image), "boot: '$e2e_graph_name' renders from the RRD file");

	if (!e2e_image($e2e_image)) {
		print '  output: ' . substr(preg_replace('/[^\x20-\x7E]/', '.', is_string($e2e_image) ? $e2e_image : var_export($e2e_image, true)), 0, 200) . PHP_EOL;
	}
}

e2e_check(e2e_title($e2e_host, e2e_key(1), 'GPU - Utilization') === 'gpu-node-e2e - GPU 0 NVIDIA H100 80GB HBM3 - Utilization', 'boot: the graph title names the GPU by index and name');

/* A tool failure stores nothing and marks no GPU absent. */

e2e_tool($e2e_metrics, $e2e_names, 9);
e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check($e2e_state['collection']['state'] === 'driver_not_loaded', 'tool failure: recorded as driver not loaded');
e2e_check(array_unique($e2e_state['presence']) === array('GPU-00000000-0000-4000-8000-000000000001' => 'present'), 'tool failure: every GPU stays present');
e2e_check(e2e_ss($e2e_host, 'get', 'util_gpu', e2e_key(1)) === 'U' && is_numeric(e2e_ss($e2e_host, 'get', 'age', e2e_key(1))), 'tool failure: values print U while age keeps counting');

/* Empty output succeeds and marks no GPU absent. */

e2e_tool(array(), array());
e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check($e2e_state['collection']['state'] === 'empty', 'empty output: recorded as an empty collection');
e2e_check(array_unique($e2e_state['presence']) === array('GPU-00000000-0000-4000-8000-000000000001' => 'present'), 'empty output: every GPU stays present');
e2e_check(e2e_ss($e2e_host, 'get', 'util_gpu', e2e_key(1)) === 'U', 'empty output: values print U');

/* Remove GPU 8, then return it: one data source and RRD file throughout. */

$e2e_source8 = (int) $e2e_state['sources'][e2e_key(8)];

e2e_tool(array_slice($e2e_metrics, 0, 7), array_slice($e2e_names, 0, 7));
e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check($e2e_state['presence']['GPU-00000000-0000-4000-8000-000000000008'] === 'absent', 'remove: the missing GPU is marked absent');
e2e_check(in_array(e2e_key(8), explode("\n", e2e_ss($e2e_host, 'index')), true) && in_array(e2e_key(8), $e2e_state['cached'], true), 'remove: the absent GPU stays an index of the data query');
e2e_check(e2e_ss($e2e_host, 'get', 'util_gpu', e2e_key(8)) === 'U' && (int) $e2e_state['sources'][e2e_key(8)] === $e2e_source8, 'remove: it prints U and keeps its data source');

$e2e_graph8 = (int) db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id = ? AND snmp_index = ? LIMIT 1', array($e2e_host, e2e_key(8)));
$e2e_meta   = array();
$e2e_image  = rrdtool_function_graph($e2e_graph8, 0, array('graph_start' => -3600, 'graph_end' => time()), false, $e2e_meta, 1);

e2e_check(is_string($e2e_image) && e2e_image($e2e_image), 'remove: the absent GPU\'s graph still renders from its RRD file');

e2e_tool($e2e_metrics, $e2e_names);
e2e_collect();
e2e_poll();
$e2e_state = e2e_state($e2e_host);
$e2e_rrd   = e2e_rrd($e2e_source8);

e2e_check($e2e_state['presence']['GPU-00000000-0000-4000-8000-000000000008'] === 'present' && (int) $e2e_state['sources'][e2e_key(8)] === $e2e_source8, 're-add: the GPU is present again under the same data source');
e2e_check(($e2e_rrd['util_gpu'] ?? '') === '37' && (int) ($e2e_rrd['time'] ?? 0) > (int) $e2e_first['time'], 're-add: history continues in the same RRD file');

/* A replacement card: same slot, new UUID. */

$e2e_replaced    = $e2e_metrics;
$e2e_replaced[7] = str_replace('GPU-00000000-0000-4000-8000-000000000008', 'GPU-00000000-0000-4000-8000-000000000099', $e2e_replaced[7]);
$e2e_names2      = $e2e_names;
$e2e_names2[7]   = str_replace('GPU-00000000-0000-4000-8000-000000000008', 'GPU-00000000-0000-4000-8000-000000000099', $e2e_names2[7]);

e2e_tool($e2e_replaced, $e2e_names2);
e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check(($e2e_state['presence']['GPU-00000000-0000-4000-8000-000000000099'] ?? '') === 'present' && $e2e_state['presence']['GPU-00000000-0000-4000-8000-000000000008'] === 'absent', 'replacement: a new record for the new card, the old one absent');
e2e_check(isset($e2e_state['sources'][e2e_key(0x99)]) && (int) $e2e_state['sources'][e2e_key(0x99)] !== $e2e_source8, 'replacement: the new card has its own data source');
e2e_check((int) $e2e_state['sources'][e2e_key(8)] === $e2e_source8 && is_file(get_data_source_path($e2e_source8, true)), 'replacement: the old card\'s data source and RRD file are intact');
e2e_check(count($e2e_state['cached']) === 9 && $e2e_state['graphs'] === 9 * $e2e_per_gpu, 'replacement: nine indexes, and graphs for the new card');

/* A reindex Cacti skips while the device is down runs once it is up. */

$e2e_swapped = $e2e_replaced;
$e2e_swapped[0] = preg_replace('/^([^,]*, [^,]*, )0,/', '${1}1,', $e2e_swapped[0]);
$e2e_swapped[1] = preg_replace('/^([^,]*, [^,]*, )1,/', '${1}0,', $e2e_swapped[1]);

db_execute_prepared('UPDATE host SET status = 1 WHERE id = ?', array($e2e_host));

e2e_tool($e2e_swapped, $e2e_names2);
$e2e_stats = e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check($e2e_stats['deferred'] === 1 && (int) ($e2e_state['reindex']['attempts'] ?? 0) === 1, 'down: the reindex request is kept and its attempt recorded');
e2e_check(e2e_title($e2e_host, e2e_key(1), 'GPU - Utilization') === 'gpu-node-e2e - GPU 0 NVIDIA H100 80GB HBM3 - Utilization', 'down: titles are unchanged while Cacti will not reindex');

db_execute_prepared('UPDATE host SET status = 3 WHERE id = ?', array($e2e_host));

$e2e_stats = e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check($e2e_stats['reindexed'] === 1 && $e2e_state['reindex'] === array(), 'up: the kept reindex ran and its request was cleared');
e2e_check(e2e_title($e2e_host, e2e_key(1), 'GPU - Utilization') === 'gpu-node-e2e - GPU 1 NVIDIA H100 80GB HBM3 - Utilization', 'up: the new index is in the graph title');

/* Sixteen GPUs on a new driver line. */

e2e_tool(e2e_fixture('r620-16gpu', 'metrics'), e2e_fixture('r620-16gpu', 'names'));
e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check((int) $e2e_state['collection']['gpus'] === 16 && count(array_keys($e2e_state['presence'], 'present', true)) === 16, 'sixteen GPUs: all recorded present');
e2e_check(count($e2e_state['cached']) === 17 && $e2e_state['graphs'] === 17 * $e2e_per_gpu, 'sixteen GPUs: seventeen indexes (the replaced card stays) and graphs for each present GPU');

/* One GPU on a newer driver that reports some fields as [N/A]. */

e2e_tool(e2e_fixture('r595-1gpu', 'metrics'), e2e_fixture('r595-1gpu', 'names'));
e2e_collect();
$e2e_state = e2e_state($e2e_host);

e2e_check($e2e_state['collection']['state'] === 'ok' && (int) $e2e_state['collection']['gpus'] === 1 && count(array_keys($e2e_state['presence'], 'present', true)) === 1, 'one GPU: collected, and the other records marked absent');
e2e_check(e2e_ss($e2e_host, 'get', 'power', e2e_key(1)) === 'U' && e2e_ss($e2e_host, 'get', 'util_gpu', e2e_key(1)) === '30', 'one GPU: an [N/A] field prints U, the rest print values');
e2e_check(count($e2e_state['cached']) === 17, 'one GPU: absent GPUs stay indexes');

/* REQ-SS-005: under 50 ms per call with 10,000 GPUs in the cache. */

$e2e_now = time();

for ($n = 0; $n < 625; $n++) {
	db_execute_prepared("INSERT INTO plugin_gpu_node (host_id, admin_state) VALUES (?, 'enabled')", array(900000 + $n));
	$e2e_seed_node = (int) db_fetch_insert_id();

	for ($g = 0; $g < 16; $g++) {
		$e2e_uuid = sprintf('GPU-%08x-0000-4000-8000-%012x', 0x10000 + $n, $g);
		db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, gpu_index, pci_bus_id, name, presence) VALUES ('nvidia', ?, ?, ?, ?, 'seed', 'present')", array($e2e_uuid, $e2e_seed_node, $g, sprintf('00000000:%02X:00.0', $g)));
		db_execute_prepared('INSERT INTO plugin_gpu_observation (gpu_id, node_id, observed_at, reported_at, util_gpu) VALUES (?, ?, ?, ?, 50)', array((int) db_fetch_insert_id(), $e2e_seed_node, $e2e_now, $e2e_now));
	}

	db_execute_prepared("INSERT INTO plugin_gpu_collection (node_id, state, finished_at) VALUES (?, 'ok', ?)", array($e2e_seed_node, $e2e_now));
}

e2e_check((int) db_fetch_cell('SELECT COUNT(*) FROM plugin_gpu_gpu') >= 10000, 'timing: 10,000 GPUs in the cache');

$e2e_slowest = 0.0;

foreach (array(array('get', 'util_gpu', e2e_key(3)), array('get', 'age', e2e_key(3)), array('index', '', ''), array('num_indexes', '', ''), array('query', 'util_gpu', ''), array('query', 'name', '')) as $e2e_call) {
	for ($i = 0; $i < 50; $i++) {
		$e2e_start   = microtime(true);
		e2e_ss($e2e_host, $e2e_call[0], $e2e_call[1], $e2e_call[2]);
		$e2e_slowest = max($e2e_slowest, microtime(true) - $e2e_start);
	}
}

e2e_check($e2e_slowest < 0.05, sprintf('timing: the slowest of 300 script server calls took %.1f ms', $e2e_slowest * 1000));

db_execute('DELETE o FROM plugin_gpu_observation AS o INNER JOIN plugin_gpu_node AS n ON n.id = o.node_id WHERE n.host_id >= 900000');
db_execute('DELETE c FROM plugin_gpu_collection AS c INNER JOIN plugin_gpu_node AS n ON n.id = c.node_id WHERE n.host_id >= 900000');
db_execute('DELETE g FROM plugin_gpu_gpu AS g INNER JOIN plugin_gpu_node AS n ON n.id = g.node_id WHERE n.host_id >= 900000');
db_execute('DELETE FROM plugin_gpu_node WHERE host_id >= 900000');

print ($e2e_failures === 0 ? 'e2e ok' : "e2e: $e2e_failures failed") . PHP_EOL;

exit($e2e_failures === 0 ? 0 : 1);
