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
 * Helpers shared by the end-to-end runs against a real snmpd (e2e.php,
 * alert-flow.php, failure-flow.php, restore-flow.php, scale-flow.php). Each
 * run includes this file from the Cacti root; see e2e.php for the
 * environment. Must parse on PHP 8.0.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

foreach (array('api_device', 'data_query', 'template', 'api_graph', 'api_data_source', 'utility', 'poller', 'rrd', 'snmp') as $library) {
	require_once($config['library_path'] . '/' . $library . '.php');
}

require_once($config['base_path'] . '/plugins/gpu/setup.php');

if (getenv('GPU_TEST_DATABASE') !== 'disposable' || !gpu_boot()) {
	fwrite(STDERR, "Set GPU_TEST_DATABASE=disposable and run on PHP 8.2 or later\n");
	exit(2);
}

const E2E_CACHE_WAIT = 32;

$e2e_failures = 0;
$e2e_fixtures = rtrim((string) getenv('GPU_E2E_FIXTURES'), '/');
$e2e_secrets  = file((string) getenv('GPU_E2E_SECRETS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$e2e_agent    = explode(':', (string) getenv('GPU_E2E_AGENT')) + array(1 => '161');

// Each flow runs in the same database as the ones before it, and a GPU
// already recorded at another node waits for a confirmed move, so every
// flow gives its fixture GPUs UUIDs of its own (e2e_space()).
$e2e_space = 0;

if (!is_dir($e2e_fixtures) || !is_array($e2e_secrets) || count($e2e_secrets) < 2 || $e2e_agent[0] === '') {
	fwrite(STDERR, "Set GPU_E2E_AGENT, GPU_E2E_SECRETS, and GPU_E2E_FIXTURES\n");
	exit(2);
}

function e2e_check(bool $ok, string $what): void {
	global $e2e_failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $what . PHP_EOL;

	if (!$ok) {
		$e2e_failures++;
	}
}

/**
 * The fixture lines with GPU $n replaced, or removed when $line is null.
 *
 * @return list<string>
 */
function e2e_fixture(string $name, string $part): array {
	global $e2e_space;

	$text = rtrim((string) file_get_contents(dirname(__DIR__) . '/fixtures/nvidia-smi/' . $name . '.' . $part), "\n");

	return explode("\n", str_replace('GPU-00000000-', sprintf('GPU-%08x-', $e2e_space), $text));
}

/**
 * Use UUID space $space (1 or more) for this flow's fixture GPUs.
 */
function e2e_space(int $space): void {
	global $e2e_space;

	$e2e_space = $space;
}

/**
 * Give the fake tool new output, and wait out snmpd's cache of the old.
 *
 * @param list<string> $metrics
 * @param list<string> $names
 */
function e2e_tool(array $metrics, array $names, int $status = 0, bool $legacy = false): void {
	global $e2e_fixtures;

	file_put_contents($e2e_fixtures . '/expected-query', implode(',', \Somethingwithproof\Gpu\MetricModel::nvidiaFields()));
	file_put_contents($e2e_fixtures . '/expected-query-legacy', implode(',', \Somethingwithproof\Gpu\MetricModel::nvidiaFields(true)));
	file_put_contents($e2e_fixtures . '/current.legacy', $legacy ? '1' : '');
	file_put_contents($e2e_fixtures . '/current.metrics', $metrics === array() ? '' : implode("\n", $metrics) . "\n");
	file_put_contents($e2e_fixtures . '/current.names', $names === array() ? '' : implode("\n", $names) . "\n");
	file_put_contents($e2e_fixtures . '/current.status', $status === 0 ? '' : (string) $status);

	// Always wait, the first change of a run too: each flow runs after
	// another, and snmpd may still hold the output the last one left.
	print 'wait ' . E2E_CACHE_WAIT . " s for snmpd's extend cache" . PHP_EOL;
	sleep(E2E_CACHE_WAIT);
}

/**
 * Wait for Thold's check of the last poll to finish. poller_bottom starts
 * poller_thold.php in the background, and it may not have registered when
 * this is called, so the poll's own mark is waited on too: Thold's
 * poller_output hook sets tcheck on each threshold it fed, synchronously in
 * the poller, and the check clears it.
 */
function e2e_wait_thold(int $limit = 120): void {
	$marked = db_table_exists('thold_data');

	for ($i = 0; $i < $limit; $i++) {
		$running = (int) db_fetch_cell("SELECT COUNT(*) FROM processes WHERE tasktype = 'thold'") > 0;

		if (!$running && (!$marked || (int) db_fetch_cell('SELECT COUNT(*) FROM thold_data WHERE tcheck = 1') === 0)) {
			return;
		}

		sleep(1);
	}

	print "NOTE: Thold's check had not finished after $limit s" . PHP_EOL;
}

function e2e_wait_collector(): void {
	for ($i = 0; $i < 90 && (int) db_fetch_cell("SELECT COUNT(*) FROM processes WHERE tasktype = 'gpu' AND taskname = 'collector'") > 0; $i++) {
		sleep(1);
	}
}

/**
 * One collector run, as poller_gpu.php does it, after any run that Cacti's
 * poller_bottom hook started has finished.
 *
 * @return array<string, mixed>
 */
function e2e_collect(): array {
	e2e_wait_collector();

	return gpu_poller()->run(gpu_settings(), 120);
}

/**
 * One Cacti poller cycle: it reads the script server input and updates the
 * RRD files.
 */
function e2e_poll(): void {
	global $config;

	$log = $config['base_path'] . '/log/gpu-e2e-poller.log';
	$process = proc_open(array(PHP_BINARY, $config['base_path'] . '/poller.php', '--force'), array(1 => array('file', $log, 'a'), 2 => array('file', $log, 'a')), $pipes, $config['base_path']);

	if (!is_resource($process) || proc_close($process) !== 0) {
		throw new RuntimeException('Cacti poller failed; see log/gpu-e2e-poller.log');
	}

	e2e_wait_collector();
}

function e2e_key(int $n): string {
	global $e2e_space;

	return sprintf('nvidia/GPU-%08x-0000-4000-8000-%012x', $e2e_space, $n);
}

function e2e_ss(int $host, string $command, string $field = '', string $index = ''): string {
	return gpu_script_server()->call((string) $host, $command, $field, $index);
}

/**
 * The last values Cacti wrote to a GPU's RRD file.
 *
 * @return array<string, string>
 */
function e2e_rrd(int $local_data_id): array {
	return e2e_rrd_file((string) get_data_source_path($local_data_id, true));
}

/**
 * The last values written to an RRD file, by path.
 *
 * @return array<string, string>
 */
function e2e_rrd_file(string $path): array {

	if (!is_file($path)) {
		return array();
	}

	$lines = array();
	exec('rrdtool lastupdate ' . escapeshellarg($path), $lines);

	if (count($lines) < 3) {
		return array();
	}

	$names  = preg_split('/\s+/', trim($lines[0]));
	$values = preg_split('/\s+/', trim(substr($lines[2], strpos($lines[2], ':') + 1)));

	return array('time' => (string) (int) $lines[2]) + array_combine($names, $values);
}

/**
 * A rendered graph: the templates ask for SVG; PNG is accepted too.
 */
function e2e_image($image): bool {
	return is_string($image) && (substr($image, 0, 4) === "\x89PNG" || preg_match('/^(<\?xml[^>]*>\s*)?<svg/', $image) === 1);
}

function e2e_title(int $host, string $index, string $template): string {
	return (string) db_fetch_cell_prepared('SELECT gtg.title_cache FROM graph_local AS gl INNER JOIN graph_templates_graph AS gtg ON gtg.local_graph_id = gl.id INNER JOIN graph_templates AS gt ON gt.id = gl.graph_template_id WHERE gl.host_id = ? AND gl.snmp_index = ? AND gt.name = ?', array($host, $index, $template));
}

/**
 * An SNMPv3 device with the GPU Node template on the test agent, mapped to
 * an enabled node, with the data query attached. Every other node is
 * disabled, so collection statistics are this node's own. Exits when the
 * agent does not answer.
 *
 * @return array{0: int, 1: int} the device and node ids
 */
function e2e_node(string $description): array {
	global $e2e_agent, $e2e_secrets;

	$template = (new \Somethingwithproof\Gpu\CactiObjectRepository(false))->idByHash('host_template', \Somethingwithproof\Gpu\TemplateXml::hash('02', \Somethingwithproof\Gpu\TemplateXml::DEVICE_TEMPLATE));

	e2e_check($template > 0, 'the GPU Node device template is installed');

	// Cacti's SNMP availability check uses cacti_snmp_get(), which on Cacti
	// 1.2.x (where snmp_format_agent() puts the target in brackets) cannot
	// reach an IPv4 agent on a port other than 161, so cmd.php would hold the
	// device down. Use the workaround docs/node-setup.md gives there: an
	// availability method that does not use SNMP.
	$avail = function_exists('snmp_format_agent') && (int) $e2e_agent[1] !== 161 ? AVAIL_NONE : AVAIL_SNMP;

	$host = (int) api_device_save(0, $template, $description, $e2e_agent[0], '', 3, 'cactigpu', $e2e_secrets[0], (int) $e2e_agent[1], 1000, '',
		$avail, 1, 23, 400, 1, '', 'SHA256', $e2e_secrets[1], 'AES128', '', '', 10, 1, 1, 0);

	e2e_check($host > 0, 'the node device was created through api_device_save()');

	// Earlier CI steps leave nodes on unreachable test devices; only this
	// run's node is collected, so the statistics are its own.
	db_execute("UPDATE plugin_gpu_node SET admin_state = 'disabled'");

	$nodeId = gpu_nodes()->save(0, $host, \Somethingwithproof\Gpu\AdminState::Enabled);
	$node   = gpu_nodes()->find($nodeId);

	e2e_check($node !== null && gpu_provisioner()->attach($node), 'the GPU data query was attached with reindex method None');
	e2e_check((int) db_fetch_cell_prepared('SELECT reindex_method FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?', array($host, gpu_provisioner()->dataQueryId())) === 0, 'the attachment keeps reindex method None');

	// Wait, within a bound, until the agent answers over SNMPv3 with the
	// device's own settings, so a slow agent start is not read as a failure.
	// The read goes the way collection reads the device.
	$device = $node !== null ? gpu_nodes()->device($node) : null;
	$ready  = false;

	for ($i = 0; $i < 30 && $device !== null && !$ready; $i++) {
		$uptime = (new \Somethingwithproof\Gpu\SnmpExtendReader(0))->value($device, '.1.3.6.1.2.1.1.3.0', 1000);
		$ready  = $uptime !== '' && $uptime !== 'U';

		if (!$ready) {
			sleep(1);
		}
	}

	e2e_check($ready, 'the agent answers over SNMPv3');

	if (!$ready) {
		print "e2e: the agent never answered; see snmpd's log\n";
		exit(1);
	}

	return array($host, $nodeId);
}
