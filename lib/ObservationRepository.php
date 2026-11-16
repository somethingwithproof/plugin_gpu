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
	 * What the health page shows of each GPU's latest sample, by GPU record
	 * id: when it was taken, by which backend and tool version, the raw
	 * clock event mask, and the ECC mode. One query for every GPU.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function summaries(): array {
		$rows = db_fetch_assoc_prepared('SELECT gpu_id, observed_at, backend, tool_version, thr_mask, ecc_mode FROM plugin_gpu_observation', [], true, $this->connection);
		$out  = [];

		foreach (is_array($rows) ? Row::rows($rows) : [] as $row) {
			$out[Row::int($row, 'gpu_id')] = $row;
		}

		return $out;
	}

	/**
	 * Store one GPU's reading. The values replace the stored ones only when
	 * the tool took this sample later than the stored one, so snmpd handing
	 * back a cached sample, or a slow run finishing after a faster one, never
	 * moves a value back in time. reported_at always moves, because the GPU
	 * was reported either way.
	 *
	 * The new ECC errors are computed here, against the stored sample, in
	 * the same statement: the new total minus the stored one. The result is
	 * unknown when either total is unknown, and when the new total is lower,
	 * which is a reset at driver load (REQ-ERR-003), so a reset is never
	 * read as a negative count or as zero.
	 */
	public function store(int $gpuId, int $nodeId, Reading $reading, string $backend, string $toolVersion, int $now, int $previousRead = 0): bool {
		$columns = array_values(array_diff(Schema::observationMetrics(), array_keys(MetricModel::DELTAS)));
		$values  = [];

		foreach ($columns as $column) {
			$values[] = $reading->values[$column] ?? null;
		}

		$all    = array_merge(['gpu_id', 'node_id', 'observed_at', 'ingested_at', 'reported_at', 'backend', 'tool_version'], $columns, ['thr_mask', 'ecc_mode']);
		$newer  = 'VALUES(observed_at) > observed_at';
		$update = ['node_id = VALUES(node_id)'];
		// A failed/interrupted collection, or a successful one which omitted
		// this GPU, breaks the delta baseline. Keep the old observation time
		// and totals for age and diagnostics, but never bridge that gap.
		$continuous = $previousRead > 0 ? 'reported_at >= ' . $previousRead : '0';

		// MySQL applies the assignments in order, and a later one sees the
		// columns earlier ones set. The deltas are assigned before the totals
		// change, and observed_at last, so every other column still compares
		// against the stored sample.
		foreach (MetricModel::DELTAS as $delta => $total) {
			$update[] = "$delta = IF($continuous, IF($newer, IF(VALUES($total) >= $total, VALUES($total) - $total, NULL), $delta), NULL)";
		}

		foreach (array_merge(['ingested_at', 'backend', 'tool_version'], $columns, ['thr_mask', 'ecc_mode']) as $column) {
			$update[] = "$column = IF($newer, VALUES($column), $column)";
		}

		$update[] = 'reported_at = VALUES(reported_at)';
		$update[] = "observed_at = IF($newer, VALUES(observed_at), observed_at)";

		$sql = 'INSERT INTO plugin_gpu_observation (' . implode(', ', $all) . ') VALUES (' . implode(', ', array_fill(0, count($all), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $update);

		return (bool) db_execute_prepared($sql, array_merge([$gpuId, $nodeId, $reading->observedAt, $now, $now, $backend, $toolVersion], $values, [$reading->mask, $reading->eccMode]), true, $this->connection);
	}
}
