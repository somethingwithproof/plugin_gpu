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
 * The GPU data query's script server input (resource/script_server/
 * plugin_gpu.xml). Cacti's script server includes this file and calls
 * ss_gpu() for each get; index and query also run it from the command line:
 *
 *   php plugins/gpu/ss_gpu.php <host id> index|num_indexes|query|get [field] [index]
 *
 * It reads only the plugin's cache, never a GPU node (REQ-SS-001), and never
 * throws (REQ-SS-002). This file must parse on PHP 8.0.
 */

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../include/cli_check.php');
	include_once(__DIR__ . '/setup.php');

	array_shift($argv);

	print call_user_func_array('ss_gpu', $argv);
} else {
	include_once(__DIR__ . '/setup.php');
}

/**
 * @param mixed $host_id
 * @param mixed $cmd
 * @param mixed $arg1
 * @param mixed $arg2
 */
function ss_gpu($host_id = '', $cmd = '', $arg1 = '', $arg2 = ''): string {
	$fallback = $cmd === 'get' ? 'U' : '';

	if (!gpu_boot()) {
		return $fallback;
	}

	try {
		return gpu_script_server()->call((string) $host_id, (string) $cmd, (string) $arg1, (string) $arg2);
	} catch (Throwable $e) {
		cacti_log('ERROR: The GPU script server input failed: ' . get_class($e), false, 'GPU');

		return $fallback;
	}
}
