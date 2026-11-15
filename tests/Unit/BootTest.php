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

use Somethingwithproof\Gpu\Log;

beforeEach(function () {
	$GLOBALS['__gpu_log'] = [];
});

it('reads its metadata from INFO', function () {
	$info = plugin_gpu_version();

	expect($info['name'])->toBe('gpu')
		->and($info['compat'])->toBe('1.2.31')
		// Nothing is collected before milestone 3, and remote collectors are phase 3.
		->and($info['capabilities'])->not->toContain('remote_collect')
		->and($info['capabilities'])->not->toContain('remote_poller');
});

it('boots and loads plugin classes on a supported runtime', function () {
	expect(gpu_php_supported())->toBeTrue()
		->and(gpu_boot())->toBeTrue()
		->and(class_exists(Log::class))->toBeTrue();
});

it('does not refuse or log on a supported runtime', function () {
	expect(gpu_refuse_unsupported())->toBeFalse()
		->and($GLOBALS['__gpu_log'])->toBe([]);
});

it('ignores classes outside its namespace and nested names', function () {
	expect(class_exists('Somethingwithproof\\Gpu\\Nested\\Log'))->toBeFalse()
		->and(class_exists('Somethingwithproof\\GpuLog'))->toBeFalse();
});

it('logs under the GPU tag at the right verbosity', function () {
	Log::debug('d');
	Log::info('i');
	Log::warning('w');
	Log::error('e');

	expect($GLOBALS['__gpu_log'])->toBe([
		['message' => 'DEBUG: d', 'environ' => 'GPU', 'level' => POLLER_VERBOSITY_DEBUG],
		['message' => 'NOTE: i', 'environ' => 'GPU', 'level' => POLLER_VERBOSITY_MEDIUM],
		['message' => 'WARNING: w', 'environ' => 'GPU', 'level' => ''],
		['message' => 'ERROR: e', 'environ' => 'GPU', 'level' => ''],
	]);
});
