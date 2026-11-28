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

namespace Somethingwithproof\Gpu;

/**
 * Installs the shipped Cacti objects (REQ-TPL-001 to REQ-TPL-005).
 *
 * The data query XML is copied to <path_cacti>/resource/script_server/,
 * the one place outside its own directory the plugin writes, and the path
 * it wrote is recorded in the gpu_data_query_file setting. The templates
 * are imported with import_xml_data(), which matches every object by hash,
 * so a second import updates the first instead of adding copies. Both run
 * under one advisory lock, so two upgrades cannot import at once.
 *
 * The import is skipped when the same file was imported before and the
 * objects are still there. Nothing here creates a device, data source,
 * graph, or RRD file.
 */
final class TemplateInstaller {
	public const SETTING_FILE = 'gpu_data_query_file';
	public const SETTING_HASH = 'gpu_templates_sha256';

	private const LOCK = 'templates';

		/**
	 * Construct a TemplateInstaller instance.
	 *
	 * @param CactiObjectRepository $cacti
	 * @param LockRepository $locks
	 * @param string $pluginPath
	 * @param string $cactiPath
	 */
	public function __construct(
		private readonly CactiObjectRepository $cacti,
		private readonly LockRepository $locks,
		private readonly string $pluginPath,
		private readonly string $cactiPath,
	) {
	}

		/**
	 * Install.
	 *
	 * @return bool
	 */
	public function install(): bool {
		if (!$this->locks->acquire(self::LOCK)) {
			Log::info('Template import skipped because another upgrade is importing them');

			return false;
		}

		try {
			return $this->placeDataQuery() && $this->importTemplates();
		} finally {
			$this->locks->release(self::LOCK);
		}
	}

		/**
	 * Data query target.
	 *
	 * @return string
	 */
	public function dataQueryTarget(): string {
		return $this->cactiPath . '/resource/script_server/' . TemplateXml::DATA_QUERY_FILE;
	}

		/**
	 * Place data query.
	 *
	 * @return bool
	 */
	private function placeDataQuery(): bool {
		$source = (string) @file_get_contents($this->pluginPath . '/resource/script_server/' . TemplateXml::DATA_QUERY_FILE);
		$target = $this->dataQueryTarget();

		if ($source === '') {
			Log::error('The GPU data query XML is missing from the plugin directory');

			return false;
		}

		if (@file_get_contents($target) !== $source) {
			// Written beside the target and renamed over it, so the script
			// server never reads a half-written file.
			$temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';

			if (@file_put_contents($temporary, $source) !== strlen($source) || !@rename($temporary, $target)) {
				@unlink($temporary);

				Log::error(sprintf('Unable to write the GPU data query XML to %s; make the directory writable by the web server user', dirname($target)));

				return false;
			}
		}

		if (@file_get_contents($target) !== $source) {
			Log::error('The GPU data query XML could not be verified after it was written');

			return false;
		}

		return $this->cacti->setting(self::SETTING_FILE) === $target || $this->cacti->saveSetting(self::SETTING_FILE, $target);
	}

		/**
	 * Import templates.
	 *
	 * @return bool
	 */
	private function importTemplates(): bool {
		$xml = (string) @file_get_contents($this->pluginPath . '/templates/' . TemplateXml::TEMPLATE_FILE);

		if ($xml === '') {
			Log::error('The GPU template XML is missing from the plugin directory');

			return false;
		}

		$digest = hash('sha256', $xml);

		if ($this->cacti->setting(self::SETTING_HASH) === $digest && $this->installed()) {
			return true;
		}

		$profile = $this->cacti->defaultProfileId();

		if ($profile === 0) {
			Log::error('The GPU templates were not imported because Cacti has no data source profile');

			return false;
		}

		$result = import_xml_data($xml, false, $profile);

		if (!self::succeeded($result) || !$this->installed()) {
			Log::error('The GPU templates could not be imported; see the Cacti log for the import messages');

			return false;
		}

		Log::info('GPU templates imported');

		return $this->cacti->saveSetting(self::SETTING_HASH, $digest);
	}

	/**
	 * Whether every top-level object the template file defines exists, and
	 * Cacti's copy of the data query XML matches the plugin's: a Cacti
	 * redeployment can remove the copy while the database objects stay, and
	 * Cacti cannot run the query without it.
	 * @return bool
	 */
	public function installed(): bool {
		$source = (string) @file_get_contents($this->pluginPath . '/resource/script_server/' . TemplateXml::DATA_QUERY_FILE);

		$target = $this->dataQueryTarget();

		if ($source === '' || !is_file($target) || @file_get_contents($target) !== $source) {
			return false;
		}

		if ($this->cacti->idByHash('snmp_query', TemplateXml::hash('04', TemplateXml::DATA_QUERY)) === 0
			|| $this->cacti->idByHash('host_template', TemplateXml::hash('02', TemplateXml::DEVICE_TEMPLATE)) === 0
			|| $this->cacti->idByHash('data_template', TemplateXml::hash('01', TemplateXml::DATA_TEMPLATE)) === 0) {
			return false;
		}

		foreach (array_keys(TemplateXml::graphs()) as $graph) {
			if ($this->cacti->idByHash('graph_templates', TemplateXml::hash('00', $graph)) === 0) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove what install() put in place, for an uninstall asked to
	 * (REQ-LIFE-004): each template the plugin imported, found by its hash,
	 * that nothing outside the plugin uses, and the data query XML when the
	 * data query went too and the file is the one the plugin wrote,
	 * unchanged. Never a device, data source, graph, or RRD file.
	 *
	 * A graph or data template is removed only with the data query and
	 * device template that contain it, so a kept container never points at
	 * a missing part.
	 *
	 * @return array{removed: list<string>, kept: list<string>}
	 * @param CactiTemplateRepository $templates
	 */
	public function remove(CactiTemplateRepository $templates): array {
		$report = ['removed' => [], 'kept' => []];
		$host   = $templates->idByHash('host_template', TemplateXml::hash('02', TemplateXml::DEVICE_TEMPLATE));
		$query  = $templates->idByHash('snmp_query', TemplateXml::hash('04', TemplateXml::DATA_QUERY));
		$data   = $templates->idByHash('data_template', TemplateXml::hash('01', TemplateXml::DATA_TEMPLATE));
		$graphs = [];
		$unknown = false;

		foreach (array_keys(TemplateXml::graphs()) as $name) {
			$id = $templates->idByHash('graph_templates', TemplateXml::hash('00', $name));
			$unknown = $unknown || $id === null;

			if ($id > 0) {
				$graphs[$name] = $id;
			}
		}

		if ($unknown || $host === null || $query === null || $data === null) {
			return ['removed' => [], 'kept' => array_merge([TemplateXml::DEVICE_TEMPLATE, TemplateXml::DATA_QUERY, TemplateXml::DATA_TEMPLATE, TemplateXml::DATA_QUERY_FILE], array_keys(TemplateXml::graphs()))];
		}

		$hostGone = $host === 0 || self::step($report, TemplateXml::DEVICE_TEMPLATE, !$templates->hostTemplateInUse($host), static fn (): bool => $templates->removeHostTemplate($host));
		$queryGone = $query === 0 || ($hostGone && self::step($report, TemplateXml::DATA_QUERY, !$templates->dataQueryInUse($query, $host), static fn (): bool => $templates->removeDataQuery($query)));

		if (!$hostGone && $query > 0) {
			$report['kept'][] = TemplateXml::DATA_QUERY;
		}

		$graphsGone = true;

		foreach ($graphs as $name => $id) {
			$free = $queryGone && $hostGone && !$templates->graphTemplateInUse($id, $query, $host);

			$graphsGone = self::step($report, $name, $free, static fn (): bool => $templates->removeGraphTemplate($id)) && $graphsGone;
		}

		if ($data > 0) {
			self::step($report, TemplateXml::DATA_TEMPLATE, $queryGone && $graphsGone && !$templates->dataTemplateInUse($data, $query, array_values($graphs)), static fn (): bool => $templates->removeDataTemplate($data));
		}

		if ($queryGone) {
			$this->removeDataQueryFile($report, $templates);
			$templates->deleteSetting(self::SETTING_HASH);
		}

		return $report;
	}

	/**
	 * Remove one object when it is free, and note the outcome.
	 *
	 * @param array{removed: list<string>, kept: list<string>} $report
	 * @param \Closure(): bool                                  $remove
	 * @param string $name
	 * @param bool $free
	 * @return bool
	 */
	private static function step(array &$report, string $name, bool $free, \Closure $remove): bool {
		if ($free && $remove()) {
			$report['removed'][] = $name;

			return true;
		}

		$report['kept'][] = $name;

		return false;
	}

	/**
	 * The data query XML goes only if the plugin wrote it there and nobody
	 * changed it since.
	 *
	 * @param array{removed: list<string>, kept: list<string>} $report
	 * @param CactiTemplateRepository $templates
	 */
	private function removeDataQueryFile(array &$report, CactiTemplateRepository $templates): void {
		$target = $this->dataQueryTarget();
		$source = (string) @file_get_contents($this->pluginPath . '/resource/script_server/' . TemplateXml::DATA_QUERY_FILE);

		if (!is_file($target)) {
			return;
		}

		if ($this->cacti->setting(self::SETTING_FILE) === $target && $source !== '' && @file_get_contents($target) === $source && @unlink($target)) {
			$report['removed'][] = TemplateXml::DATA_QUERY_FILE;

			$templates->deleteSetting(self::SETTING_FILE);

			return;
		}

		$report['kept'][] = TemplateXml::DATA_QUERY_FILE;
	}

	/**
	 * import_xml_data() returns false or a list of objects per type, each
	 * with a result of success or fail.
	 * @param mixed $result
	 * @return bool
	 */
	private static function succeeded(mixed $result): bool {
		if (!is_array($result) || $result === []) {
			return false;
		}

		foreach ($result as $type => $objects) {
			if ($type === 'files' || !is_array($objects)) {
				continue;
			}

			foreach ($objects as $object) {
				if (is_array($object) && ($object['result'] ?? '') === 'fail') {
					return false;
				}
			}
		}

		return true;
	}
}
