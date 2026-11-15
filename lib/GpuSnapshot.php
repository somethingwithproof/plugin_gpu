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
 * Everything one successful collection at one node reported. A failed
 * collection produces no snapshot, so it can never mark a GPU absent.
 */
final readonly class GpuSnapshot {
	/**
	 * @param list<GpuReport> $reports
	 */
	private function __construct(
		public string $tool,
		public string $toolVersion,
		public array $reports,
	) {
	}

	/**
	 * @param list<GpuReport> $reports
	 *
	 * @throws \InvalidArgumentException when two reports share a UUID or a bus
	 *                                   ID, which no real node produces; the
	 *                                   output was misread and is refused
	 */
	public static function create(string $tool, string $toolVersion, array $reports): self {
		$keys  = [];
		$buses = [];

		foreach ($reports as $report) {
			if (isset($keys[$report->key()])) {
				throw new \InvalidArgumentException('Two GPUs in one collection report the same UUID');
			}

			if (isset($buses[$report->pciBusId])) {
				throw new \InvalidArgumentException('Two GPUs in one collection report the same PCI bus ID');
			}

			$keys[$report->key()]     = true;
			$buses[$report->pciBusId] = true;
		}

		return new self(GpuReport::clean($tool, 32), GpuReport::clean($toolVersion, 64), $reports);
	}

	public function has(string $key): bool {
		foreach ($this->reports as $report) {
			if ($report->key() === $key) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	public function uuids(): array {
		return array_map(static fn (GpuReport $report): string => $report->uuid, $this->reports);
	}
}
