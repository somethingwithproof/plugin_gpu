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
 * One NET-SNMP-EXTEND-MIB entry as read: the command's exit status
 * (nsExtendResult), the line count snmpd stored (nsExtendOutNumLines), and
 * the lines (nsExtendOutput2Table), in order.
 */
final readonly class ExtendOutput {
	// net-snmp 5.9.5.2 keeps at most 100 KiB of output and, in practice,
	// 1024 lines (research/snmp-extend.md). Output that reaches either may be
	// cut, so it is refused rather than parsed.
	public const MAX_BYTES = 102400;
	public const MAX_LINES = 1024;

	/**
	 * @param list<string> $lines
	 */
	public function __construct(
		public int $result,
		public int $declaredLines,
		public array $lines,
	) {
	}

	public function truncated(): bool {
		$bytes = 0;

		foreach ($this->lines as $line) {
			$bytes += strlen($line) + 1;
		}

		return count($this->lines) !== $this->declaredLines || count($this->lines) >= self::MAX_LINES || $bytes >= self::MAX_BYTES - 1;
	}
}
