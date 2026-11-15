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

function gpu_nodes(): \Somethingwithproof\Gpu\NodeRepository {
	return new \Somethingwithproof\Gpu\NodeRepository(false, new \Somethingwithproof\Gpu\SystemClock());
}

function gpu_gpus(): \Somethingwithproof\Gpu\GpuRepository {
	return new \Somethingwithproof\Gpu\GpuRepository(false);
}

function gpu_poller(): \Somethingwithproof\Gpu\Poller {
	$clock       = new \Somethingwithproof\Gpu\SystemClock();
	$gpus        = gpu_gpus();
	$collections = new \Somethingwithproof\Gpu\CollectionRepository(false);
	$retries     = (int) read_config_option('snmp_retries');

	$collector = new \Somethingwithproof\Gpu\Collector(
		new \Somethingwithproof\Gpu\SnmpExtendReader(max(0, $retries)),
		new \Somethingwithproof\Gpu\NvidiaSmiParser(),
		new \Somethingwithproof\Gpu\Inventory($gpus, new \Somethingwithproof\Gpu\IdentityReconciler(), $clock),
		$gpus,
		new \Somethingwithproof\Gpu\ObservationRepository(false),
		$collections,
		new \Somethingwithproof\Gpu\ReindexRepository(false),
		new \Somethingwithproof\Gpu\LockRepository(false),
		$clock,
	);

	return new \Somethingwithproof\Gpu\Poller(gpu_nodes(), $collections, $collector, $clock);
}

function gpu_templates(): \Somethingwithproof\Gpu\TemplateInstaller {
	global $config;

	// What core's cli/import_template.php loads for an import: the import
	// itself replicates and repairs through poller.php and utility.php.
	include_once($config['library_path'] . '/import.php');
	include_once($config['library_path'] . '/poller.php');
	include_once($config['library_path'] . '/utility.php');

	return new \Somethingwithproof\Gpu\TemplateInstaller(new \Somethingwithproof\Gpu\CactiObjectRepository(false), new \Somethingwithproof\Gpu\LockRepository(false), __DIR__, rtrim((string) $config['base_path'], '/'));
}

function gpu_script_server(): \Somethingwithproof\Gpu\ScriptServer {
	return new \Somethingwithproof\Gpu\ScriptServer(new \Somethingwithproof\Gpu\CacheRepository(false), new \Somethingwithproof\Gpu\SystemClock(), gpu_settings()->staleAfter);
}

/**
 * Bring the schema, hooks, realms, and shipped templates up to the code's version.
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

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered() && gpu_templates()->installed()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	gpu_setup_hooks($grant);

	// The version is recorded only once the templates are in place, so a
	// failed import is retried by the next repair.
	if (!gpu_templates()->install()) {
		return false;
	}

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

	// A hook repaired while the plugin is enabled must be active, or core
	// never calls it; on install and while disabled, core decides.
	$enabled = (new \Somethingwithproof\Gpu\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Gpu\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('gpu', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Gpu\Pages::realms() as $realm) {
		api_plugin_register_realm('gpu', implode(',', $realm['files']), $realm['name'], $grant);
	}
}

/**
 * Abort an uninstall that was told to keep the tables but could not untrack
 * them. Core drops every tracked table once plugin_gpu_uninstall() returns
 * and ignores its result, so only stopping the request keeps the tables; the
 * plugin then stays installed and the uninstall can be retried. PHP 8.0
 * syntax, because the unbooted path uses it too.
 */
function gpu_uninstall_stop(): void {
	cacti_log('ERROR: Uninstall stopped: the plugin tables could not be untracked, so Cacti would have dropped the tables it was told to keep', false, 'GPU');

	throw new RuntimeException('The GPU plugin uninstall was stopped to keep its tables. See the Cacti log.');
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
		if (!$schema->untrackTables()) {
			gpu_uninstall_stop();
		}

		\Somethingwithproof\Gpu\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
}

/**
 * poller_bottom hook: start the collector in the background once the
 * poller cycle has finished. The collector reads the GPU nodes; the poller
 * itself and the script server input never do (REQ-SS-001).
 */
function gpu_poller_bottom(): void {
	global $config;

	if (!gpu_boot()) {
		return;
	}

	// Remote data collectors are phase 3; only the main poller collects.
	if ((int) $config['poller_id'] !== \Somethingwithproof\Gpu\Poller::MAIN_POLLER) {
		return;
	}

	if (!function_exists('exec_background')) {
		include_once($config['library_path'] . '/poller.php');
	}

	// exec_background() silently does nothing for a binary that does not
	// exist, so a bare 'php' fallback would never start the collector.
	$php = trim((string) read_config_option('path_php_binary'));

	if ($php === '' || !is_file($php) || !is_executable($php)) {
		cacti_log('ERROR: The GPU collector was not started because the PHP binary path setting is empty or not an executable file', false, 'GPU');

		return;
	}

	// The one argument is escaped, so no part of it is parsed by a shell.
	$args = '-q ' . cacti_escapeshellarg(__DIR__ . '/poller_gpu.php');

	exec_background($php, $args);
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
