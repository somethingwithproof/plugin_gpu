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
 * The reads behind the script server input (REQ-SS-001): GPU records,
 * stored samples, and collection results, never a GPU node. Each call is
 * one indexed query, so a poll stays well under 50 ms with 10,000 GPUs in
 * the cache (REQ-SS-005).
 */
final class CacheRepository {
	private const COLUMNS = 'g.id, g.vendor, g.uuid, g.gpu_index, g.name, g.pci_bus_id, g.presence';

		/**
	 * Construct a CacheRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Every GPU at the node of a Cacti device that the data query lists:
	 * present and absent alike, so Cacti keeps the data sources of absent
	 * GPUs attached (REQ-INDEX-002), but not those an operator removed.
	 *
	 * @return list<array<array-key, mixed>>|null null when the read failed
	 * @param int $hostId
	 */
	public function listed(int $hostId): ?array {
		$rows = db_fetch_assoc_prepared(
			'SELECT ' . self::COLUMNS . ' FROM plugin_gpu_node AS n
			INNER JOIN plugin_gpu_gpu AS g ON g.node_id = n.id
			LEFT JOIN plugin_gpu_retired AS r ON r.gpu_id = g.id
			WHERE n.host_id = ? AND r.gpu_id IS NULL
			ORDER BY g.gpu_index IS NULL, g.gpu_index, g.vendor, g.uuid',
			[$hostId],
			true,
			$this->connection,
		);

		return is_array($rows) ? Row::rows($rows) : null;
	}

	/**
	 * Every listed GPU with its stored sample and its node's latest
	 * collection, for a query of one value across the indexes.
	 *
	 * @return list<array<array-key, mixed>>|null
	 * @param int $hostId
	 */
	public function samples(int $hostId): ?array {
		$rows = db_fetch_assoc_prepared(self::sampleSelect() . ' WHERE n.host_id = ? AND r.gpu_id IS NULL', [$hostId], true, $this->connection);

		return is_array($rows) ? Row::rows($rows) : null;
	}

	/**
	 * One GPU's stored sample, by the data query index (vendor/UUID).
	 *
	 * @return array<array-key, mixed>|null
	 * @param int $hostId
	 * @param Vendor $vendor
	 * @param string $uuid
	 */
	public function sample(int $hostId, Vendor $vendor, string $uuid): ?array {
		$row = db_fetch_row_prepared(self::sampleSelect() . ' WHERE g.vendor = ? AND g.uuid = ? AND n.host_id = ? AND r.gpu_id IS NULL', [$vendor->value, $uuid, $hostId], true, $this->connection);

		return is_array($row) && $row !== [] ? $row : null;
	}

		/**
	 * Sample select.
	 *
	 * @return string
	 */
	private static function sampleSelect(): string {
		return 'SELECT ' . self::COLUMNS . ', o.observed_at, o.reported_at, o.' . implode(', o.', Schema::observationMetrics()) . ',
				c.state AS collection_state, c.finished_at AS collection_finished_at
			FROM plugin_gpu_gpu AS g
			INNER JOIN plugin_gpu_node AS n ON n.id = g.node_id
			LEFT JOIN plugin_gpu_retired AS r ON r.gpu_id = g.id
			LEFT JOIN plugin_gpu_observation AS o ON o.gpu_id = g.id AND o.node_id = g.node_id
			LEFT JOIN plugin_gpu_collection AS c ON c.node_id = g.node_id';
	}
}
