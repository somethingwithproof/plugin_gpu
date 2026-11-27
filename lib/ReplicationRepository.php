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
 * Queries plugin configuration tables scoped to a remote poller so
 * replicate_out can push only that poller's GPU nodes and records
 * (REQ-REM-002, REQ-REM-004).
 */
final class ReplicationRepository {
		/**
	 * Construct a ReplicationRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Rows for every replicated plugin table belonging to $pollerId.
	 *
	 * @return array<string, list<array<array-key, mixed>>>
	 * @param int $pollerId
	 */
	public function tablesForPoller(int $pollerId): array {
		if ($pollerId < 1) {
			return [];
		}

		$nodes = Row::rows(db_fetch_assoc_prepared(
			'SELECT n.* FROM plugin_gpu_node AS n
			INNER JOIN host AS h ON h.id = n.host_id
			WHERE h.poller_id = ?
			ORDER BY n.id',
			[$pollerId],
			true,
			$this->connection,
		));

		$gpus = Row::rows(db_fetch_assoc_prepared(
			'SELECT g.* FROM plugin_gpu_gpu AS g
			INNER JOIN plugin_gpu_node AS n ON n.id = g.node_id
			INNER JOIN host AS h ON h.id = n.host_id
			WHERE h.poller_id = ?
			ORDER BY g.id',
			[$pollerId],
			true,
			$this->connection,
		));

		$retired = Row::rows(db_fetch_assoc_prepared(
			'SELECT r.* FROM plugin_gpu_retired AS r
			INNER JOIN plugin_gpu_gpu AS g ON g.id = r.gpu_id
			INNER JOIN plugin_gpu_node AS n ON n.id = g.node_id
			INNER JOIN host AS h ON h.id = n.host_id
			WHERE h.poller_id = ?
			ORDER BY r.gpu_id',
			[$pollerId],
			true,
			$this->connection,
		));

		return [
			'plugin_gpu_node'    => $nodes,
			'plugin_gpu_gpu'     => $gpus,
			'plugin_gpu_retired' => $retired,
		];
	}
}
