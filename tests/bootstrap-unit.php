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
 * Unit test bootstrap. Stubs the few Cacti helpers the plugin calls, so the
 * suite needs no database or Cacti checkout. Each stub records its calls in
 * $GLOBALS for tests to read.
 */

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_NONE', 1);
	define('POLLER_VERBOSITY_LOW', 2);
	define('POLLER_VERBOSITY_MEDIUM', 3);
	define('POLLER_VERBOSITY_HIGH', 4);
	define('POLLER_VERBOSITY_DEBUG', 5);
}

if (!defined('MESSAGE_LEVEL_ERROR')) {
	define('MESSAGE_LEVEL_ERROR', 3);
}

$GLOBALS['__gpu_log'] = [];

if (!function_exists('__')) {
	// Core's __() (include/global_languages.php) passes every argument to
	// sprintf(), the trailing text domain included, when the text has a
	// placeholder. A placeholder therefore takes the domain unless the caller
	// supplied values before it: __('%d s', $n, 'gpu'), never
	// sprintf(__('%d s', 'gpu'), $n).
	function __() {
		$args = func_get_args();

		if (count($args) === 1 || preg_match('/%(%|c|[-0-9.]*[bdeEfFgGhHosuxX])/', $args[0]) !== 1) {
			return $args[0];
		}

		return call_user_func_array('sprintf', $args);
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['__gpu_log'][] = ['message' => $string, 'environ' => $environ, 'level' => $level];

		return true;
	}
}

$GLOBALS['__gpu_options'] = [];

if (!function_exists('read_config_option')) {
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['__gpu_options'][$config_name] ?? '';
	}
}

// Repositories in unit tests talk to these stubs. A test reads the
// statements from $GLOBALS['__gpu_db_exec'] and queues query results in
// $GLOBALS['__gpu_db_rows'] (keyed by a substring of the SQL).
$GLOBALS['__gpu_db_exec'] = [];
$GLOBALS['__gpu_db_rows'] = [];

function gpu_test_rows(string $sql, array $params = []): mixed {
	foreach ($GLOBALS['__gpu_db_rows'] as $needle => $rows) {
		if (str_contains($sql, $needle)) {
			// A closure answers each query in turn, for a test that needs a
			// table to change between two reads.
			return $rows instanceof Closure ? $rows($sql, $params) : $rows;
		}
	}

	return [];
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		$GLOBALS['__gpu_db_exec'][] = ['sql' => $sql, 'params' => $params];

		return true;
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		return gpu_test_rows($sql, $params);
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		$rows = gpu_test_rows($sql, $params);

		return is_array($rows) && isset($rows[0]) ? $rows[0] : [];
	}
}

// Transactions are recorded as BEGIN, COMMIT, and ROLLBACK statements.
// @@in_transaction answers from $GLOBALS['__gpu_in_tx'] in order, then 1,
// so a test can play InnoDB ending a transaction on a deadlock.
$GLOBALS['__gpu_in_tx'] = [];

if (!function_exists('db_begin_transaction')) {
	function db_begin_transaction($db_conn = false) {
		$GLOBALS['__gpu_db_exec'][] = ['sql' => 'BEGIN', 'params' => []];

		return true;
	}

	function db_commit_transaction($db_conn = false) {
		$GLOBALS['__gpu_db_exec'][] = ['sql' => 'COMMIT', 'params' => []];

		return true;
	}

	function db_rollback_transaction($db_conn = false) {
		$GLOBALS['__gpu_db_exec'][] = ['sql' => 'ROLLBACK', 'params' => []];

		return true;
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false, $log_function = null) {
		if (str_contains($sql, '@@in_transaction')) {
			return $GLOBALS['__gpu_in_tx'] === [] ? 1 : array_shift($GLOBALS['__gpu_in_tx']);
		}

		$rows = gpu_test_rows($sql, $params);

		return is_array($rows) && isset($rows[0]) ? reset($rows[0]) : 0;
	}
}

if (!function_exists('db_fetch_insert_id')) {
	function db_fetch_insert_id($db_conn = false) {
		return 1;
	}
}

// An SNMP agent: $GLOBALS['__gpu_snmp'] maps numeric OIDs (no leading dot)
// to values. A get of a missing OID answers like Cacti does for no answer.
$GLOBALS['__gpu_snmp'] = [];

if (!function_exists('cacti_snmp_get')) {
	function cacti_snmp_get($hostname, $community, $oid, $version, $auth_user = '', $auth_pass = '', $auth_proto = '', $priv_pass = '', $priv_proto = '', $context = '', $port = 161, $timeout_ms = 500, $retries = 0, $environ = 'SNMP', $engineid = '', $value_output_format = 1) {
		return $GLOBALS['__gpu_snmp'][ltrim($oid, '.')] ?? 'U';
	}

	function cacti_snmp_walk($hostname, $community, $oid, $version, $auth_user = '', $auth_pass = '', $auth_proto = '', $priv_pass = '', $priv_proto = '', $context = '', $port = 161, $timeout_ms = 500, $retries = 0, $bulk_walk_size = 10, $environ = 'SNMP', $engineid = '', $value_output_format = 1) {
		$out = [];

		foreach ($GLOBALS['__gpu_snmp'] as $key => $value) {
			if (str_starts_with($key, ltrim($oid, '.') . '.')) {
				$out[] = ['oid' => '.' . $key, 'value' => $value];
			}
		}

		return $out;
	}
}

// Enough of Cacti's HTML helpers for a page class to render into a buffer.
if (!function_exists('html_escape')) {
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('html_start_box')) {
	function html_start_box($title, $width, $div, $cell_padding, $align, $add_text) {
		print '<table>';
	}

	function html_end_box($trailing_br = true) {
		print '</table>';
	}
}

// What the last write changed; a test sets it before a guarded update.
$GLOBALS['__gpu_affected_rows'] = 1;

if (!function_exists('db_affected_rows')) {
	function db_affected_rows($db_conn = false) {
		return $GLOBALS['__gpu_affected_rows'];
	}
}


$GLOBALS['config'] = [
	'base_path'    => '/var/www/cacti',
	'library_path' => '/var/www/cacti/lib',
	'poller_id'    => 1,
	'is_web'       => false,
];

require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/include/autoload.php';

// Cacti's device, data query, and graph APIs. Each call is recorded in
// $GLOBALS['__gpu_cacti']; a test sets what they return.
$GLOBALS['__gpu_cacti']       = [];
$GLOBALS['__gpu_cacti_graph'] = ['local_graph_id' => 1, 'local_data_id' => [1]];

if (!function_exists('api_device_dq_add')) {
	function api_device_dq_add($device_id, $data_query_id, $reindex_method) {
		$GLOBALS['__gpu_cacti'][] = ['api_device_dq_add', $device_id, $data_query_id, $reindex_method];
	}

	function run_data_query($host_id, $snmp_query_id, $automation = false, $force = false) {
		$GLOBALS['__gpu_cacti'][] = ['run_data_query', $host_id, $snmp_query_id, $force];

		return true;
	}

	function create_complete_graph_from_template($graph_template_id, $host_id, $snmp_query_array, &$suggested_vals) {
		$GLOBALS['__gpu_cacti'][] = ['create_complete_graph_from_template', $graph_template_id, $host_id, $snmp_query_array];

		return $GLOBALS['__gpu_cacti_graph'];
	}

	function push_out_host($host_id, $local_data_id = 0, $data_template_id = 0) {
		$GLOBALS['__gpu_cacti'][] = ['push_out_host', $host_id, $local_data_id];
	}
}
