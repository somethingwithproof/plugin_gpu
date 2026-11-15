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

	public function __construct(
		private readonly CactiObjectRepository $cacti,
		private readonly LockRepository $locks,
		private readonly string $pluginPath,
		private readonly string $cactiPath,
	) {
	}

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

	public function dataQueryTarget(): string {
		return $this->cactiPath . '/resource/script_server/' . TemplateXml::DATA_QUERY_FILE;
	}

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
	 * Whether every top-level object the template file defines exists.
	 */
	public function installed(): bool {
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
	 * import_xml_data() returns false or a list of objects per type, each
	 * with a result of success or fail.
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
