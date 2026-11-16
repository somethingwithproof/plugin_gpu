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
 * Backup and restore (#53, REQ-BACKUP-001 to REQ-BACKUP-003), end to end
 * against the real snmpd of tests/bin/e2e.php, with its environment. Run
 * from the Cacti root, on a disposable database, with mysqldump and mysql
 * on the PATH:
 *
 *   php plugins/gpu/tests/bin/restore-flow.php
 *
 * The backup is what docs/backup.md lists: a mysqldump of the whole Cacti
 * database, plugin tables included, and the RRD directory, which this test
 * leaves in place. Two restores:
 *
 *   same age    the dump is restored over a database that has moved on:
 *               every GPU keeps its record, data source, and RRD file
 *   older dump  the dump predates four GPUs whose RRD files are still on
 *               disk; four different GPUs then arrive, their new data
 *               sources get the same ids, and so the same RRD file names,
 *               and the plugin moves each existing file aside before Cacti
 *               writes to it, so no GPU inherits another's history
 *
 * The database password goes to the tools in a defaults file, never on a
 * command line. Must parse on PHP 8.0.
 */

require(__DIR__ . '/e2e-lib.php');

db_execute("REPLACE INTO settings (name, value) VALUES ('gpu_absent_after', '1')");

/**
 * Run mysqldump or mysql on Cacti's database, with a file as input or output.
 */
function restore_db(string $tool, string $file): bool {
	// A background Thold run truncates its tables, which stops a dump with
	// "Table definition has changed".
	e2e_wait_thold();

	global $database_hostname, $database_username, $database_password, $database_default, $database_port;

	$defaults = tempnam(sys_get_temp_dir(), 'gpu-restore');
	chmod($defaults, 0600);
	file_put_contents($defaults, sprintf("[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n", $database_hostname, (int) $database_port, $database_username, addcslashes((string) $database_password, '"\\')));

	$io = $tool === 'mysqldump' ? array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $file, 'w')) : array(0 => array('file', $file, 'r'), 1 => array('file', '/dev/null', 'w'));
	$io[2] = array('file', $file . '.err', 'w');

	// MariaDB's client packages name the tools mariadb-dump and mariadb, and
	// may not install the mysql names.
	$binary = $tool === 'mysqldump' ? 'mariadb-dump' : 'mariadb';
	$binary = trim((string) shell_exec('command -v ' . $binary)) !== '' ? $binary : $tool;

	$argv = $tool === 'mysqldump'
		? array($binary, '--defaults-extra-file=' . $defaults, '--single-transaction', '--routines', '--skip-ssl', $database_default)
		: array($binary, '--defaults-extra-file=' . $defaults, '--skip-ssl', $database_default);

	$process = proc_open($argv, $io, $pipes);
	$rc      = is_resource($process) ? proc_close($process) : -1;

	unlink($defaults);

	if ($rc !== 0) {
		print '  ' . $tool . ': ' . substr((string) file_get_contents($file . '.err'), 0, 300) . PHP_EOL;
	}

	return $rc === 0;
}

/**
 * Fixture lines for the GPUs numbered in $map (fixture GPU => UUID suffix).
 *
 * @param array<int, int> $map
 * @return array{0: list<string>, 1: list<string>}
 */
function restore_tool(array $map): array {
	$out = array(array(), array());

	foreach (array('metrics', 'names') as $part => $name) {
		$lines = e2e_fixture('r580-8gpu', $name);

		foreach ($map as $gpu => $suffix) {
			$out[$part][] = str_replace(sprintf('8000-%012x', $gpu), sprintf('8000-%012x', $suffix), $lines[$gpu - 1]);
		}
	}

	return $out;
}

/**
 * Each listed GPU's UUID => its data source id and RRD file.
 *
 * @return array<string, array{0: int, 1: string}>
 */
function restore_sources(int $host): array {
	$out = array();

	foreach (db_fetch_assoc_prepared('SELECT id, snmp_index FROM data_local WHERE host_id = ? AND snmp_query_id = ? ORDER BY id', array($host, gpu_provisioner()->dataQueryId())) as $row) {
		$out[$row['snmp_index']] = array((int) $row['id'], get_data_source_path((int) $row['id'], true));
	}

	return $out;
}

function restore_cycle(array $tool): void {
	e2e_tool($tool[0], $tool[1]);
	e2e_collect();
	sleep(1);
	e2e_poll();
	sleep(1);
	e2e_poll();
}

$dump_old  = $config['base_path'] . '/log/gpu-restore-old.sql';
$dump_same = $config['base_path'] . '/log/gpu-restore-same.sql';

e2e_space(3);
list($e2e_host, $e2e_node_id) = e2e_node('gpu-node-restore');

/* Four GPUs, then the older backup. */

restore_cycle(restore_tool(array(1 => 1, 2 => 2, 3 => 3, 4 => 4)));

$four = restore_sources($e2e_host);

e2e_check(count($four) === 4, 'setup: four GPUs with data sources');
e2e_check(restore_db('mysqldump', $dump_old), 'backup: the older dump was written');

/* Four more, then the same-age backup. */

restore_cycle(restore_tool(array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8)));

$eight = restore_sources($e2e_host);
$rrd8  = array();

foreach ($eight as $key => $source) {
	$rrd8[$key] = e2e_rrd($source[0]);
}

e2e_check(count($eight) === 8 && count(array_filter(array_column($eight, 1), 'is_file')) === 8, 'setup: eight GPUs, each with its RRD file');
e2e_check(restore_db('mysqldump', $dump_same), 'backup: the same-age dump was written');

/* Same age: the database moves on (a GPU is removed), then is restored. */

$seven = restore_tool(array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7));

e2e_tool($seven[0], $seven[1]);
e2e_collect();

e2e_check(restore_db('mysql', $dump_same), 'same age: the dump was restored');

restore_cycle(restore_tool(array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8)));

$after   = restore_sources($e2e_host);
$matched = 0;

foreach ($eight as $key => $source) {
	$rrd = e2e_rrd($source[0]);

	// Each GPU's fixture utilization is 29 plus its number, so a value in
	// the wrong file would show.
	$matched += isset($after[$key]) && $after[$key] === $source && ($rrd['util_gpu'] ?? '') === ($rrd8[$key]['util_gpu'] ?? 'x') && (int) ($rrd['time'] ?? 0) > (int) ($rrd8[$key]['time'] ?? 0) ? 1 : 0;
}

e2e_check($matched === 8, "same age: every GPU kept its data source and RRD file, and its own values continue in it ($matched of 8)");
e2e_check((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_gpu_gpu WHERE node_id = ? AND presence = 'present'", array($e2e_node_id)) === 8, 'same age: the inventory is the backed-up one: eight GPUs present');

/* Older dump: four GPUs' RRD files outlive their records. */

$archiveEnds = array();

foreach ($eight as $source) {
	$archiveEnds[$source[1]] = e2e_rrd_file($source[1]);
}

e2e_check(restore_db('mysql', $dump_old), 'older dump: the dump was restored');

$restored = restore_sources($e2e_host);

e2e_check(count($restored) === 4, 'older dump: the database has the four GPUs it had then');

// Four GPUs the node never had before take the place of GPUs 5 to 8.
$fresh = restore_tool(array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 0x15, 6 => 0x16, 7 => 0x17, 8 => 0x18));

e2e_tool($fresh[0], $fresh[1]);
e2e_collect();

$new     = array_diff_key(restore_sources($e2e_host), $restored);
$aside   = 0;
$clashed = 0;

foreach ($new as $key => $source) {
	$old      = array_search($source[1], array_column($eight, 1), true) !== false;
	$clashed += $old ? 1 : 0;
	$moved    = glob($source[1] . '.gpu-orphan-*');
	$aside   += $old && !is_file($source[1]) && is_array($moved) && count($moved) === 1 ? 1 : 0;
}

e2e_check(count($new) === 4, 'older dump: four new data sources for the four new GPUs');
e2e_check($clashed > 0, "older dump: new data sources reuse RRD file names that are still on disk ($clashed of 4), the case this test is for");
e2e_check($aside === $clashed, "older dump: every existing file at a new data source's name was moved aside before Cacti wrote to it ($aside of $clashed)");

sleep(1);
e2e_poll();
sleep(1);
e2e_poll();

$clean = 0;

foreach ($new as $key => $source) {
	$rrd    = e2e_rrd($source[0]);
	$moved  = glob($source[1] . '.gpu-orphan-*');
	$number = (int) hexdec(substr($key, -2));

	// The new file holds the new GPU's values; the old file, moved aside,
	// still ends with the old GPU's last values.
	$oldEnd = is_array($moved) && count($moved) === 1 ? e2e_rrd_file($moved[0]) : array();
	$historyKept = !isset($archiveEnds[$source[1]]) || ($oldEnd !== array() && $oldEnd === $archiveEnds[$source[1]]);
	$clean += ($rrd['util_gpu'] ?? '') === (string) (29 + $number - 0x10) && $historyKept ? 1 : 0;
}

e2e_check($clean === 4, "older dump: each new GPU's RRD file holds only its own values ($clean of 4)");

foreach (array($dump_old, $dump_same) as $file) {
	foreach (array($file, $file . '.err') as $path) {
		if (is_file($path)) {
			unlink($path);
		}
	}
}

print ($e2e_failures === 0 ? 'restore flow ok' : "restore flow: $e2e_failures failed") . PHP_EOL;

exit($e2e_failures === 0 ? 0 : 1);
