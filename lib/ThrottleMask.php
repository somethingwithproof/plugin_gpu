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
 * The clock event reasons bitmask (research/clock-event-reasons.md).
 *
 * The help text says only "Bitmask ... See nvml.h", and whether nvidia-smi
 * prints it in hexadecimal or decimal is a live check, so both are read. A
 * 0x prefix is hexadecimal and must not be taken for an unknown value.
 */
final class ThrottleMask {
	public static function parse(string $raw): ?int {
		$raw = trim($raw);

		if (preg_match('/^0x([0-9a-f]{1,16})$/i', $raw, $hex) === 1) {
			// Bits above 2^62 would not fit an int; no defined bit is near.
			return strlen(ltrim($hex[1], '0')) <= 15 ? (int) hexdec($hex[1]) : null;
		}

		if (preg_match('/^[0-9]{1,18}$/', $raw) === 1) {
			return (int) $raw;
		}

		return null;
	}

	/**
	 * One indicator per decoded reason: 1 when its bit is set, 0 when clear,
	 * unknown when the mask is.
	 *
	 * @return array<string, ?float>
	 */
	public static function indicators(?int $mask): array {
		$out = [];

		foreach (MetricModel::BITS as $name => $bit) {
			$out[$name] = $mask === null ? null : (($mask & $bit) !== 0 ? 1.0 : 0.0);
		}

		return $out;
	}

	/**
	 * The raw mask as the health page shows it: 16 hexadecimal digits, and
	 * the bits that have no indicator, so a reason the decoder does not know
	 * stays visible (REQ-THR-005).
	 */
	public static function describe(?int $mask): string {
		if ($mask === null) {
			return __('Unknown', 'gpu');
		}

		$text  = sprintf('0x%016X', $mask);
		$other = $mask & ~array_sum(MetricModel::BITS);

		return $other === 0 ? $text : $text . '; ' . __('bits without an indicator: %s', sprintf('0x%X', $other), 'gpu');
	}
}
