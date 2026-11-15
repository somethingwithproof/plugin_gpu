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
 * One field of the common metric model (ADR 0009, REQ-METRIC-001): its data
 * source name, unit, kind, range, and source per backend.
 *
 * A field is read from an nvidia-smi query field, decoded from one bit of
 * the clock event reasons mask, or computed. The DCGM and AMD columns
 * document the source each later backend will read; milestone 3 implements
 * the nvidia-smi column only.
 */
final readonly class Metric {
	public const GAUGE     = 'gauge';
	public const INDICATOR = 'indicator';

	public function __construct(
		// Data source item name: Cacti allows ^[a-zA-Z0-9_]{1,19}$.
		public string $name,
		public string $label,
		public string $unit,
		public string $kind,
		public ?float $min,
		public ?float $max,
		// The nvidia-smi --query-gpu field, or null when not read from one.
		public ?string $nvidiaField,
		// Multiplier from the nvidia-smi value to the model unit.
		public float $nvidiaScale,
		// The clock event reasons bit an indicator decodes, or null.
		public ?int $maskBit,
		public string $dcgm,
		// Multiplier from the DCGM field named first in $dcgm to the model unit.
		public float $dcgmScale,
		public string $amd,
	) {
	}

	/**
	 * A source value converted to the model unit; null stays unknown.
	 */
	public static function scale(?float $value, float $factor): ?float {
		return $value === null ? null : $value * $factor;
	}

	/**
	 * The range as RRDtool takes it: a number, or U for unbounded.
	 */
	public function rrdMin(): string {
		return $this->min === null ? 'U' : self::number($this->min);
	}

	public function rrdMax(): string {
		return $this->max === null ? 'U' : self::number($this->max);
	}

	private static function number(float $value): string {
		return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
	}
}
