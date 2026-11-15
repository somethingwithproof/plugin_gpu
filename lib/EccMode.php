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
 * Whether a GPU's ECC is on, so the health page can say why its ECC values
 * are unknown (REQ-ERR-002). Stored as the value; '' is not reported.
 */
enum EccMode: string {
	case Enabled     = 'enabled';
	case Disabled    = 'disabled';
	case Unavailable = 'unavailable';
	case Unknown     = '';

	/**
	 * nvidia-smi's ecc.mode.current: "Enabled", "Disabled", or a bracketed
	 * placeholder such as "[N/A]" on a GPU without ECC.
	 */
	public static function fromNvidia(string $raw): self {
		return match (strtolower(trim($raw))) {
			'enabled'                        => self::Enabled,
			'disabled'                       => self::Disabled,
			'[n/a]', 'n/a', '[not supported]' => self::Unavailable,
			default                          => self::Unknown,
		};
	}

	/**
	 * Why the GPU's ECC values are unknown, or '' when ECC is on.
	 */
	public function reason(): string {
		return match ($this) {
			self::Enabled     => '',
			self::Disabled    => __('ECC is disabled on this GPU', 'gpu'),
			self::Unavailable => __('This GPU does not report ECC', 'gpu'),
			self::Unknown     => __('ECC mode not reported', 'gpu'),
		};
	}
}
