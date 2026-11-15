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
 * GPU identity records. The unique key on (vendor, uuid) is the last line of
 * defence for REQ-ID-001: a second record for one UUID fails to insert even
 * if two writers race.
 */
final class GpuRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * The records IdentityReconciler needs for one snapshot: everything at the
	 * node, and any record elsewhere that the snapshot reports.
	 *
	 * @return list<GpuRecord>
	 */
	public function forReconcile(Node $node, GpuSnapshot $snapshot): array {
		$uuids = $snapshot->uuids();
		$sql   = 'SELECT * FROM plugin_gpu_gpu WHERE node_id = ?';

		if ($uuids !== []) {
			$sql .= ' OR uuid IN (' . implode(', ', array_fill(0, count($uuids), '?')) . ')';
		}

		return $this->records(db_fetch_assoc_prepared($sql . ' ORDER BY id', array_merge([$node->id], $uuids), true, $this->connection));
	}

	/**
	 * @return list<GpuRecord>
	 */
	public function all(): array {
		return $this->records(db_fetch_assoc_prepared('SELECT * FROM plugin_gpu_gpu ORDER BY node_id, gpu_index, uuid', [], true, $this->connection));
	}

	/**
	 * @return list<GpuRecord>
	 */
	public function pendingMoves(): array {
		return $this->records(db_fetch_assoc_prepared('SELECT * FROM plugin_gpu_gpu WHERE pending_node_id > 0 ORDER BY pending_since, id', [], true, $this->connection));
	}

	/**
	 * Write a reconciliation. Each statement stands alone and the next
	 * collection recomputes from what was stored, so a run stopped part way
	 * leaves records that the next run completes rather than corrupts.
	 */
	public function apply(Reconciliation $changes): bool {
		$ok = true;

		foreach ($changes->created as $record) {
			$row = $record->toRow();
			$ok  = db_execute_prepared('INSERT INTO plugin_gpu_gpu (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row), true, $this->connection) && $ok;
		}

		foreach ($changes->updated as $record) {
			$row = $record->toRow();

			// Vendor and UUID are the key; an update never rewrites them.
			unset($row['vendor'], $row['uuid']);

			$set = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($row)));
			$ok  = db_execute_prepared("UPDATE plugin_gpu_gpu SET $set WHERE id = ?", array_merge(array_values($row), [$record->id]), true, $this->connection) && $ok;
		}

		return $ok;
	}

	/**
	 * The operator's confirmation of a pending move (REQ-ID-004). The GPU
	 * keeps its record, and so its history; only its node changes.
	 */
	public function confirmMove(int $id): bool {
		$ok = db_execute_prepared("UPDATE plugin_gpu_gpu SET node_id = pending_node_id, pending_node_id = 0, pending_since = 0, missing_count = 0, presence = 'present' WHERE id = ? AND pending_node_id > 0", [$id], true, $this->connection);

		return $ok && db_affected_rows($this->connection) === 1;
	}

	public function rejectMove(int $id): bool {
		$ok = db_execute_prepared('UPDATE plugin_gpu_gpu SET pending_node_id = 0, pending_since = 0 WHERE id = ? AND pending_node_id > 0', [$id], true, $this->connection);

		return $ok && db_affected_rows($this->connection) === 1;
	}

	/**
	 * @return list<GpuRecord>
	 */
	private function records(mixed $rows): array {
		$records = [];

		foreach (Row::rows($rows) as $row) {
			$record = GpuRecord::fromRow($row);

			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}
}
