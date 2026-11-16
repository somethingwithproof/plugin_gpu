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
 * Decides what the script server input prints for one stored value
 * (REQ-SS-002, REQ-SS-004, REQ-INDEX-003, REQ-METRIC-006). Null prints U.
 *
 * A value is printed only while all of these hold: the GPU is present, the
 * node's latest collection succeeded and reported it, and the tool took the
 * sample no longer ago than the staleness limit. Otherwise it is unknown, so
 * a stopped or failing collection shows as a gap, never as a held value or
 * a zero.
 *
 * age is the exception: it is the seconds since the sample was taken, from
 * the sample's own time, and stays numeric whenever a sample exists, so an
 * alert on it fires when collection stops. It is unknown only for a GPU
 * never sampled, or a sample stamped further in the future than clock skew
 * explains.
 */
final class Freshness {
	// Seconds a node's clock may run ahead of Cacti's before its sample
	// time is distrusted.
	public const SKEW_ALLOWANCE = 120;

	/**
	 * @param array<array-key, mixed> $row the GPU's record, observation, and
	 *                                     its node's latest collection, as
	 *                                     CacheRepository reads them
	 */
	public static function value(string $field, array $row, int $now, int $staleAfter): ?float {
		$observed = Row::int($row, 'observed_at', 0, 0);

		if ($observed === 0) {
			return null;
		}

		$age = $now - $observed;

		if ($age < -self::SKEW_ALLOWANCE) {
			return null;
		}

		if ($field === 'age') {
			return (float) max(0, $age);
		}

		$state = ExecutionState::tryFrom(Row::string($row, 'collection_state'));

		if (Row::string($row, 'presence') !== Presence::Present->value || $state === null || !$state->succeeded()) {
			return null;
		}

		// Reported by an earlier collection, but not by the latest.
		if (Row::int($row, 'reported_at', 0, 0) < Row::int($row, 'collection_finished_at', 0, 0)) {
			return null;
		}

		if ($age > $staleAfter) {
			return null;
		}

		return in_array($field, Schema::observationMetrics(), true) ? Row::float($row, $field) : null;
	}

	/**
	 * A value as Cacti reads it: plain decimal, never exponent notation,
	 * which RRDtool would refuse, and U for unknown.
	 */
	public static function format(?float $value): string {
		if ($value === null || !is_finite($value)) {
			return 'U';
		}

		if (floor($value) === $value && abs($value) < 1e15) {
			return sprintf('%.0f', $value);
		}

		$text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

		return $text === '-0' ? '0' : $text;
	}
}
