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
 * How the last collection at a node ended (PLAN, Measurement state).
 *
 * Every state but Ok and Empty is an error: it writes no values and marks
 * no GPU absent (REQ-EXEC-006, REQ-INV-003).
 */
enum ExecutionState: string {
	case Ok               = 'ok';
	// The tool succeeded and reported no GPU.
	case Empty            = 'empty';
	case Unreachable      = 'unreachable';
	// The agent answers but has no extend entry under the documented name.
	case NotConfigured    = 'not_configured';
	case TimedOut         = 'timed_out';
	case PermissionDenied = 'permission_denied';
	case DriverNotLoaded  = 'driver_not_loaded';
	case ToolMissing      = 'tool_missing';
	case ToolError        = 'tool_error';
	case Truncated        = 'truncated';
	case InvalidOutput    = 'invalid_output';
	// Another collection held the node (REQ-EXEC-005).
	case Skipped          = 'skipped';

	public function succeeded(): bool {
		return $this === self::Ok || $this === self::Empty;
	}

	/**
	 * The state for an extend command's exit status. nvidia-smi documents 4
	 * as no permission and 9 as driver not loaded; timeout(1) exits 124 when
	 * it stops the command and 137 when it has to kill it; 126 and 127 are
	 * the shell convention env(1) follows for a program it cannot run.
	 */
	public static function fromExitStatus(int $status): self {
		return match ($status) {
			0        => self::Ok,
			124, 137 => self::TimedOut,
			4        => self::PermissionDenied,
			9        => self::DriverNotLoaded,
			126, 127 => self::ToolMissing,
			default  => self::ToolError,
		};
	}

	public function label(): string {
		return match ($this) {
			self::Ok               => __('Collected', 'gpu'),
			self::Empty            => __('No GPUs reported', 'gpu'),
			self::Unreachable      => __('SNMP agent unreachable', 'gpu'),
			self::NotConfigured    => __('Extend entry missing', 'gpu'),
			self::TimedOut         => __('Timed out', 'gpu'),
			self::PermissionDenied => __('Permission denied', 'gpu'),
			self::DriverNotLoaded  => __('Driver not loaded', 'gpu'),
			self::ToolMissing      => __('Tool not found', 'gpu'),
			self::ToolError        => __('Tool error', 'gpu'),
			self::Truncated        => __('Output truncated', 'gpu'),
			self::InvalidOutput    => __('Output rejected', 'gpu'),
			self::Skipped          => __('Skipped: already running', 'gpu'),
		};
	}
}
