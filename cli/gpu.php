#!/usr/bin/env php
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
 * Operator and Configuration-as-Code (CasC) CLI for plugin_gpu:
 *
 *   php plugins/gpu/cli/gpu.php export [--format=yaml|xml]
 *   php plugins/gpu/cli/gpu.php validate <file> [--format=yaml|xml]
 *   php plugins/gpu/cli/gpu.php plan <file> [--format=yaml|xml] [--output=text|json]
 *   php plugins/gpu/cli/gpu.php apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]
 *   php plugins/gpu/cli/gpu.php health [--format=text|json]
 *
 * Parses on PHP 8.0 and refuses to run below PHP 8.2.
 */

chdir(__DIR__ . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/gpu/setup.php');

if (gpu_refuse_unsupported()) {
	exit(1);
}

gpu_boot();

/** @var list<string> $argv */
$gpu_args = array_slice($argv, 1);

if ($gpu_args === array() || in_array($gpu_args[0], array('-h', '--help', 'help'), true)) {
	print "usage: gpu.php <export|validate|plan|apply|health> [options]\n";
	print "  export [--format=yaml|xml]\n";
	print "  validate <file> [--format=yaml|xml]\n";
	print "  plan <file> [--format=yaml|xml] [--output=text|json]\n";
	print "  apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]\n";
	print "  health [--format=text|json]\n";
	exit($gpu_args === array() ? 2 : 0);
}

$gpu_cmd     = array_shift($gpu_args);
$gpu_format  = '';
$gpu_output  = 'text';
$gpu_confirm = '';
$gpu_file    = '';

foreach ($gpu_args as $gpu_arg) {
	if (strpos($gpu_arg, '--format=') === 0) {
		$gpu_format = strtolower(substr($gpu_arg, 9));
	} elseif (strpos($gpu_arg, '--output=') === 0) {
		$gpu_output = strtolower(substr($gpu_arg, 9));
	} elseif (strpos($gpu_arg, '--confirm=') === 0) {
		$gpu_confirm = substr($gpu_arg, 10);
	} elseif (strpos($gpu_arg, '-') === 0) {
		fwrite(STDERR, 'ERROR: Unknown option ' . $gpu_arg . PHP_EOL);
		exit(2);
	} elseif ($gpu_file === '') {
		$gpu_file = $gpu_arg;
	} else {
		fwrite(STDERR, 'ERROR: Unexpected argument ' . $gpu_arg . PHP_EOL);
		exit(2);
	}
}

$gpu_casc = gpu_casc();

if ($gpu_cmd === 'export') {
	$fmt = $gpu_format !== '' ? $gpu_format : 'yaml';

	try {
		print $gpu_casc->export($fmt);
		exit(0);
	} catch (InvalidArgumentException $e) {
		fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
		exit(2);
	}
}

if ($gpu_cmd === 'health') {
	$fmt = $gpu_format !== '' ? $gpu_format : 'text';

	if (!in_array($fmt, array('text', 'json'), true)) {
		fwrite(STDERR, 'ERROR: health --format must be text or json' . PHP_EOL);
		exit(2);
	}

	print $gpu_casc->formatHealth($fmt);
	exit(0);
}

if (!in_array($gpu_cmd, array('validate', 'plan', 'apply'), true)) {
	fwrite(STDERR, 'ERROR: Unknown command ' . $gpu_cmd . PHP_EOL);
	exit(2);
}

if ($gpu_file === '' || !is_file($gpu_file) || !is_readable($gpu_file)) {
	fwrite(STDERR, 'ERROR: Input configuration file is missing or unreadable' . PHP_EOL);
	exit(1);
}

$gpu_raw = file_get_contents($gpu_file);

if ($gpu_raw === false) {
	fwrite(STDERR, 'ERROR: Could not read input configuration file' . PHP_EOL);
	exit(1);
}

$fmt = $gpu_format;

if ($fmt === '') {
	$fmt = substr(strtolower($gpu_file), -4) === '.xml' ? 'xml' : 'yaml';
}

try {
	$gpu_desired = \Somethingwithproof\Gpu\CascProvider::parse($gpu_raw, $fmt);
} catch (InvalidArgumentException $e) {
	fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
	exit(1);
}

if ($gpu_cmd === 'validate') {
	$gpu_errors = $gpu_casc->validate($gpu_desired);

	if ($gpu_errors !== array()) {
		foreach ($gpu_errors as $err) {
			fwrite(STDERR, 'INVALID: ' . $err . PHP_EOL);
		}
		exit(1);
	}

	print "VALID\n";
	exit(0);
}

if ($gpu_cmd === 'plan') {
	$gpu_plan = $gpu_casc->plan($gpu_desired);

	if ($gpu_output === 'json') {
		print json_encode($gpu_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	} elseif (!$gpu_plan['valid']) {
		foreach ($gpu_plan['errors'] as $err) {
			fwrite(STDERR, 'INVALID: ' . $err . PHP_EOL);
		}
	} else {
		print 'Plan Hash: ' . $gpu_plan['plan_hash'] . "\n";
		print 'Settings changes: ' . count($gpu_plan['changes']['settings']) . "\n";
		print 'Node changes: ' . count($gpu_plan['changes']['nodes']) . "\n";
	}

	exit($gpu_plan['valid'] ? 0 : 1);
}

$gpu_result = $gpu_casc->apply($gpu_desired, $gpu_confirm);

if (!$gpu_result['applied']) {
	foreach ($gpu_result['errors'] as $err) {
		fwrite(STDERR, 'ERROR: ' . $err . PHP_EOL);
	}
	exit(1);
}

print sprintf(
	"APPLIED plan=%s settings_updated=%d nodes_created=%d nodes_updated=%d\n",
	$gpu_result['plan_hash'],
	$gpu_result['settings_updated'],
	$gpu_result['nodes_created'],
	$gpu_result['nodes_updated']
);
exit(0);
