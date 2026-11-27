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
	 * Compute identity record inserts and updates for one node snapshot in O(R + K) time.
	 *
	 * @param Node            $node        target GPU node
	 * @param list<GpuRecord> $known       every record at this node, plus any record
	 *                                     elsewhere whose UUID the snapshot reports
	 * @param GpuSnapshot     $snapshot    validated GPU snapshot from the latest collection
	 * @param int             $absentAfter consecutive misses before marking a GPU absent
	 * @param int             $now         current Unix timestamp
	 *
	 * @return Reconciliation
	 */
	public function reconcile(Node $node, array $known, GpuSnapshot $snapshot, int $absentAfter, int $now): Reconciliation {
		if (!$node->enabled() || $snapshot->reports === []) {
			return new Reconciliation();
		}

		$byKey        = [];
		$read         = [];
		$unreported   = [];
		/** @var array<string, GpuRecord> $replacedBySlot */
		$replacedBySlot = [];

		foreach ($known as $record) {
			$key               = $record->key();
			$byKey[$key]       = $record;
			$read[$record->id] = $record;

			if ($record->nodeId === $node->id && !$snapshot->has($key)) {
				if ($record->presence !== Presence::Absent) {
					$unreported[] = $record;
				}

				// Track the most recently seen absent/unreported card per (vendor, PCI slot)
				// so new UUIDs resolve possible replacements in O(1) instead of scanning $known.
				$slot      = $record->vendor->value . '/' . $record->pciBusId;
				$candidate = $replacedBySlot[$slot] ?? null;

				if ($candidate === null || [$record->lastSeen, $record->id] > [$candidate->lastSeen, $candidate->id]) {
					$replacedBySlot[$slot] = $record;
				}
			}
		}

		$created = [];
		$updated = [];

		foreach ($snapshot->reports as $report) {
			$record = $byKey[$report->key()] ?? null;

			if ($record === null) {
				$slot       = $report->vendor->value . '/' . $report->pciBusId;
				$replacesId = isset($replacedBySlot[$slot]) ? $replacedBySlot[$slot]->id : 0;
				$created[]  = GpuRecord::discovered($report, $snapshot, $node->id, $replacesId, $now);
			} elseif ($record->nodeId === $node->id) {
				$updated[] = $record->seen($report, $snapshot, $now);
			} else {
				$moved = $record->seenElsewhere($node->id, $now);

				if ($moved != $record) {
					$updated[] = $moved;
				}
			}
		}

		foreach ($unreported as $record) {
			$updated[] = $record->missed($absentAfter);
		}

		return new Reconciliation($created, $updated, $read);
	}
}
