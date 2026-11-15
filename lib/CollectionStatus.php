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
 * How the latest collection at one node went. A node never collected has
 * no status.
 */
final readonly class CollectionStatus {
	public function __construct(
		public int $nodeId,
		// Null while the first collection is still running.
		public ?ExecutionState $state,
		public string $detail,
		public int $startedAt,
		public int $finishedAt,
		public int $observedAt,
		public int $gpus,
		public int $newGpus,
		public int $rejectedLines,
		public int $skipped,
		public int $lastOkAt,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row a plugin_gpu_collection row
	 */
	public static function fromRow(array $row): self {
		$state = Row::string($row, 'state');

		return new self(
			nodeId: Row::int($row, 'node_id'),
			state: $state === '' ? null : (ExecutionState::tryFrom($state) ?? ExecutionState::ToolError),
			detail: Row::string($row, 'detail'),
			startedAt: Row::int($row, 'started_at', 0, 0),
			finishedAt: Row::int($row, 'finished_at', 0, 0),
			observedAt: Row::int($row, 'observed_at', 0, 0),
			gpus: Row::int($row, 'gpus', 0, 0),
			newGpus: Row::int($row, 'new_gpus', 0, 0),
			rejectedLines: Row::int($row, 'rejected_lines', 0, 0),
			skipped: Row::int($row, 'skipped', 0, 0),
			lastOkAt: Row::int($row, 'last_ok_at', 0, 0),
		);
	}

	/**
	 * Whether the latest finished collection succeeded. A run that started
	 * after it and has not finished does not change the answer.
	 */
	public function succeeded(): bool {
		return $this->state !== null && $this->state->succeeded();
	}
}
