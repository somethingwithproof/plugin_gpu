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
 * Decides how one node's snapshot changes the GPU records (PLAN, GPU
 * identity; REQ-ID-001 to REQ-ID-005). It only decides; it reads and writes
 * nothing, so every identity rule is tested without a database.
 *
 * The rules, in the order they apply:
 *
 * - A disabled node, or a snapshot with no GPUs, changes nothing. A tool that
 *   suddenly reports nothing is far likelier broken than every card gone.
 * - A reported UUID with a record at this node updates that record in place.
 * - A reported UUID with a record at another node leaves the record where it
 *   is and marks the move pending until an operator confirms it.
 * - A reported UUID with no record creates one. If an earlier record at this
 *   node sat on the same PCI bus ID and was not reported now, the new record
 *   is flagged as its possible replacement and inherits nothing. The rule
 *   matches on the bus ID alone, not also the index, because the index can
 *   change at every boot and a replacement must not escape the flag that way.
 * - A record at this node that was not reported counts a miss, and is marked
 *   absent at the configured count. It is never deleted.
 */
final class IdentityReconciler {
	/**
	 * @param list<GpuRecord> $known every record at this node, plus any record
	 *                               elsewhere whose UUID the snapshot reports
	 */
	public function reconcile(Node $node, array $known, GpuSnapshot $snapshot, int $absentAfter, int $now): Reconciliation {
		if (!$node->enabled() || $snapshot->reports === []) {
			return new Reconciliation();
		}

		$byKey = [];

		foreach ($known as $record) {
			$byKey[$record->key()] = $record;
		}

		$created = [];
		$updated = [];

		foreach ($snapshot->reports as $report) {
			$record = $byKey[$report->key()] ?? null;

			if ($record === null) {
				$created[] = GpuRecord::discovered($report, $snapshot, $node->id, $this->replaced($node, $known, $snapshot, $report), $now);
			} elseif ($record->nodeId === $node->id) {
				$updated[] = $record->seen($report, $snapshot, $now);
			} else {
				$moved = $record->seenElsewhere($node->id, $now);

				if ($moved != $record) {
					$updated[] = $moved;
				}
			}
		}

		foreach ($known as $record) {
			if ($record->nodeId === $node->id && !$snapshot->has($record->key()) && $record->presence !== Presence::Absent) {
				$updated[] = $record->missed($absentAfter);
			}
		}

		return new Reconciliation($created, $updated);
	}

	/**
	 * The id of the record a new GPU may have replaced, or 0. When several
	 * earlier cards sat on that bus ID, the one seen last is named.
	 *
	 * @param list<GpuRecord> $known
	 */
	private function replaced(Node $node, array $known, GpuSnapshot $snapshot, GpuReport $report): int {
		$candidate = null;

		foreach ($known as $record) {
			if ($record->nodeId !== $node->id || $record->vendor !== $report->vendor || $record->pciBusId !== $report->pciBusId || $snapshot->has($record->key())) {
				continue;
			}

			if ($candidate === null || [$record->lastSeen, $record->id] > [$candidate->lastSeen, $candidate->id]) {
				$candidate = $record;
			}
		}

		return $candidate->id ?? 0;
	}
}
