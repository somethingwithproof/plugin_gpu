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
 * Collects one node: reads both extend entries, parses them, records the
 * inventory, and stores the values, all from the same output (REQ-INV-001).
 *
 * A failed collection stores no value and changes no GPU record, so it can
 * never mark a GPU absent (REQ-INV-003); its state is recorded instead. A
 * collection that changes what the data query lists asks for a reindex
 * (REQ-INDEX-004).
 */
final class Collector {
	public function __construct(
		private readonly ExtendReader $reader,
		private readonly NvidiaSmiParser $parser,
		private readonly Inventory $inventory,
		private readonly GpuRepository $gpus,
		private readonly ObservationRepository $observations,
		private readonly CollectionRepository $collections,
		private readonly ReindexRepository $reindex,
		private readonly LockRepository $locks,
		private readonly Clock $clock,
	) {
	}

	public function collect(Node $node, NodeDevice $device, Settings $settings): ExecutionState {
		// REQ-INV-005: nothing is recorded for a node that is not enabled.
		if (!$node->enabled() || $device->id !== $node->hostId) {
			return ExecutionState::Skipped;
		}

		$lock = 'node_' . $node->id;

		if (!$this->locks->acquire($lock)) {
			$this->collections->skipped($node->id);

			Log::info(sprintf('Node %d: collection skipped because another collection of it is still running', $node->id));

			return ExecutionState::Skipped;
		}

		try {
			$previous = $this->collections->find($node->id);

			$this->collections->start($node->id, $this->clock->now());

			[$state, $detail] = $this->run($node, $device, $settings);
		} finally {
			$this->locks->release($lock);
		}

		$this->logChange($node, $previous, $state, $detail);

		return $state;
	}

	/**
	 * @return array{ExecutionState, string} the state and its detail
	 */
	private function run(Node $node, NodeDevice $device, Settings $settings): array {
		try {
			$collection = $this->parser->parse(
				$this->reader->read($device, SnmpdConfig::METRICS),
				$this->reader->read($device, SnmpdConfig::NAMES),
			);
		} catch (CollectionError $e) {
			$this->collections->finish($node->id, $e->state, $e->getMessage(), $this->clock->now(), rejectedLines: $e->rejectedLines);

			return [$e->state, $e->getMessage()];
		}

		// A sample stamped ahead of Cacti's clock is refused before anything
		// is recorded: stored samples only move forward, so keeping it would
		// hold back every real sample until Cacti's clock caught up.
		$ahead = $collection->newestSample() - $this->clock->now();

		if ($ahead > Freshness::SKEW_ALLOWANCE) {
			$detail = sprintf('The sample time is %d seconds ahead of the Cacti server\'s clock', $ahead);

			$this->collections->finish($node->id, ExecutionState::InvalidOutput, $detail, $this->clock->now());

			return [ExecutionState::InvalidOutput, $detail];
		}

		$changes = $this->inventory->record($node, $collection->snapshot, $settings->absentAfter);
		$records = $this->gpus->forReconcile($node, $collection->snapshot);

		if ($records === null) {
			$detail = 'The GPU records could not be read after the inventory was recorded';

			$this->collections->finish($node->id, ExecutionState::NotStored, $detail, $this->clock->now());

			return [ExecutionState::NotStored, $detail];
		}

		$byKey = [];

		foreach ($records as $record) {
			$byKey[$record->key()] = $record;
		}

		$now      = $this->clock->now();
		$stored   = 0;
		$reported = [];
		$missing  = 0;

		// Ask for the reindex as soon as the records changed, so a failure
		// to store the samples below does not lose it.
		$reason = self::reindexReason($changes);

		if ($reason !== '') {
			$this->reindex->request($node->id, $reason, $now);
		}

		foreach ($collection->readings as $reading) {
			$record = $byKey[$reading->report->key()] ?? null;

			// A GPU whose record belongs to another node is waiting for an
			// operator to confirm the move (REQ-ID-004). Its values are not
			// stored until then, so they never appear under the old node's
			// graphs.
			if ($record === null || $record->id === 0) {
				// The record for a reported GPU should exist once the
				// inventory was recorded; its insert failed.
				$missing++;

				continue;
			}

			if ($record->nodeId !== $node->id) {
				continue;
			}

			$reported[] = $record->id;

			if ($this->observations->store($record->id, $node->id, $reading, $collection->backend, $collection->snapshot->toolVersion, $now)) {
				$stored++;
			}
		}

		$state = $collection->state();
		$total = count($collection->readings);

		if ($stored < count($reported) || $missing > 0) {
			$detail = sprintf('%d of %d GPU samples could not be stored', count($reported) - $stored + $missing, count($reported) + $missing);

			$this->collections->finish($node->id, ExecutionState::NotStored, $detail, $now, $collection->newestSample(), $total, count($changes->created));

			return [ExecutionState::NotStored, $detail];
		}

		$returned = $this->gpus->unretire($reported);

		if ($reason === '' && $returned > 0) {
			$this->reindex->request($node->id, 'retired GPU returned', $now);
		}

		if (!$this->collections->finish($node->id, $state, '', $now, $collection->newestSample(), $total, count($changes->created))) {
			$detail = 'The collection status could not be stored';

			Log::warning(sprintf('Node %d: %s', $node->id, $detail));

			return [ExecutionState::NotStored, $detail];
		}

		return [$state, ''];
	}

	/**
	 * Why the node's data query needs a reindex after this collection, or ''
	 * when its listing is unchanged. The listing holds each GPU at the node
	 * with its index, name, and bus ID, so a change to any of those, a new
	 * GPU, a GPU moving in or out, or a retired GPU returning changes it.
	 */
	public static function reindexReason(Reconciliation $changes, int $returned = 0): string {
		if ($changes->created !== []) {
			return 'new GPU';
		}

		if ($returned > 0) {
			return 'retired GPU returned';
		}

		foreach ($changes->updated as $record) {
			$read = $changes->read[$record->id] ?? null;

			if ($read === null || $read->nodeId !== $record->nodeId || $read->index !== $record->index || $read->name !== $record->name || $read->pciBusId !== $record->pciBusId) {
				return 'GPU changed';
			}
		}

		return '';
	}

	/**
	 * Log a node's collection state when it changes, not every cycle, so a
	 * node that stays down does not fill the log.
	 */
	private function logChange(Node $node, ?CollectionStatus $previous, ExecutionState $state, string $detail): void {
		$was = $previous?->state;

		if ($was === $state || ($state->succeeded() && ($was === null || $was->succeeded()))) {
			return;
		}

		if ($state->succeeded()) {
			Log::info(sprintf('Node %d: collection recovered (%s)', $node->id, $state->value));
		} else {
			Log::warning(sprintf('Node %d: collection failed (%s): %s', $node->id, $state->value, $detail));
		}
	}
}
