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
 * One GPU's values from one collection, in model units, with the time the
 * tool took the sample. A null value is unknown, never zero.
 */
final readonly class Reading {
	/**
	 * Construct one GPU reading in model units.
	 *
	 * @param GpuReport             $report           validated GPU identity report
	 * @param int                   $observedAt       Unix timestamp when the sample was taken
	 * @param array<string, ?float> $values           metric name => value, for every
	 *                                                model field the backend reads
	 * @param ?int                  $mask             raw NVIDIA clock event bitmask, or null
	 * @param string                $eccMode          EccMode value, or '' when not reported
	 * @param array<string, ?float> $vendorExtensions vendor-specific fields such as
	 *                                                AMD throttle accumulators and extended telemetry
	 */
	public function __construct(
		public GpuReport $report,
		public int $observedAt,
		public array $values,
		public ?int $mask,
		// EccMode value, or '' when the backend does not report it.
		public string $eccMode = '',
		public array $vendorExtensions = [],
	) {
	}

	/**
	 * Return a strongly typed view over extended enterprise telemetry fields
	 * stored in $vendorExtensions.
	 *
	 * @return ExtendedGpuTelemetry
	 */
	public function extendedTelemetry(): ExtendedGpuTelemetry {
		return ExtendedGpuTelemetry::fromExtensions($this->vendorExtensions);
	}
}
