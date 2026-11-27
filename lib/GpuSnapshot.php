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
	 * Construct a validated GPU collection snapshot.
	 *
	 * @param string               $tool        collector tool name
	 * @param string               $toolVersion collector or driver version
	 * @param list<GpuReport>      $reports     validated GPU reports in this snapshot
	 * @param array<string, true>  $keys        O(1) identity key index mirroring $reports
	 */
	private function __construct(
		public string $tool,
		public string $toolVersion,
		public array $reports,
		private readonly array $keys,
	) {
	}

	/**
	 * Create a validated snapshot, refusing duplicate UUIDs or physical PCI bus IDs.
	 *
	 * @param string          $tool        collector tool name
	 * @param string          $toolVersion collector or driver version
	 * @param list<GpuReport> $reports     GPU reports from one collection
	 *
	 * @return self
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

			// MIG instances share their parent GPU's PCI bus ID; physical GPUs must be unique per bus ID.
			if (!$report->isMig()) {
				if (isset($buses[$report->pciBusId])) {
					throw new \InvalidArgumentException('Two GPUs in one collection report the same PCI bus ID');
				}

				$buses[$report->pciBusId] = true;
			}

			$keys[$report->key()] = true;
		}

		return new self(GpuReport::clean($tool, 32), GpuReport::clean($toolVersion, 64), $reports, $keys);
	}

	/**
	 * Whether this snapshot reported the given vendor/UUID identity key in O(1) time.
	 *
	 * @param string $key vendor/UUID key from GpuRecord::key() or GpuReport::key()
	 *
	 * @return bool
	 */
	public function has(string $key): bool {
		return isset($this->keys[$key]);
	}

	/**
	 * List every reported GPU UUID in snapshot order.
	 *
	 * @return list<string>
	 */
	public function uuids(): array {
		return array_map(static fn (GpuReport $report): string => $report->uuid, $this->reports);
	}
}
