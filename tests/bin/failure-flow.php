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
 * Failure qualification (#52, REQ-FAIL-001 to REQ-FAIL-003), end to end
 * against the real snmpd of tests/bin/e2e.php, with its environment. Run
 * from the Cacti root:
 *
 *   php plugins/gpu/tests/bin/failure-flow.php
 *
 * Each scenario breaks one thing, collects, and runs Cacti's poller, then
 * checks that no value became zero, the GPU records and Cacti objects are
 * all still there, the RRD history is intact, and the health page names
 * the failure. Then it repairs the fault and checks that collection
 * recovers:
 *
 *   agent down        the device points at a port no agent listens on
 *   wrong credentials the device's SNMPv3 authentication passphrase is wrong
 *   tool timeout      the fake nvidia-smi outlasts timeout(1) in the extend line
 *   cut output        the last metrics line stops partway
 *   agent limit       more lines than snmpd keeps
 *   database lost     the collector's database connection is killed mid-run
 *   RRD not writable  Cacti cannot write the GPU's RRD file
 *
 * Must parse on PHP 8.0.
 */

require(__DIR__ . '/e2e-lib.php');

use Somethingwithproof\Gpu\ExtendOutput;
use Somethingwithproof\Gpu\ExtendReader;
use Somethingwithproof\Gpu\NodeDevice;

db_execute("REPLACE INTO settings (name, value) VALUES ('gpu_absent_after', '1')");

e2e_space(2);
list($e2e_host, $e2e_node_id) = e2e_node('gpu-node-fail');

$fail_metrics = e2e_fixture('r580-8gpu', 'metrics');
$fail_names   = e2e_fixture('r580-8gpu', 'names');

function fail_sources(): array {
	global $e2e_host;

	return array_column(db_fetch_assoc_prepared('SELECT id, snmp_index FROM data_local WHERE host_id = ? AND snmp_query_id = ? ORDER BY id', array($e2e_host, gpu_provisioner()->dataQueryId())), 'id', 'snmp_index');
}

/**
 * What a failure must leave alone: the GPU records and their presence,
 * the stored values, the data sources and graphs, and the RRD files.
 *
 * @return array<string, mixed>
 */
function fail_kept(): array {
	global $e2e_host, $e2e_node_id;

	$rrd = array();

	foreach (fail_sources() as $id) {
		$path       = get_data_source_path((int) $id, true);
		$rrd[$id]   = is_file($path) ? filesize($path) : -1;
	}

	return array(
		'gpus'    => db_fetch_assoc_prepared('SELECT id, uuid, presence FROM plugin_gpu_gpu WHERE node_id = ? ORDER BY id', array($e2e_node_id)),
		'graphs'  => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($e2e_host)),
		'sources' => fail_sources(),
		'rrd'     => $rrd,
	);
}

/**
 * The stored values of every GPU at the node: none may be zero where the
 * healthy sample was not, and none may go back in time.
 *
 * @return array<string, array<string, mixed>>
 */
function fail_values(): array {
	global $e2e_node_id;

	return array_column(db_fetch_assoc_prepared('SELECT g.uuid, o.observed_at, o.util_gpu, o.temp_gpu, o.power, o.mem_total FROM plugin_gpu_gpu AS g INNER JOIN plugin_gpu_observation AS o ON o.gpu_id = g.id WHERE g.node_id = ? ORDER BY g.uuid', array($e2e_node_id)), null, 'uuid');
}

function fail_health(): string {
	global $e2e_node_id;

	$page = new \Somethingwithproof\Gpu\HealthPage(gpu_nodes(), gpu_gpus(), new \Somethingwithproof\Gpu\CollectionRepository(false), new \Somethingwithproof\Gpu\ObservationRepository(false), function ($host) {
		return true;
	}, function ($host) {
		return null;
	}, new \Somethingwithproof\Gpu\SystemClock(), 300, gpu_settings()->staleAfter);

	return $page->collection((new \Somethingwithproof\Gpu\CollectionRepository(false))->find($e2e_node_id));
}

/**
 * The checks after a failed collection and a Cacti poll.
 *
 * @param array<string, mixed>                $kept   fail_kept() before the fault
 * @param array<string, array<string, mixed>> $values fail_values() before the fault
 */
function fail_check(string $scenario, string $state, string $outcome, array $kept, array $values): void {
	global $e2e_host, $e2e_node_id;

	$status = db_fetch_row_prepared('SELECT state, detail FROM plugin_gpu_collection WHERE node_id = ?', array($e2e_node_id));

	e2e_check(($status['state'] ?? '') === $state, "$scenario: the collection is recorded as $state (" . ($status['state'] ?? 'none') . ')');

	e2e_poll();

	$after = fail_values();
	$zeros = array();

	foreach ($values as $uuid => $row) {
		foreach (array('util_gpu', 'temp_gpu', 'power', 'mem_total') as $field) {
			if ((string) ($after[$uuid][$field] ?? '') !== (string) $row[$field] && (float) ($after[$uuid][$field] ?? 0) === 0.0) {
				$zeros[] = "$uuid $field";
			}
		}

		if ((int) ($after[$uuid]['observed_at'] ?? 0) < (int) $row['observed_at']) {
			$zeros[] = "$uuid went back in time";
		}
	}

	e2e_check($zeros === array(), "$scenario: no stored value became zero or older (" . implode(', ', $zeros) . ')');
	e2e_check(fail_kept() === $kept, "$scenario: every GPU record, data source, graph, and RRD file is kept, and no GPU is marked absent");
	e2e_check(e2e_ss($e2e_host, 'get', 'util_gpu', e2e_key(1)) === 'U' && is_numeric(e2e_ss($e2e_host, 'get', 'age', e2e_key(1))), "$scenario: the script server input prints U, never 0, while age keeps counting");

	$rrd = e2e_rrd((int) $kept['sources'][e2e_key(1)]);

	e2e_check(($rrd['util_gpu'] ?? '') !== '0' && ($rrd['temp_gpu'] ?? '') !== '0', "$scenario: Cacti wrote no zero to the RRD file (util_gpu " . ($rrd['util_gpu'] ?? '?') . ')');

	$health = fail_health();

	e2e_check(strpos($health, $outcome) === 0, "$scenario: the health page shows '$health'");
}

/**
 * Repair, collect, and check the node is healthy again.
 */
function fail_recover(string $scenario): void {
	global $e2e_node_id;

	e2e_collect();

	e2e_check(db_fetch_cell_prepared('SELECT state FROM plugin_gpu_collection WHERE node_id = ?', array($e2e_node_id)) === 'ok' && strpos(fail_health(), 'Completed') === 0, "$scenario: collection recovers once the fault is repaired");
}

/* A healthy node with history. */

e2e_tool($fail_metrics, $fail_names);
e2e_collect();
e2e_poll();
sleep(1);
e2e_poll();

$kept   = fail_kept();
$values = fail_values();

e2e_check(count($kept['gpus']) === 8 && count($kept['sources']) === 8 && min($kept['rrd']) > 0, 'setup: eight GPUs with data sources and RRD files');

/* The agent is down: nothing listens on the device's port. */

$fail_port = (int) db_fetch_cell_prepared('SELECT snmp_port FROM host WHERE id = ?', array($e2e_host));

db_execute_prepared('UPDATE host SET snmp_port = ? WHERE id = ?', array($fail_port + 1, $e2e_host));
e2e_collect();
fail_check('agent down', 'unreachable', 'Tool error: SNMP agent unreachable', $kept, $values);
db_execute_prepared('UPDATE host SET snmp_port = ? WHERE id = ?', array($fail_port, $e2e_host));
fail_recover('agent down');

/* Wrong SNMPv3 credentials. */

$fail_secret = (string) db_fetch_cell_prepared('SELECT snmp_password FROM host WHERE id = ?', array($e2e_host));

// net-snmp keeps an SNMPv3 user's keys for the life of a process, so this
// process would still authenticate with the old passphrase. The collector
// runs as a new process each poller cycle; so does this collection.
db_execute_prepared('UPDATE host SET snmp_password = ? WHERE id = ?', array('wrong-' . bin2hex(random_bytes(6)), $e2e_host));
e2e_wait_collector();
passthru(escapeshellarg(PHP_BINARY) . ' plugins/gpu/poller_gpu.php');
fail_check('wrong credentials', 'unreachable', 'Tool error: SNMP agent unreachable', $kept, $values);
db_execute_prepared('UPDATE host SET snmp_password = ? WHERE id = ?', array($fail_secret, $e2e_host));
fail_recover('wrong credentials');

/*
 * The tool hangs past timeout(1)'s 10 seconds. snmpd answers nothing while
 * the command runs, so the first read may time out at the SNMP layer; the
 * read after the command ends gets its cached exit status 124.
 */

file_put_contents($e2e_fixtures . '/current.delay', '15');
e2e_tool($fail_metrics, $fail_names);
e2e_collect();
sleep(16);
e2e_collect();
fail_check('tool timeout', 'timed_out', 'Timed out', $kept, $values);
file_put_contents($e2e_fixtures . '/current.delay', '');
e2e_tool($fail_metrics, $fail_names);
fail_recover('tool timeout');

/* The output stops partway through the last line. */

$fail_cut               = $fail_metrics;
$fail_cut[count($fail_cut) - 1] = substr($fail_cut[count($fail_cut) - 1], 0, 60);

e2e_tool($fail_cut, $fail_names);
e2e_collect();
fail_check('cut output', 'invalid_output', 'Tool error: Output rejected', $kept, $values);
e2e_tool($fail_metrics, $fail_names);
fail_recover('cut output');

/*
 * More lines than snmpd keeps: the plugin refuses output that may be cut.
 * The extra lines are short, so the output stays under snmpd's 100 KiB
 * buffer and only the line limit is exercised.
 */

e2e_tool(array_merge($fail_metrics, array_fill(0, 1100, 'x')), $fail_names);
e2e_collect();
fail_check('agent limit', 'truncated', 'Tool error: Output truncated', $kept, $values);
e2e_tool($fail_metrics, $fail_names);
fail_recover('agent limit');

/* Exit statuses of the documented commands: tool missing, permission denied, driver unloaded. */

foreach (array(127 => array('tool missing', 'tool_missing', 'Tool error: Tool not found'), 4 => array('permission denied', 'permission_denied', 'Permission denied'), 9 => array('driver unloaded', 'driver_not_loaded', 'Tool error: Driver not loaded')) as $fail_status => $fail_case) {
	e2e_tool($fail_metrics, $fail_names, $fail_status);
	e2e_collect();
	fail_check($fail_case[0], $fail_case[1], $fail_case[2], $kept, $values);
	e2e_tool($fail_metrics, $fail_names);
	fail_recover($fail_case[0]);
}

/*
 * Clock skew: the node's clock runs an hour ahead of Cacti's. The sample is
 * refused, so a real sample is stored again as soon as the clock is fixed.
 */

e2e_tool(str_replace('@NOW@', gmdate('Y/m/d H:i:s', time() + 3600) . '.000', $fail_metrics), $fail_names);
e2e_collect();
fail_check('clock skew', 'invalid_output', 'Tool error: Output rejected: The sample time is', $kept, $values);
e2e_tool($fail_metrics, $fail_names);
fail_recover('clock skew');

/* A second collector start while another run holds the node. */

$fail_other = db_connect_real($database_hostname, $database_username, $database_password, $database_default, 'mysql', $database_port, 1);
$fail_lock  = is_object($fail_other) ? (int) db_fetch_cell_prepared("SELECT GET_LOCK(SHA2(CONCAT(DATABASE(), '.plugin_gpu.', ?), 256), 0)", array('node_' . $e2e_node_id), '', true, $fail_other) : 0;
$fail_stats = e2e_collect();

e2e_check($fail_lock === 1 && $fail_stats['skipped'] === 1 && $fail_stats['ok'] === 0, 'duplicate start: a run that finds the node held skips it and counts the skip (' . json_encode($fail_stats) . ')');
e2e_check(fail_kept() === $kept && strpos(fail_health(), '1 later runs skipped') !== false, "duplicate start: nothing is changed, and the health page shows the skip ('" . fail_health() . "')");

if (is_object($fail_other)) {
	db_close($fail_other);
}

fail_recover('duplicate start');

/*
 * The database goes away mid-run: a reader that kills the collector's own
 * connection right after the agent answered, before anything is stored.
 */

final class FailKillingReader implements ExtendReader {
	/** @var ExtendReader */
	private $inner;

	/** @var bool */
	public $killed = false;

	public function __construct(ExtendReader $inner) {
		$this->inner = $inner;
	}

	public function read(NodeDevice $device, string $token): ExtendOutput {
		global $database_hostname, $database_username, $database_password, $database_default, $database_port;

		$out = $this->inner->read($device, $token);

		if (!$this->killed && $token === 'cacti_gpu') {
			$id    = (int) db_fetch_cell('SELECT CONNECTION_ID()');
			$other = db_connect_real($database_hostname, $database_username, $database_password, $database_default, 'mysql', $database_port, 1);

			if (is_object($other)) {
				db_execute('KILL CONNECTION ' . $id, false, $other);
				db_close($other);
				$this->killed = true;
			}
		}

		return $out;
	}
}

$fail_clock  = new \Somethingwithproof\Gpu\SystemClock();
$fail_reader = new FailKillingReader(new \Somethingwithproof\Gpu\SnmpExtendReader(0));
$fail_gpus   = gpu_gpus();
$fail_node   = gpu_nodes()->find($e2e_node_id);
$fail_dev    = $fail_node === null ? null : gpu_nodes()->device($fail_node);
$fail_state  = 'not run';

$fail_collector = new \Somethingwithproof\Gpu\Collector($fail_reader, new \Somethingwithproof\Gpu\NvidiaSmiParser(), new \Somethingwithproof\Gpu\Inventory($fail_gpus, new \Somethingwithproof\Gpu\IdentityReconciler(), $fail_clock), $fail_gpus, new \Somethingwithproof\Gpu\ObservationRepository(false), new \Somethingwithproof\Gpu\CollectionRepository(false), new \Somethingwithproof\Gpu\ReindexRepository(false), new \Somethingwithproof\Gpu\LockRepository(false), $fail_clock);

// The tool's sample must be newer than the stored one, or there is nothing
// the lost connection could have written.
e2e_tool($fail_metrics, $fail_names);

try {
	$fail_state = $fail_node !== null && $fail_dev !== null ? $fail_collector->collect($fail_node, $fail_dev, gpu_settings())->value : 'no node';
} catch (\Throwable $e) {
	$fail_state = 'threw ' . get_class($e);
}

e2e_check($fail_reader->killed, "database lost: the collector's connection was killed mid-run (collect returned $fail_state)");

$after = fail_values();
$zeros = 0;

foreach ($after as $row) {
	$zeros += (float) $row['util_gpu'] === 0.0 || (float) $row['mem_total'] === 0.0 ? 1 : 0;
}

e2e_check($zeros === 0 && count($after) === 8 && fail_kept() === $kept, 'database lost: every stored value is a real sample, and every record is kept');
e2e_check(strpos(fail_health(), 'Completed') === 0 || strpos(fail_health(), 'Unknown') === 0 || strpos(fail_health(), 'Tool error: Result not stored') === 0, "database lost: the health page shows '" . fail_health() . "'");
fail_recover('database lost');

/* Cacti cannot write the RRD file. */

$fail_source = (int) $kept['sources'][e2e_key(1)];
$fail_path   = get_data_source_path($fail_source, true);
$fail_before = e2e_rrd($fail_source);
$fail_root   = function_exists('posix_geteuid') && posix_geteuid() === 0;

// root writes through any mode, so as root the file is moved aside and a
// directory takes its name; otherwise the file is made read-only.
if ($fail_root) {
	rename($fail_path, $fail_path . '.aside');
	mkdir($fail_path);
} else {
	chmod($fail_path, 0444);
}

e2e_tool($fail_metrics, $fail_names);
e2e_collect();
sleep(1);
e2e_poll();

e2e_check(db_fetch_cell_prepared('SELECT state FROM plugin_gpu_collection WHERE node_id = ?', array($e2e_node_id)) === 'ok' && strpos(fail_health(), 'Completed') === 0, 'RRD not writable: collection is unaffected, so the health page shows it completed; Cacti logs the write error');

if ($fail_root) {
	rmdir($fail_path);
	rename($fail_path . '.aside', $fail_path);
} else {
	chmod($fail_path, 0644);
}

$fail_after = e2e_rrd($fail_source);

e2e_check(($fail_after['time'] ?? '') === ($fail_before['time'] ?? 'x') && fail_kept() === $kept, 'RRD not writable: the RRD file and its history are unchanged (' . ($fail_root ? 'directory in its place' : 'read-only file') . ')');

sleep(1);
e2e_poll();

$fail_after = e2e_rrd($fail_source);

e2e_check((int) ($fail_after['time'] ?? 0) > (int) ($fail_before['time'] ?? 0) && ($fail_after['util_gpu'] ?? '') === '30', 'RRD not writable: once writable, Cacti writes the GPU values again');

print ($e2e_failures === 0 ? 'failure flow ok' : "failure flow: $e2e_failures failed") . PHP_EOL;

exit($e2e_failures === 0 ? 0 : 1);
