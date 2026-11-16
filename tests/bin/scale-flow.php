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
 * Scale measurement (#49, REQ-PERF-001 to REQ-PERF-004). Run from the Cacti
 * root with the agent and environment of tests/bin/e2e.php:
 *
 *   php plugins/gpu/tests/bin/scale-flow.php <gpus> [provision]
 *
 * Registers <gpus> / 8 nodes, rounded up, every one a Cacti device on the
 * same real snmpd with the 8-GPU fixture behind the fake nvidia-smi. A test
 * reader wraps the plugin's SNMP extend reader and rewrites each UUID with
 * the device id, so every node reports GPUs of its own, and the last node
 * reports only the GPUs that make up the count. Nothing else is changed,
 * and every read is a real SNMPv3 exchange with snmpd.
 *
 * It measures, and prints as SCALE lines:
 *
 *   - two collection rounds over every node (the first records the GPUs,
 *     the second is steady state), in wall time and peak memory;
 *   - the script server input: one index listing per node, and one get
 *     per data source item, the call Cacti's poller makes for each;
 *   - with "provision", Cacti objects for every node through the plugin's
 *     provisioner, one Cacti poller cycle (its SYSTEM STATS line), a time
 *     budget that stops a run early, and the row counts;
 *   - a GPU Health page render, which must not read the agent.
 *
 * Numbers depend on the machine; docs/performance.md records them with
 * the machine they were taken on. Disposable databases only. Must parse on
 * PHP 8.0.
 */

require(__DIR__ . '/e2e-lib.php');

use Somethingwithproof\Gpu\ExtendOutput;
use Somethingwithproof\Gpu\ExtendReader;
use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\NodeDevice;

$scale_gpus      = (int) ($argv[1] ?? 0);
$scale_provision = ($argv[2] ?? '') === 'provision';

if ($scale_gpus < 1) {
	fwrite(STDERR, "Pass the GPU count\n");
	exit(2);
}

/**
 * The plugin's reader, with each UUID made unique to the device it was
 * read from (only the UUID's last 12 digits change), and the output cut to
 * the device's GPU count, one line per GPU.
 */
final class ScaleReader implements ExtendReader {
	/** @var ExtendReader */
	private $inner;

	/** @var array<int, int> device id => GPUs */
	private $counts;

	public function __construct(ExtendReader $inner, array $counts) {
		$this->inner  = $inner;
		$this->counts = $counts;
	}

	public function read(NodeDevice $device, string $token): ExtendOutput {
		$out   = $this->inner->read($device, $token);
		$lines = array();

		foreach (array_slice($out->lines, 0, $this->counts[$device->id] ?? 8) as $line) {
			$lines[] = (string) preg_replace_callback('/GPU-00000000-0000-4000-8000-0000000000(\d\d)/', function ($m) use ($device) {
				return sprintf('GPU-00000000-0000-4000-8000-%06x%06x', $device->id, (int) $m[1]);
			}, $line);
		}

		return new ExtendOutput($out->result, count($out->lines) === $out->declaredLines ? count($lines) : $out->declaredLines, $lines);
	}
}

/**
 * A clock that moves one second forward on every read, so the budget check
 * stops after a known number of nodes on any machine.
 */
final class ScaleClock implements \Somethingwithproof\Gpu\Clock {
	/** @var int */
	private $time;

	public function __construct(int $time) {
		$this->time = $time;
	}

	public function now(): int {
		return $this->time++;
	}
}

/**
 * Hold every node's collection lock on a connection of the test's own, so
 * a collector that Cacti's poller_bottom starts, which reads the agent
 * without the test's UUID rewriting, skips the synthetic nodes instead of
 * changing their inventory. Dropping the connection releases the locks.
 *
 * @param list<\Somethingwithproof\Gpu\Node> $nodes
 */
function scale_hold(array $nodes): PDO {
	global $database_hostname, $database_username, $database_password, $database_default, $database_port;

	$pdo  = new PDO('mysql:host=' . $database_hostname . ';port=' . $database_port . ';dbname=' . $database_default, $database_username, $database_password);
	$lock = $pdo->prepare("SELECT GET_LOCK(SHA2(CONCAT(DATABASE(), '.plugin_gpu.', ?), 256), 0)");
	$held = 0;

	foreach ($nodes as $node) {
		$held += $lock->execute(array('node_' . $node->id)) && (int) $lock->fetchColumn() === 1 ? 1 : 0;
	}

	e2e_check($held === count($nodes), "hold: every synthetic node is held while Cacti polls ($held of " . count($nodes) . ')');

	return $pdo;
}

/**
 * The synthetic GPU population: every GPU of the scale nodes, present.
 */
function scale_present(array $hosts): int {
	return (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_gpu_gpu AS g INNER JOIN plugin_gpu_node AS n ON n.id = g.node_id WHERE g.presence = 'present' AND n.host_id IN (" . implode(', ', array_map('intval', $hosts)) . ')');
}

function scale_report(string $what, array $values): void {
	$parts = array();

	foreach ($values as $name => $value) {
		$parts[] = $name . '=' . (is_float($value) ? sprintf('%.3f', $value) : $value);
	}

	print 'SCALE ' . $what . ' ' . implode(' ', $parts) . PHP_EOL;
}

function scale_collector(array $counts): \Somethingwithproof\Gpu\Collector {
	$clock = new \Somethingwithproof\Gpu\SystemClock();
	$gpus  = gpu_gpus();

	return new \Somethingwithproof\Gpu\Collector(
		new ScaleReader(new \Somethingwithproof\Gpu\SnmpExtendReader(0), $counts),
		new \Somethingwithproof\Gpu\NvidiaSmiParser(),
		new \Somethingwithproof\Gpu\Inventory($gpus, new \Somethingwithproof\Gpu\IdentityReconciler(), $clock),
		$gpus,
		new \Somethingwithproof\Gpu\ObservationRepository(false),
		new \Somethingwithproof\Gpu\CollectionRepository(false),
		new \Somethingwithproof\Gpu\ReindexRepository(false),
		new \Somethingwithproof\Gpu\LockRepository(false),
		$clock,
	);
}

/* Nodes: one device through api_device_save(), then copies of its row. */

$scale_nodes = intdiv($scale_gpus + 7, 8);

list($scale_first, $scale_first_node) = e2e_node('gpu-scale-0001');

e2e_tool(e2e_fixture('r580-8gpu', 'metrics'), e2e_fixture('r580-8gpu', 'names'));

$scale_columns = array_values(array_diff(array_column(db_fetch_assoc('SHOW COLUMNS FROM host'), 'Field'), array('id', 'description')));
$scale_hosts   = array($scale_first);

for ($i = 2; $i <= $scale_nodes; $i++) {
	db_execute_prepared('INSERT INTO host (description, ' . implode(', ', $scale_columns) . ') SELECT ?, ' . implode(', ', $scale_columns) . ' FROM host WHERE id = ?', array(sprintf('gpu-scale-%04d', $i), $scale_first));
	$scale_host = (int) db_fetch_insert_id();
	db_execute_prepared('INSERT INTO host_snmp_query (host_id, snmp_query_id, sort_field, title_format, reindex_method) SELECT ?, snmp_query_id, sort_field, title_format, reindex_method FROM host_snmp_query WHERE host_id = ?', array($scale_host, $scale_first));
	gpu_nodes()->save(0, $scale_host, \Somethingwithproof\Gpu\AdminState::Enabled);
	$scale_hosts[] = $scale_host;
}

$scale_all = array();

foreach (gpu_nodes()->all() as $node) {
	if (in_array($node->hostId, $scale_hosts, true)) {
		$scale_all[] = $node;
	}
}

e2e_check(count($scale_all) === $scale_nodes, "setup: $scale_nodes nodes on one agent");

/* Collection: two rounds over every node. */

$scale_counts = array_fill_keys($scale_hosts, 8);
$scale_counts[end($scale_hosts)] = $scale_gpus - 8 * ($scale_nodes - 1);

$scale_collector = scale_collector($scale_counts);
$scale_settings  = gpu_settings();

foreach (array('first', 'steady') as $round) {
	$ok      = 0;
	$started = microtime(true);
	memory_reset_peak_usage();

	foreach ($scale_all as $node) {
		$device = gpu_nodes()->device($node);
		$ok    += $device !== null && $scale_collector->collect($node, $device, $scale_settings)->succeeded() ? 1 : 0;
	}

	$seconds = microtime(true) - $started;

	e2e_check($ok === $scale_nodes, "collect $round: every node collected ($ok of $scale_nodes)");
	scale_report('collect', array('round' => $round, 'gpus' => $scale_gpus, 'nodes' => $scale_nodes, 'seconds' => $seconds, 'ms_per_node' => 1000 * $seconds / $scale_nodes, 'peak_mb' => memory_get_peak_usage(true) / 1048576));
}

e2e_check(scale_present($scale_hosts) === $scale_gpus, "collect: $scale_gpus GPU records, all present");

/* Script server input: what Cacti's poller asks of it each cycle. */

$scale_ss     = gpu_script_server();
$scale_fields = MetricModel::names();
$scale_keys   = array();

$started = microtime(true);

foreach ($scale_hosts as $host) {
	$scale_keys[$host] = array_filter(explode("\n", $scale_ss->call((string) $host, 'index')));
}

$scale_index = microtime(true) - $started;
$scale_calls = 0;
$scale_u     = 0;
$started     = microtime(true);

foreach ($scale_keys as $host => $keys) {
	foreach ($keys as $key) {
		foreach ($scale_fields as $field) {
			$scale_u += $scale_ss->call((string) $host, 'get', $field, $key) === 'U' ? 1 : 0;
			$scale_calls++;
		}
	}
}

$scale_get = microtime(true) - $started;

e2e_check($scale_calls === $scale_gpus * count($scale_fields), "script server: one get per GPU and data source item ($scale_calls)");
scale_report('script_server', array('gpus' => $scale_gpus, 'index_calls' => count($scale_hosts), 'index_seconds' => $scale_index, 'get_calls' => $scale_calls, 'get_seconds' => $scale_get, 'ms_per_get' => 1000 * $scale_get / max(1, $scale_calls), 'unknown' => $scale_u));

/* Cacti objects, one poller cycle, and the collector's time budget. */

if ($scale_provision) {
	$started = microtime(true);
	$graphs  = 0;

	foreach ($scale_all as $node) {
		$device = gpu_nodes()->device($node);
		$done   = $device === null ? array('graphs' => 0) : gpu_provisioner()->provision($node, $device, (new \Somethingwithproof\Gpu\ReindexRepository(false))->pending()[$node->id] ?? null);
		$graphs += $done['graphs'];
	}

	$seconds = microtime(true) - $started;
	$per_gpu = count(\Somethingwithproof\Gpu\TemplateXml::graphs());

	e2e_check($graphs === $scale_gpus * $per_gpu, "provision: $per_gpu graphs per GPU ($graphs)");
	scale_report('provision', array('gpus' => $scale_gpus, 'graphs' => $graphs, 'seconds' => $seconds));

	// Cacti creates the RRD files in the first cycle and writes values from
	// the second; the second cycle is the steady cost. The collector the
	// poller starts finds every synthetic node held and leaves it alone.
	e2e_wait_collector();
	$scale_held = scale_hold($scale_all);
	e2e_poll();
	sleep(1);
	db_execute("DELETE FROM settings WHERE name = 'stats_poller'");
	e2e_poll();
	sleep(2);
	e2e_wait_collector();
	$scale_held = null;

	$stats = (string) db_fetch_cell("SELECT value FROM settings WHERE name = 'stats_poller'");

	e2e_check($stats !== '', 'poller: Cacti recorded the cycle');
	scale_report('poller', array('gpus' => $scale_gpus, 'stats' => str_replace(' ', ',', $stats)));

	// A run that hits its budget stops and leaves the rest, which go first
	// next time (REQ-PERF-003). The clock moves a second per node, so a
	// 3-second budget reaches exactly two nodes on any machine; the run uses
	// the test's reader, like the collection rounds.
	$scale_enabled = count(array_filter(gpu_nodes()->all(), function ($node) {
		return $node->enabled() && $node->deviceExists;
	}));
	$scale_budget  = new \Somethingwithproof\Gpu\Poller(gpu_nodes(), new \Somethingwithproof\Gpu\CollectionRepository(false), $scale_collector, gpu_provisioner(), new \Somethingwithproof\Gpu\ReindexRepository(false), new ScaleClock(time()));
	$stats         = $scale_budget->run($scale_settings, 3);

	$scale_processed = min(2, $scale_enabled);
	$scale_left = $scale_enabled - $scale_processed;
	e2e_check($stats['stopped_early'] === ($scale_left > 0) && $stats['nodes'] + $stats['skipped'] + $stats['remote'] === $scale_processed && $stats['left'] === $scale_left, 'budget: processed ' . $scale_processed . ' nodes and left ' . $scale_left . ' (' . json_encode($stats) . ')');

	$rows = array();

	foreach (array('host', 'plugin_gpu_gpu', 'plugin_gpu_observation', 'data_local', 'graph_local', 'poller_item') as $table) {
		$rows[$table] = (int) db_fetch_cell('SELECT COUNT(*) FROM ' . $table);
	}

	scale_report('rows', $rows);
}

// The poller cycles and the budget run must not have changed the synthetic
// inventory that the numbers below describe.
e2e_check(scale_present($scale_hosts) === $scale_gpus, "population: still $scale_gpus GPU records, all present, after the poller cycles");

/* The health page reads the database only: the agent sees no request. */

$scale_device = gpu_nodes()->device($scale_all[0]);
$scale_reader = new \Somethingwithproof\Gpu\SnmpExtendReader(0);
$scale_pkts   = function () use ($scale_reader, $scale_device) {
	return (int) $scale_reader->value($scale_device, '.1.3.6.1.2.1.11.1.0', 1000);
};

// Nothing else may talk to the agent while the page renders: a collector
// that poller_bottom started may still be on its last nodes.
e2e_wait_collector();
e2e_wait_thold();
sleep(2);
e2e_wait_collector();

// Two reads with nothing between them give the cost of the reads alone.
$a = $scale_pkts();
$b = $scale_pkts();

$page = new \Somethingwithproof\Gpu\HealthPage(gpu_nodes(), gpu_gpus(), new \Somethingwithproof\Gpu\CollectionRepository(false), new \Somethingwithproof\Gpu\ObservationRepository(false), function ($host) {
	return true;
}, function ($host) {
	return null;
}, new \Somethingwithproof\Gpu\SystemClock(), 300, $scale_settings->staleAfter);

$started = microtime(true);
ob_start();
$page->render();
$html    = (string) ob_get_clean();
$seconds = microtime(true) - $started;
$c       = $scale_pkts();

e2e_check($a > 0 && $c - $b === $b - $a && strpos($html, 'gpu-scale-0001') !== false, "health page: rendered $scale_nodes nodes without one request to the agent (snmpInPkts $a, $b, $c)");
scale_report('health_page', array('gpus' => $scale_gpus, 'seconds' => $seconds, 'bytes' => strlen($html)));

print ($e2e_failures === 0 ? 'scale ok' : "scale: $e2e_failures failed") . PHP_EOL;

exit($e2e_failures === 0 ? 0 : 1);
