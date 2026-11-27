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
 * The record changes one snapshot calls for. GpuRepository::apply() writes
 * them; nothing else does.
 */
final readonly class Reconciliation {
	/**
	 * @param list<GpuRecord>        $created new identities, with id 0
	 * @param list<GpuRecord>        $updated existing records in their new state
	 * @param array<int, GpuRecord> $read    each updated record as it was read,
	 *                                        by id, so a write can check that
	 *                                        nothing changed it since
	 */
	public function __construct(
		public array $created = [],
		public array $updated = [],
		public array $read = [],
	) {
	}

		/**
	 * Is empty.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->created === [] && $this->updated === [];
	}
}
