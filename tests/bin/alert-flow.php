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
 * Thold alerting and Maint suppression on the GPU data source, end to end
 * (#46, REQ-ALERT-001 to REQ-ALERT-005), with Thold v1.8.2 and Maint v1.3
 * installed unchanged at plugins/thold and plugins/maint. Run from the
 * Cacti root once the plugin, Thold, and Maint are installed and enabled,
 * with the same agent and environment as tests/bin/e2e.php:
 *
 *   php plugins/gpu/tests/bin/alert-flow.php
 *
 * The thresholds are the shipped templates (templates/gpu_thold_templates.
 * xml), imported with Thold's own import and linked to the GPU Node device
 * template, so Thold creates them itself when the plugin creates the
 * graphs. Each round gives the fake nvidia-smi new output for GPU 0,
 * collects over SNMPv3, runs Cacti's poller, and runs Thold's check; the
 * round then checks what Thold logged. The maintenance schedule is the two
 * rows Maint's schedule page saves.
 *
 * Every check prints PASS or FAIL; EVIDENCE lines show the Thold state and
 * log rows each check relied on. Must parse on PHP 8.0.
 */

require(__DIR__ . '/e2e-lib.php');

use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\TemplateXml;
use Somethingwithproof\Gpu\TholdTemplateXml;

if (!api_plugin_is_enabled('thold') || !api_plugin_is_enabled('maint')) {
	fwrite(STDERR, "Install and enable Thold and Maint first\n");
	exit(2);
}

// Thold's import parses with core's xml2array(), which its own page loads.
require_once($config['library_path'] . '/xml.php');
require_once($config['base_path'] . '/plugins/thold/thold_functions.php');
require_once($config['base_path'] . '/plugins/maint/functions.php');

// Thold's log statuses (plugins/thold/includes/arrays.php).
const ALERT_NOTIFY  = 4;
const ALERT_RESTORE = 5;

// The age threshold this test sets on GPU 0, in seconds: low enough that a
// round without a sample passes it, above the age a fresh sample has.
const ALERT_AGE = 45;

$alert_log = 0;

/**
 * The 8-GPU fixture with GPU 0's fields changed, by nvidia-smi field name.
 *
 * @param array<string, string> $change
 * @return list<string>
 */
function alert_metrics(array $change): array {
	$fields = MetricModel::nvidiaFields();
	$lines  = e2e_fixture('r580-8gpu', 'metrics');
	$values = array_map('trim', explode(',', $lines[0]));

	foreach ($change as $field => $value) {
		$values[array_search($field, $fields, true)] = $value;
	}

	$lines[0] = implode(', ', $values);

	return $lines;
}

/**
 * Thold's threshold on one of GPU 0's data source items.
 *
 * @return array<string, mixed>
 */
function alert_thold(string $item): array {
	global $e2e_host;

	$row = db_fetch_row_prepared('SELECT td.id, td.thold_alert, td.thold_fail_count, td.lastread, td.thold_hi, td.thold_enabled, td.data_type, td.percent_ds
		FROM thold_data AS td
		INNER JOIN data_local AS dl ON dl.id = td.local_data_id
		WHERE td.host_id = ? AND dl.snmp_index = ? AND td.data_source_name = ?', array($e2e_host, e2e_key(1), $item));

	return is_array($row) ? $row : array();
}

/**
 * Thold log rows since the round began: data source item => statuses, for
 * every threshold on the node.
 *
 * @return array<string, list<int>>
 */
function alert_logged(): array {
	global $alert_log, $e2e_host;

	$out = array();

	foreach (db_fetch_assoc_prepared('SELECT l.id, l.status, l.current, td.data_source_name, dl.snmp_index
		FROM plugin_thold_log AS l
		INNER JOIN thold_data AS td ON td.id = l.threshold_id
		INNER JOIN data_local AS dl ON dl.id = td.local_data_id
		WHERE l.id > ? AND td.host_id = ? ORDER BY l.id', array($alert_log, $e2e_host)) as $row) {
		print 'EVIDENCE log id=' . $row['id'] . ' ' . $row['snmp_index'] . ' ' . $row['data_source_name'] . ' status=' . $row['status'] . " current='" . $row['current'] . "'" . PHP_EOL;

		$key         = ($row['snmp_index'] === e2e_key(1) ? '' : $row['snmp_index'] . ' ') . $row['data_source_name'];
		$out[$key][] = (int) $row['status'];
	}

	return $out;
}

/**
 * Run a program from the Cacti root, with its output in a file.
 *
 * @param list<string> $argv
 */
function alert_run(array $argv, string $output): int {
	global $config;

	$process = proc_open($argv, array(1 => array('file', $output, 'w'), 2 => array('file', $output, 'a')), $pipes, $config['base_path']);

	return is_resource($process) ? proc_close($process) : -1;
}

/**
 * One round: new tool output for GPU 0, a collection, a Cacti poll, and a
 * Thold check. Returns the Thold log rows the round added.
 *
 * @param array<string, string> $change
 * @return array<string, list<int>>
 */
function alert_round(string $round, array $change, int $status = 0, int $extraWait = 0): array {
	global $config, $alert_log;

	print "== round $round" . PHP_EOL;

	$alert_log = (int) db_fetch_cell('SELECT COALESCE(MAX(id), 0) FROM plugin_thold_log');

	e2e_tool(alert_metrics($change), e2e_fixture('r580-8gpu', 'names'), $status);

	if ($extraWait > 0) {
		sleep($extraWait);
	}

	e2e_collect();

	// RRDtool refuses a second update in the same second.
	sleep(1);
	e2e_poll();

	// poller_bottom starts poller_thold.php in the background. Wait for its
	// check, then run it in the foreground: a run that finds nothing new to
	// check changes nothing, and one that finds the background run still
	// registered exits at once, so the wait is what orders the evidence.
	e2e_wait_thold();

	$output = $config['base_path'] . '/log/gpu-alert-thold.txt';
	$rc     = alert_run(array(PHP_BINARY, $config['base_path'] . '/plugins/thold/poller_thold.php'), $output);
	$text   = (string) file_get_contents($output);

	e2e_check($rc === 0 && stripos($text, 'TypeError') === false && stripos($text, 'Fatal') === false, "$round: Thold's check ran without an error (exit $rc)");

	if ($rc !== 0 || stripos($text, 'error') !== false) {
		print '  poller_thold.php: ' . substr(preg_replace('/\s+/', ' ', $text), 0, 400) . PHP_EOL;
	}

	foreach (TholdTemplateXml::templates() as $spec) {
		$t = alert_thold($spec[0]);

		if ($t !== array()) {
			print "EVIDENCE $round " . $spec[0] . ' alert=' . $t['thold_alert'] . ' fail_count=' . $t['thold_fail_count'] . " lastread='" . $t['lastread'] . "'" . PHP_EOL;
		}
	}

	return alert_logged();
}

/**
 * Check a round: $expect is item => [breached now, statuses logged]; every
 * other threshold on the node must have logged nothing.
 *
 * @param array<string, list<int>> $logged
 * @param array<string, array{0: bool, 1: list<int>}> $expect
 */
function alert_expect(string $round, array $logged, array $expect): void {
	foreach ($expect as $item => $want) {
		$t   = alert_thold($item);
		$got = $logged[$item] ?? array();

		e2e_check($t !== array() && ((int) $t['thold_alert'] !== 0) === $want[0] && $got === $want[1], "$round: $item " . ($want[0] ? 'breached' : 'normal') . ' with log ' . json_encode($want[1]) . ' (alert=' . ($t['thold_alert'] ?? '?') . ', fail_count=' . ($t['thold_fail_count'] ?? '?') . ', log ' . json_encode($got) . ')');
	}

	$other = array_diff_key($logged, $expect);

	e2e_check($other === array(), "$round: no other threshold logged anything" . ($other === array() ? '' : ' (' . json_encode($other) . ')'));
}

function alert_thold_enabled(string $round): void {
	// Cacti disables a plugin whose hook throws; Thold must still be on.
	e2e_check(api_plugin_is_enabled('thold') && (int) db_fetch_cell("SELECT status FROM plugin_config WHERE directory = 'thold'") === 1, "$round: Thold is still enabled");
}

$quiet = array();

foreach (TholdTemplateXml::templates() as $spec) {
	$quiet[$spec[0]] = array(false, array());
}

$mask   = MetricModel::MASK_FIELD;
$uncorr = 'ecc.errors.uncorrected.volatile.total';
$hot    = array('temperature.gpu' => '95', $mask => '0x0000000000000048', $uncorr => '3', 'retired_pages.pending' => 'Yes');

/* Setup: the shipped Thold templates, linked to the GPU Node template. */

$alert_result = thold_template_import((string) file_get_contents($config['base_path'] . '/plugins/gpu/templates/' . TholdTemplateXml::FILE));

e2e_check(count($alert_result['success'] ?? array()) === count(TholdTemplateXml::templates()) && empty($alert_result['errors']) && empty($alert_result['failure']), 'setup: Thold imported every shipped template (' . json_encode($alert_result) . ')');

$alert_host_template = (new \Somethingwithproof\Gpu\CactiObjectRepository(false))->idByHash('host_template', TemplateXml::hash('02', TemplateXml::DEVICE_TEMPLATE));

// As Thold's Device Template page links a template (item_add_tt), with
// Thold's own setting to create thresholds for new graphs.
foreach (TholdTemplateXml::templates() as $alert_name => $spec) {
	db_execute_prepared('REPLACE INTO plugin_thold_host_template (host_template_id, thold_template_id) SELECT ?, id FROM thold_template WHERE hash = ?', array($alert_host_template, TholdTemplateXml::hash($alert_name)));
}

db_execute("REPLACE INTO settings (name, value) VALUES ('thold_autocreate', 'on')");

e2e_space(1);
list($e2e_host, $e2e_node_id) = e2e_node('gpu-node-alert');

e2e_tool(alert_metrics(array()), e2e_fixture('r580-8gpu', 'names'));
e2e_collect();

$alert_count = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM thold_data WHERE host_id = ?', array($e2e_host));

e2e_check($alert_count === 8 * count(TholdTemplateXml::templates()), "setup: Thold created the templated thresholds for each GPU's graphs ($alert_count)");

foreach (TholdTemplateXml::templates() as $alert_name => $spec) {
	$t = alert_thold($spec[0]);

	e2e_check(($t['thold_hi'] ?? '') === $spec[1] && ($t['thold_enabled'] ?? '') === 'on' && (int) ($t['data_type'] ?? -1) === ($spec[3] === '' ? 0 : 2) && ($t['percent_ds'] ?? '') === $spec[3], "setup: '$alert_name' is on GPU 0 above " . $spec[1]);
}

// A low staleness threshold for this test only (the template's is 900 s).
db_execute_prepared('UPDATE thold_data SET thold_hi = ? WHERE id = ?', array((string) ALERT_AGE, (int) (alert_thold('age')['id'] ?? 0)));

// Cacti creates the RRD files in the first cycle and writes from the next.
e2e_poll();
e2e_poll();

e2e_check((int) db_fetch_cell_prepared('SELECT status FROM host WHERE id = ?', array($e2e_host)) === 3, 'setup: the node device is up, so Thold checks its thresholds');

/* Normal values: nothing breaches, and the values Thold reads are numbers. */

$logged = alert_round('baseline', array());
alert_expect('baseline', $logged, $quiet);
e2e_check(is_numeric(alert_thold('temp_gpu')['lastread'] ?? '') && is_numeric(alert_thold('age')['lastread'] ?? '') && (alert_thold('ecc_uncorr_new')['lastread'] ?? '') === '0', 'baseline: Thold read temperature, age, and zero new ECC errors');

/* A fault: hot, throttled by hardware, new uncorrected errors, a page pending. */

$logged = alert_round('fault', $hot);
alert_expect('fault', $logged, array(
	'temp_gpu'        => array(true, array()),
	'thr_hw_thermal'  => array(true, array(ALERT_NOTIFY)),
	'thr_hw_slowdown' => array(true, array(ALERT_NOTIFY)),
	'ecc_uncorr_new'  => array(true, array(ALERT_NOTIFY)),
	'retired_pending' => array(true, array(ALERT_NOTIFY)),
) + $quiet);
e2e_check((int) (alert_thold('temp_gpu')['thold_fail_count'] ?? 0) === 1, 'fault: the temperature breach waits for its second poll');

/* The fault holds; no new errors, so the ECC alert recovers. */

$logged = alert_round('fault2', $hot);
alert_expect('fault2', $logged, array(
	'temp_gpu'        => array(true, array(ALERT_NOTIFY)),
	'thr_hw_thermal'  => array(true, array()),
	'thr_hw_slowdown' => array(true, array()),
	'ecc_uncorr_new'  => array(false, array(ALERT_RESTORE)),
	'retired_pending' => array(true, array()),
) + $quiet);

/* Driver reload: the volatile total falls to 0, so the delta is unknown. */

$logged = alert_round('reset', array($uncorr => '0'));
alert_expect('reset', $logged, array(
	'temp_gpu'        => array(false, array(ALERT_RESTORE)),
	'thr_hw_thermal'  => array(false, array(ALERT_RESTORE)),
	'thr_hw_slowdown' => array(false, array(ALERT_RESTORE)),
	'retired_pending' => array(false, array(ALERT_RESTORE)),
) + $quiet);
e2e_check(!is_numeric(alert_thold('ecc_uncorr_new')['lastread'] ?? '0') && e2e_ss($e2e_host, 'get', 'ecc_uncorr_new', e2e_key(1)) === 'U', 'reset: the new-error count is unknown after the total fell, and Thold reads no number');
alert_thold_enabled('reset');

/* New errors after the reset fire again, and recover. */

$logged = alert_round('after reset', array($uncorr => '2'));
alert_expect('after reset', $logged, array('ecc_uncorr_new' => array(true, array(ALERT_NOTIFY))) + $quiet);
e2e_check((alert_thold('ecc_uncorr_new')['lastread'] ?? '') === '2', 'after reset: Thold read the two errors counted since the reset');

$logged = alert_round('recovered', array($uncorr => '2'));
alert_expect('recovered', $logged, array('ecc_uncorr_new' => array(false, array(ALERT_RESTORE))) + $quiet);
alert_thold_enabled('recovered');

/* The tool fails: every value is unknown and breaches nothing; age alerts. */

$logged = alert_round('silent', array(), 9, 15);
alert_expect('silent', $logged, array('age' => array(true, array(ALERT_NOTIFY))) + $quiet);
e2e_check(is_numeric(alert_thold('age')['lastread'] ?? '') && !is_numeric(alert_thold('temp_gpu')['lastread'] ?? '0'), 'silent: age stays numeric while every other value is unknown (REQ-ALERT-002)');

$logged = alert_round('answering', array());
alert_expect('answering', $logged, array('age' => array(false, array(ALERT_RESTORE))) + $quiet);

/* Maint: a one-off schedule on the node's device, as Maint's page saves it. */

db_execute_prepared("INSERT INTO plugin_maint_schedules (enabled, name, mtype, stime, etime, minterval) VALUES ('on', 'gpu alert test', 1, ?, ?, 0)", array(time() - 60, time() + 3600));
$alert_schedule = (int) db_fetch_insert_id();
db_execute_prepared('INSERT INTO plugin_maint_hosts (type, host, schedule) VALUES (1, ?, ?)', array($e2e_host, $alert_schedule));

e2e_check(plugin_maint_check_cacti_host($e2e_host), 'maint: Maint reports the node device in maintenance');

foreach (array('maint', 'maint2') as $alert_round_name) {
	$logged = alert_round($alert_round_name, array('temperature.gpu' => '95'));
	$t      = alert_thold('temp_gpu');
	$rrd    = e2e_rrd((int) db_fetch_cell_prepared('SELECT local_data_id FROM thold_data WHERE id = ?', array((int) ($t['id'] ?? 0))));

	e2e_check((int) ($t['thold_fail_count'] ?? -1) === 0 && ($logged['temp_gpu'] ?? array()) === array() && ($rrd['temp_gpu'] ?? '') === '95', "$alert_round_name: in maintenance the breach is neither counted nor notified, while the RRD file records 95 (fail_count=" . ($t['thold_fail_count'] ?? '?') . ')');
	alert_expect($alert_round_name, $logged, array('temp_gpu' => array(true, array())) + $quiet);
}

db_execute_prepared('DELETE FROM plugin_maint_hosts WHERE schedule = ?', array($alert_schedule));
db_execute_prepared('DELETE FROM plugin_maint_schedules WHERE id = ?', array($alert_schedule));

e2e_check(!plugin_maint_check_cacti_host($e2e_host), 'unmaint: the node device is out of maintenance');

$logged = alert_round('unmaint', array('temperature.gpu' => '95'));
alert_expect('unmaint', $logged, array('temp_gpu' => array(true, array())) + $quiet);
e2e_check((int) (alert_thold('temp_gpu')['thold_fail_count'] ?? 0) === 1, 'unmaint: out of maintenance the breach counts again');

$logged = alert_round('unmaint2', array('temperature.gpu' => '95'));
alert_expect('unmaint2', $logged, array('temp_gpu' => array(true, array(ALERT_NOTIFY))) + $quiet);

$logged = alert_round('final', array());
alert_expect('final', $logged, array('temp_gpu' => array(false, array(ALERT_RESTORE))) + $quiet);
alert_thold_enabled('final');

print ($e2e_failures === 0 ? 'alert flow ok' : "alert flow: $e2e_failures failed") . PHP_EOL;

exit($e2e_failures === 0 ? 0 : 1);
