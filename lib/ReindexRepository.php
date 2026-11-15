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
 * Data query reindexes the plugin owes node devices (Schema,
 * plugin_gpu_reindex). A request is removed only once a reindex has run.
 */
final class ReindexRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Ask for a reindex. A request already waiting keeps its first time and
	 * its attempts, and its generation moves on, so a reindex that read the
	 * request before this one does not remove it.
	 */
	public function request(int $nodeId, string $reason, int $now): void {
		db_execute_prepared('INSERT INTO plugin_gpu_reindex (node_id, requested_at, reason, generation) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE reason = VALUES(reason), generation = generation + 1', [$nodeId, $now, mb_substr($reason, 0, 64)], true, $this->connection);
	}

	/**
	 * @return array<int, array{requested_at: int, reason: string, attempts: int, detail: string, generation: int}> node id => request
	 */
	public function pending(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_gpu_reindex ORDER BY requested_at, node_id', [], true, $this->connection)) as $row) {
			$out[Row::int($row, 'node_id')] = [
				'requested_at' => Row::int($row, 'requested_at'),
				'reason'       => Row::string($row, 'reason'),
				'attempts'     => Row::int($row, 'attempts', 0, 0),
				'detail'       => Row::string($row, 'detail'),
				'generation'   => Row::int($row, 'generation', 0, 0),
			];
		}

		return $out;
	}

	/**
	 * Record an attempt that could not reindex, such as a device Cacti
	 * holds down; the request stays for the next cycle.
	 */
	public function deferred(int $nodeId, string $detail, int $now): void {
		db_execute_prepared('UPDATE plugin_gpu_reindex SET attempts = attempts + 1, last_attempt_at = ?, detail = ? WHERE node_id = ?', [$now, mb_substr($detail, 0, 255), $nodeId], true, $this->connection);
	}

	/**
	 * Remove a request that a reindex has served. Only the request as it was
	 * read is removed: one made after the reindex started has a later generation
	 * and stays.
	 */
	public function done(int $nodeId, int $generation): void {
		db_execute_prepared('DELETE FROM plugin_gpu_reindex WHERE node_id = ? AND generation = ?', [$nodeId, $generation], true, $this->connection);
	}
}
