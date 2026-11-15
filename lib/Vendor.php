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
 * GPU vendors whose identity rule is settled. ADR 0003 is accepted for NVIDIA
 * only; AMD joins when research #19 shows its UUIDs are stable, so no AMD
 * record can be created before then.
 */
enum Vendor: string {
	case Nvidia = 'nvidia';

	public function label(): string {
		return match ($this) {
			self::Nvidia => 'NVIDIA',
		};
	}
}
