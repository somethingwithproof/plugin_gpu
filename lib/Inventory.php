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
 * Records one node's snapshot: reads the records the snapshot touches,
 * reconciles, and writes the result. The collector (milestone 3) calls this
 * once per successful collection and never for a failed one.
 */
final class Inventory {
		/**
	 * Construct a Inventory instance.
	 *
	 * @param GpuRepository $gpus
	 * @param IdentityReconciler $reconciler
	 * @param Clock $clock
	 */
	public function __construct(
		private readonly GpuRepository $gpus,
		private readonly IdentityReconciler $reconciler,
		private readonly Clock $clock,
	) {
	}

		/**
	 * Record.
	 *
	 * @param Node $node
	 * @param GpuSnapshot $snapshot
	 * @param int $absentAfter
	 * @return Reconciliation
	 */
	public function record(Node $node, GpuSnapshot $snapshot, int $absentAfter): Reconciliation {
		$known = $this->gpus->forReconcile($node, $snapshot);

		if ($known === null) {
			Log::warning(sprintf('The GPU records for node %d could not be read, so this collection was not recorded', $node->id));

			return new Reconciliation();
		}

		$changes = $this->reconciler->reconcile($node, $known, $snapshot, $absentAfter, $this->clock->now());

		if ($changes->isEmpty()) {
			return $changes;
		}

		if (!$this->gpus->apply($changes)) {
			Log::warning(sprintf('Not every GPU record change for node %d was stored; the next collection retries', $node->id));
		}

		$summary = sprintf('Node %d: %d new GPU records, %d updated', $node->id, count($changes->created), count($changes->updated));

		// A new identity is worth a note; routine updates are debug detail.
		if ($changes->created === []) {
			Log::debug($summary);
		} else {
			Log::info($summary);
		}

		return $changes;
	}
}
