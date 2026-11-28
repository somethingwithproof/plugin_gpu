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
 * How recent a node's or GPU's newest sample is, against the interval it
 * is expected at (REQ-HEALTH-001). The page refresh time never counts.
 */
enum FreshnessState: string {
	case Current = 'current';
	case Delayed = 'delayed';
	case Stale   = 'stale';
	case Never   = 'never';
	case Ahead   = 'ahead';

	/**
	 * Current while the sample is at most one and a half intervals old (one
	 * interval plus the drift between Cacti's poll and the collection),
	 * delayed after that, and stale once it is too old to graph, which is
	 * when the script server input starts printing U (Freshness). A sample
	 * stamped further ahead of Cacti's clock than skew explains is ahead:
	 * its values print U, so it must not read as current.
	 * @param int $observedAt
	 * @param int $now
	 * @param int $interval
	 * @param int $staleAfter
	 * @return self
	 */
	public static function of(int $observedAt, int $now, int $interval, int $staleAfter): self {
		if ($observedAt <= 0) {
			return self::Never;
		}

		$age = $now - $observedAt;

		return match (true) {
			$age < -Freshness::SKEW_ALLOWANCE                          => self::Ahead,
			$age <= min(intdiv(max(1, $interval) * 3, 2), $staleAfter) => self::Current,
			$age <= $staleAfter                                        => self::Delayed,
			default                                                    => self::Stale,
		};
	}

		/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ($this) {
			self::Current => __('Current', 'gpu'),
			self::Delayed => __('Delayed', 'gpu'),
			self::Stale   => __('Stale', 'gpu'),
			self::Never   => __('Never observed', 'gpu'),
			self::Ahead   => __('Ahead of the Cacti server\'s clock', 'gpu'),
		};
	}
}
