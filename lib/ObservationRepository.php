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
 * The latest sample of each GPU (Schema, plugin_gpu_observation).
 */
final class ObservationRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Store one GPU's reading. The values replace the stored ones only when
	 * the tool took this sample later than the stored one, so snmpd handing
	 * back a cached sample, or a slow run finishing after a faster one, never
	 * moves a value back in time. reported_at always moves, because the GPU
	 * was reported either way.
	 */
	public function store(int $gpuId, int $nodeId, Reading $reading, string $backend, string $toolVersion, int $now): bool {
		$columns = Schema::OBSERVATION_METRICS;
		$values  = [];

		foreach ($columns as $column) {
			$values[] = $reading->values[$column] ?? null;
		}

		$all    = array_merge(['gpu_id', 'node_id', 'observed_at', 'ingested_at', 'reported_at', 'backend', 'tool_version'], $columns, ['thr_mask']);
		$newer  = 'VALUES(observed_at) > observed_at';
		$update = ['node_id = VALUES(node_id)'];

		// observed_at is assigned last: MySQL applies the assignments in
		// order, so every other column still compares against the old time.
		foreach (array_merge(['ingested_at', 'backend', 'tool_version'], $columns, ['thr_mask']) as $column) {
			$update[] = "$column = IF($newer, VALUES($column), $column)";
		}

		$update[] = 'reported_at = VALUES(reported_at)';
		$update[] = "observed_at = IF($newer, VALUES(observed_at), observed_at)";

		$sql = 'INSERT INTO plugin_gpu_observation (' . implode(', ', $all) . ') VALUES (' . implode(', ', array_fill(0, count($all), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $update);

		return (bool) db_execute_prepared($sql, array_merge([$gpuId, $nodeId, $reading->observedAt, $now, $now, $backend, $toolVersion], $values, [$reading->mask]), true, $this->connection);
	}
}
