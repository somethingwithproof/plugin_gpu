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
 * The GPU collector, started by the poller_bottom hook after each poller
 * cycle:
 *
 *   php plugins/gpu/poller_gpu.php [--force] [--debug]
 *
 * One instance at a time, through Cacti's process registry. It reads each
 * enabled node's SNMP extend entries and writes the plugin's cache; the
 * script server input reads only that cache. This file must parse on PHP
 * 8.0 and refuses to run below 8.2.
 */

chdir(__DIR__ . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/snmp.php');
require_once($config['base_path'] . '/plugins/gpu/setup.php');

ini_set('max_execution_time', '0');

$gpu_force = false;

foreach (array_slice($argv, 1) as $gpu_arg) {
	if ($gpu_arg === '--force') {
		$gpu_force = true;
	} elseif ($gpu_arg === '--debug') {
		$debug = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument ' . $gpu_arg . PHP_EOL . 'usage: poller_gpu.php [--force] [--debug]' . PHP_EOL);
		exit(1);
	}
}

if (gpu_refuse_unsupported()) {
	exit(1);
}

gpu_boot();

if (!$gpu_force && !api_plugin_is_enabled('gpu')) {
	exit(0);
}

if (!(new \Somethingwithproof\Gpu\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The GPU collector did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'GPU');
	exit(1);
}

// Leave a fifth of the poller interval free, so a run ends before the next
// cycle starts another.
$gpu_interval = (int) read_config_option('poller_interval');
$gpu_budget   = max(30, (int) (($gpu_interval > 0 ? $gpu_interval : 300) * 0.8));

// One collector at a time, held through collection and provisioning. The
// database lock is atomic; Cacti's process registry is kept for monitoring
// but can let two starts through together.
$gpu_lock = new \Somethingwithproof\Gpu\LockRepository(false);

if (!$gpu_lock->acquire('collector') || !register_process_start('gpu', 'collector', 0, $gpu_budget + 120)) {
	cacti_log('NOTE: The GPU collector is already running', false, 'GPU', POLLER_VERBOSITY_MEDIUM);
	exit(0);
}

$gpu_start = microtime(true);

try {
	$gpu_stats = gpu_poller()->run(gpu_settings(), $gpu_budget);
} finally {
	unregister_process('gpu', 'collector', 0);
	$gpu_lock->release('collector');
}

cacti_log(sprintf('STATS: Time:%.2f Nodes:%d Ok:%d Failed:%d Skipped:%d Remote:%d Reindexed:%d Deferred:%d Graphs:%d StoppedEarly:%s', microtime(true) - $gpu_start, $gpu_stats['nodes'], $gpu_stats['ok'], $gpu_stats['failed'], $gpu_stats['skipped'], $gpu_stats['remote'], $gpu_stats['reindexed'], $gpu_stats['deferred'], $gpu_stats['graphs'], $gpu_stats['stopped_early'] ? 'yes' : 'no'), false, 'GPU', POLLER_VERBOSITY_MEDIUM);

exit(0);
