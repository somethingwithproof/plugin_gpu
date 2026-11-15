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
	 * @param array<string, ?float> $values metric name => value, for every
	 *                                      model field the backend reads
	 */
	public function __construct(
		public GpuReport $report,
		public int $observedAt,
		public array $values,
		public ?int $mask,
	) {
	}
}
