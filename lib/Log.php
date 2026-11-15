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
 * Plugin log lines go through cacti_log() under the GPU tag, so they share
 * Cacti's log file, rotation, and verbosity filter (REQ-CODE-006). Callers
 * name nodes and GPUs by their plugin record ids at the default level, never
 * by hostname, serial number, or raw tool output.
 */
final class Log {
	public static function debug(string $message): void {
		cacti_log('DEBUG: ' . $message, false, 'GPU', POLLER_VERBOSITY_DEBUG);
	}

	public static function info(string $message): void {
		cacti_log('NOTE: ' . $message, false, 'GPU', POLLER_VERBOSITY_MEDIUM);
	}

	public static function warning(string $message): void {
		cacti_log('WARNING: ' . $message, false, 'GPU');
	}

	public static function error(string $message): void {
		cacti_log('ERROR: ' . $message, false, 'GPU');
	}
}
