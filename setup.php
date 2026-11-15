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
 * Cacti includes this file on every request, on whatever PHP version the site
 * runs. It must parse on PHP 8.0 (CI lints it there), and nothing under lib/
 * is loaded until gpu_boot() has confirmed PHP 8.2 or later (ADR 0006).
 * Every hook and lifecycle function therefore starts with that check, so an
 * unsupported runtime gets a refusal instead of a parse error that would take
 * down the whole Cacti UI.
 */

function gpu_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

function gpu_boot(): bool {
	if (!gpu_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Refuse with a message naming the required version (REQ-COMPAT-002).
 *
 * Used by the lifecycle functions only. Hook shims return silently instead,
 * because they run on every page and a refused plugin must not flood the log.
 */
function gpu_refuse_unsupported(): bool {
	if (gpu_php_supported()) {
		return false;
	}

	$message = __('The GPU plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'gpu');

	cacti_log('ERROR: ' . $message, false, 'GPU');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('gpu_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * @return array<string, string>
 */
function plugin_gpu_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

function plugin_gpu_install(): void {
	if (gpu_refuse_unsupported()) {
		return;
	}

	plugin_gpu_upgrade(true);
}

function plugin_gpu_check_config(): bool {
	if (gpu_refuse_unsupported()) {
		return false;
	}

	return plugin_gpu_upgrade(true);
}

/**
 * Common start of every page script, after include/auth.php has authorized
 * the realm. Refuses on an unsupported runtime or a disabled plugin, because
 * Cacti keeps plugin realms registered while a plugin is disabled.
 */
function gpu_page_start(): bool {
	if (gpu_boot() && api_plugin_is_enabled('gpu')) {
		return true;
	}

	top_header();

	html_start_box(__('GPU', 'gpu'), '100%', '', '3', 'center', '');

	print '<tr><td>' . html_escape(gpu_php_supported() ? __('The GPU plugin is not enabled.', 'gpu') : __('The GPU plugin requires PHP %s or later.', '8.2.0', 'gpu')) . '</td></tr>';

	html_end_box();

	bottom_footer();

	return false;
}

/**
 * State-changing actions are accepted only as POST, where csrf-magic checks
 * the token (REQ-UI-004). A GET that names one is sent back to the page
 * without effect.
 *
 * @param list<string> $actions
 */
function gpu_require_post(string $page, array $actions): void {
	$action = (string) get_nfilter_request_var('action');

	if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: ' . $page);
		exit;
	}
}

function gpu_settings(): \Somethingwithproof\Gpu\Settings {
	return (new \Somethingwithproof\Gpu\SettingsRepository(false))->load();
}

/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Cacti 1.2.31 never calls this itself; check_config and the config_arrays
 * hook do. Fresh installs take the same path as upgrades. The version stored
 * in plugin_config changes only after every migration has succeeded, so an
 * interrupted run is finished by the next one (REQ-LIFE-002).
 *
 * $grant gives newly registered realms to the current user. Only install and
 * check_config pass it: both run from the Plugins page, which needs plugin
 * management. A repair triggered from config_arrays can run for anyone who
 * opens a plugin page, so it must never grant anything.
 */
function plugin_gpu_upgrade(bool $grant = false): bool {
	if (!gpu_boot()) {
		return false;
	}

	$info   = plugin_gpu_version();
	$schema = new \Somethingwithproof\Gpu\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	gpu_setup_hooks($grant);

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
}

/**
 * Register hooks and realms.
 *
 * Kept procedural and named *_setup_* because api_plugin_register_hook() and
 * api_plugin_register_realm() refuse callers whose function name does not
 * contain install, upgrade, or setup.
 */
function gpu_setup_hooks(bool $grant = false): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	foreach (\Somethingwithproof\Gpu\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('gpu', $hook, $function, 'setup.php');
	}

	foreach (\Somethingwithproof\Gpu\Pages::realms() as $realm) {
		api_plugin_register_realm('gpu', implode(',', $realm['files']), $realm['name'], $grant);
	}
}

/**
 * Uninstall never touches a Cacti device, data source, graph, or RRD file,
 * and never contacts a GPU node (REQ-LIFE-004, REQ-LIFE-005). Core drops the
 * tracked plugin tables afterwards unless the administrator left "Keep
 * Plugin Tables on Uninstall" checked, which is the default.
 */
function plugin_gpu_uninstall(): void {
	if (!gpu_boot()) {
		include_once(__DIR__ . '/include/unbooted-uninstall.php');
		gpu_uninstall_unbooted();

		return;
	}

	$schema = new \Somethingwithproof\Gpu\Schema(false);

	if (gpu_settings()->keepTablesOnUninstall) {
		$schema->untrackTables();

		\Somethingwithproof\Gpu\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
}

function gpu_config_arrays(): void {
	global $menu;

	if (!gpu_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Gpu\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/gpu/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Gpu\Pages::upgradeTriggers(), true)) {
		plugin_gpu_upgrade();
	}
}

function gpu_config_settings(): void {
	global $tabs, $settings;

	if (!gpu_boot()) {
		return;
	}

	$tabs['gpu']     = __('GPU', 'gpu');
	$settings['gpu'] = \Somethingwithproof\Gpu\Settings::form();
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function gpu_draw_navigation_text($nav) {
	if (!gpu_boot()) {
		return $nav;
	}

	return array_merge($nav, \Somethingwithproof\Gpu\Pages::navigation());
}
