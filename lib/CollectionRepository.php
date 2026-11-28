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
 * The latest collection result per node: one row each, overwritten by every
 * run, so the table never grows with time.
 */
final class CollectionRepository {
		/**
	 * Construct a CollectionRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

		/**
	 * Find.
	 *
	 * @param int $nodeId
	 * @return ?CollectionStatus
	 */
	public function find(int $nodeId): ?CollectionStatus {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_gpu_collection WHERE node_id = ?', [$nodeId], true, $this->connection);

		return is_array($row) && $row !== [] ? CollectionStatus::fromRow($row) : null;
	}

	/**
	 * @return array<int, CollectionStatus> node id => status
	 */
	public function all(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_gpu_collection', [], true, $this->connection)) as $row) {
			$status = CollectionStatus::fromRow($row);

			$out[$status->nodeId] = $status;
		}

		return $out;
	}

		/**
	 * Start.
	 *
	 * @param int $nodeId
	 * @param int $now
	 */
	public function start(int $nodeId, int $now): void {
		db_execute_prepared('INSERT INTO plugin_gpu_collection (node_id, started_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE started_at = VALUES(started_at)', [$nodeId, $now], true, $this->connection);
	}

		/**
	 * Finish.
	 *
	 * @param int $nodeId
	 * @param ExecutionState $state
	 * @param string $detail
	 * @param int $now
	 * @param int $observedAt
	 * @param int $gpus
	 * @param int $newGpus
	 * @param int $rejectedLines
	 * @return bool
	 */
	public function finish(int $nodeId, ExecutionState $state, string $detail, int $now, int $observedAt = 0, int $gpus = 0, int $newGpus = 0, int $rejectedLines = 0): bool {
		$ok = $state->succeeded() ? $now : 0;

		return (bool) db_execute_prepared(
			'INSERT INTO plugin_gpu_collection (node_id, state, detail, started_at, finished_at, observed_at, gpus, new_gpus, rejected_lines, skipped, last_ok_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
			ON DUPLICATE KEY UPDATE state = VALUES(state), detail = VALUES(detail), finished_at = VALUES(finished_at),
				observed_at = IF(VALUES(observed_at) > 0, VALUES(observed_at), observed_at), gpus = VALUES(gpus), new_gpus = VALUES(new_gpus),
				rejected_lines = VALUES(rejected_lines), skipped = 0, last_ok_at = IF(VALUES(last_ok_at) > 0, VALUES(last_ok_at), last_ok_at)',
			[$nodeId, $state->value, mb_substr($detail, 0, 255), $now, $now, $observedAt, $gpus, $newGpus, $rejectedLines, $ok],
			true,
			$this->connection,
		);
	}

	/**
	 * Count a run that found the node already being collected. The result
	 * of the run in progress stays.
	 * @param int $nodeId
	 */
	public function skipped(int $nodeId): void {
		db_execute_prepared('INSERT INTO plugin_gpu_collection (node_id, skipped) VALUES (?, 1) ON DUPLICATE KEY UPDATE skipped = skipped + 1', [$nodeId], true, $this->connection);
	}
}
