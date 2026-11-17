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

use Somethingwithproof\Gpu\Pages;
use Somethingwithproof\Gpu\Schema;
use Somethingwithproof\Gpu\Settings;

it('applies documented defaults to settings that were never saved', function () {
	$settings = Settings::fromRow([]);

	expect($settings->absentAfter)->toBe(Settings::DEFAULT_ABSENT_AFTER)
		->and($settings->keepTablesOnUninstall)->toBeTrue();
});

it('drops tables on uninstall only after the box was cleared and saved', function () {
	expect(Settings::fromRow(['gpu_uninstall_keep_tables' => ''])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['gpu_uninstall_keep_tables' => null])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['gpu_uninstall_keep_tables' => 'on'])->keepTablesOnUninstall)->toBeTrue();
});

it('bounds the absent count to its documented range', function (mixed $stored, int $expected) {
	expect(Settings::fromRow(['gpu_absent_after' => $stored])->absentAfter)->toBe($expected);
})->with([
	['1', 1],
	['5', 5],
	['0', Settings::DEFAULT_ABSENT_AFTER],
	['-5', Settings::DEFAULT_ABSENT_AFTER],
	['abc', Settings::DEFAULT_ABSENT_AFTER],
	['', Settings::DEFAULT_ABSENT_AFTER],
	['5000', Settings::MAX_ABSENT_AFTER],
]);

it('gives every setting in the form a default that fromRow agrees with', function () {
	$form = Settings::form();

	foreach (Settings::NAMES as $name) {
		expect($form)->toHaveKey($name);
	}

	$defaults = Settings::fromRow([
		'gpu_absent_after'          => $form['gpu_absent_after']['default'],
		'gpu_uninstall_keep_tables' => $form['gpu_uninstall_keep_tables']['default'],
	]);

	expect($defaults)->toEqual(Settings::fromRow([]))
		->and($form['gpu_absent_after']['options'])->toBe(['options' => ['min_range' => 1, 'max_range' => Settings::MAX_ABSENT_AFTER]]);
});

it('registers viewer, operator and administrator realms', function () {
	expect(array_column(Pages::realms(), 'name'))->toBe(['GPU Operator', 'GPU View', 'GPU Admin']);
});

it('puts every page in exactly one realm', function () {
	$files = Pages::files();

	expect($files)->toBe(array_values(array_unique($files)));

	foreach (Pages::realms() as $realm) {
		expect($realm['files'])->not->toBe([]);
	}
});

it('lists every page script the plugin ships in a realm', function () {
	$root    = dirname(__DIR__, 2);
	$scripts = array_map('basename', glob($root . '/gpu*.php'));

	expect($scripts)->not->toBeEmpty();

	foreach ($scripts as $script) {
		expect(in_array($script, Pages::files(), true))->toBeTrue($script);
	}

	foreach (Pages::files() as $file) {
		expect(is_file($root . '/' . $file))->toBeTrue($file);
	}
});

it('gives every menu page a breadcrumb under the console', function () {
	$nav = Pages::navigation();

	foreach (array_keys(Pages::menu()) as $file) {
		expect($nav[$file . ':']['mapping'])->toBe('index.php:')
			->and($nav[$file . ':']['url'])->toBe($file);
	}
});

it('names every hook function that setup.php defines', function () {
	foreach (Schema::HOOKS as $function) {
		expect(function_exists($function))->toBeTrue($function);
	}
});

it('defines plugin tables with the plugin prefix, a primary key, and no serial or secret columns', function () {
	foreach (Schema::tables() as $table => $definition) {
		expect($table)->toStartWith('plugin_gpu_')
			->and($definition)->toHaveKey('primary')
			->and($definition['type'])->toBe('InnoDB');

		foreach ($definition['columns'] as $column) {
			expect($column['name'])->not->toMatch('/serial|token|password|secret|private_key|command|path|url/');
		}
	}
});

it('keys GPU records by vendor and UUID only', function () {
	expect(Schema::tables()['plugin_gpu_gpu']['unique_keys'])->toBe([['name' => 'vendor_uuid', 'columns' => ['vendor', 'uuid']]]);
});

it('stops an uninstall that keeps the tables when they stay tracked, before core can drop them', function () {
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_log']     = [];
	$GLOBALS['__gpu_db_rows'] = ['FROM plugin_db_changes' => [['n' => '2']]];

	try {
		expect(fn () => plugin_gpu_uninstall())->toThrow(RuntimeException::class, 'The GPU plugin uninstall was stopped to keep its tables.');
		expect($GLOBALS['__gpu_log'][0]['message'])->toStartWith('ERROR: Uninstall stopped')
			->and(array_column($GLOBALS['__gpu_db_exec'], 'sql'))->not->toContain('DELETE FROM settings WHERE name = ?');

		// The same failure on a refused runtime, in the PHP 8.0 fallback.
		include_once dirname(__DIR__, 2) . '/include/unbooted-uninstall.php';
		expect(fn () => gpu_uninstall_unbooted())->toThrow(RuntimeException::class);

		$GLOBALS['__gpu_db_rows'] = [];

		plugin_gpu_uninstall();
		gpu_uninstall_unbooted();
	} finally {
		$GLOBALS['__gpu_db_rows'] = [];
	}
});
