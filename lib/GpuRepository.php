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
		/**
	 * Construct a GpuRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * The records IdentityReconciler needs for one snapshot: everything at the
	 * node, and any record elsewhere that the snapshot reports.
	 *
	 * Null when the read fails: an empty list would make every reported GPU
	 * look new and lose the replacement link a stored record provides.
	 *
	 * @return list<GpuRecord>|null
	 * @param Node $node
	 * @param GpuSnapshot $snapshot
	 */
	public function forReconcile(Node $node, GpuSnapshot $snapshot): ?array {
		$uuids = $snapshot->uuids();
		$sql   = 'SELECT * FROM plugin_gpu_gpu WHERE node_id = ?';

		if ($uuids !== []) {
			$sql .= ' OR uuid IN (' . implode(', ', array_fill(0, count($uuids), '?')) . ')';
		}

		$rows = db_fetch_assoc_prepared($sql . ' ORDER BY id', array_merge([$node->id], $uuids), true, $this->connection);

		return is_array($rows) ? $this->records($rows) : null;
	}

	/**
	 * @return list<GpuRecord>
	 */
	public function all(): array {
		return $this->records(db_fetch_assoc_prepared('SELECT * FROM plugin_gpu_gpu ORDER BY node_id, gpu_index, uuid', [], true, $this->connection));
	}

	/**
	 * Insert a new GPU record. The unique key on vendor and UUID refuses a
	 * second record for a GPU already stored, which only a record written
	 * between this run's read and its write can cause; that refusal is
	 * reported as such, and the next collection reconciles from what is
	 * stored. Any other failure is an error.
	 * @param GpuRecord $record
	 * @return bool
	 */
	private function insert(GpuRecord $record): bool {
		$row = $record->toRow();

		if (db_execute_prepared('INSERT INTO plugin_gpu_gpu (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row), false, $this->connection)) {
			return true;
		}

		$existing = db_fetch_row_prepared('SELECT id FROM plugin_gpu_gpu WHERE vendor = ? AND uuid = ?', [$row['vendor'], $row['uuid']], false, $this->connection);
		$stored   = is_array($existing) ? Row::int($existing, 'id') : 0;

		if ($stored > 0) {
			Log::warning(sprintf('Node %d: a new GPU record was not stored because GPU record %d already has its UUID; the next collection reconciles them', $record->nodeId, (int) $stored));
		} else {
			Log::error(sprintf('Node %d: a new GPU record could not be stored', $record->nodeId));
		}

		return false;
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
	 *
	 * An update is written only while the record still has the node and
	 * pending node it was read with. An operator who confirms or rejects a
	 * move between the read and the write changes one of them, and the stale
	 * update then matches no row instead of undoing the operator's choice;
	 * the next collection recomputes from the stored state.
	 * @param Reconciliation $changes
	 * @return bool
	 */
	public function apply(Reconciliation $changes): bool {
		$ok = true;

		foreach ($changes->created as $record) {
			$ok = $this->insert($record) && $ok;
		}

		foreach ($changes->updated as $record) {
			$read = $changes->read[$record->id] ?? null;

			// Without the state it was computed from, an update cannot be
			// checked, so it is not written.
			if ($read === null) {
				$ok = false;

				continue;
			}

			$row = $record->toRow();

			// Vendor, UUID, and MIG parent UUID are immutable identity keys; an update never rewrites them.
			unset($row['vendor'], $row['uuid'], $row['parent_uuid']);

			$set     = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($row)));
			$written = db_execute_prepared("UPDATE plugin_gpu_gpu SET $set WHERE id = ? AND node_id = ? AND pending_node_id = ?", array_merge(array_values($row), [$record->id, $read->nodeId, $read->pendingNodeId]), true, $this->connection);

			if (!$written) {
				$ok = false;
			} elseif (db_affected_rows($this->connection) === 0) {
				Log::debug(sprintf('GPU record %d changed after it was read, so this collection left it alone', $record->id));
			}
		}

		return $ok;
	}

	/**
	 * The operator's confirmation of a pending move (REQ-ID-004). The GPU
	 * keeps its record, and so its history; only its node changes.
	 */
	/**
	 * Move a GPU to the node it was seen at. Only the move the operator was
	 * shown and authorized is applied: if a collection changed the record's
	 * node or pending node since, nothing changes.
	 * @param int $id
	 * @param int $fromNodeId
	 * @param int $toNodeId
	 * @return bool
	 */
	public function confirmMove(int $id, int $fromNodeId, int $toNodeId): bool {
		$ok = db_execute_prepared("UPDATE plugin_gpu_gpu SET node_id = pending_node_id, pending_node_id = 0, pending_since = 0, missing_count = 0, presence = 'present' WHERE id = ? AND node_id = ? AND pending_node_id = ? AND pending_node_id > 0", [$id, $fromNodeId, $toNodeId], true, $this->connection);

		return $ok && db_affected_rows($this->connection) === 1;
	}

		/**
	 * Reject move.
	 *
	 * @param int $id
	 * @param int $fromNodeId
	 * @param int $toNodeId
	 * @return bool
	 */
	public function rejectMove(int $id, int $fromNodeId, int $toNodeId): bool {
		$ok = db_execute_prepared('UPDATE plugin_gpu_gpu SET pending_node_id = 0, pending_since = 0 WHERE id = ? AND node_id = ? AND pending_node_id = ? AND pending_node_id > 0', [$id, $fromNodeId, $toNodeId], true, $this->connection);

		return $ok && db_affected_rows($this->connection) === 1;
	}

	/**
	 * Remove an absent GPU from its node's data query, on an operator's
	 * request (REQ-INDEX-005). A present GPU is refused: the next collection
	 * would only return it.
	 * @param int $id
	 * @param int $nodeId
	 * @param int $userId
	 * @param int $now
	 * @return bool
	 */
	public function retire(int $id, int $nodeId, int $userId, int $now): bool {
		$ok = db_execute_prepared("INSERT IGNORE INTO plugin_gpu_retired (gpu_id, retired_at, retired_by) SELECT id, ?, ? FROM plugin_gpu_gpu WHERE id = ? AND node_id = ? AND presence = 'absent'", [$now, $userId, $id, $nodeId], true, $this->connection);

		return $ok && db_affected_rows($this->connection) === 1;
	}

	/**
	 * @return list<int>
	 */
	public function retiredIds(): array {
		return array_map(static fn (array $row): int => Row::int($row, 'gpu_id'), Row::rows(db_fetch_assoc_prepared('SELECT gpu_id FROM plugin_gpu_retired ORDER BY gpu_id', [], true, $this->connection)));
	}

		/**
	 * Find.
	 *
	 * @param int $id
	 * @return ?GpuRecord
	 */
	public function find(int $id): ?GpuRecord {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_gpu_gpu WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) && $row !== [] ? GpuRecord::fromRow($row) : null;
	}

	/**
	 * Return GPUs an operator removed from the data query to it, because a
	 * collection reported them again (REQ-INDEX-005).
	 *
	 * @param list<int> $ids
	 *
	 * @return int how many were retired
	 */
	public function unretire(array $ids): int {
		if ($ids === []) {
			return 0;
		}

		$ok = db_execute_prepared('DELETE FROM plugin_gpu_retired WHERE gpu_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids, true, $this->connection);

		return $ok ? max(0, (int) db_affected_rows($this->connection)) : 0;
	}

	/**
	 * @return list<GpuRecord>
	 * @param mixed $rows
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
