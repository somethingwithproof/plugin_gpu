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
 * One collector run, started from Cacti's poller_bottom hook by
 * poller_gpu.php. Nodes are collected one at a time, least recently
 * finished first, so a run that hits its time budget leaves the nodes it
 * did not reach first in line for the next one.
 *
 * Only nodes whose device the main poller polls are collected. Remote data
 * collectors are phase 3; their nodes are counted and left alone.
 */
final class Poller {
	public const MAIN_POLLER = 1;

	public function __construct(
		private readonly NodeRepository $nodes,
		private readonly CollectionRepository $collections,
		private readonly Collector $collector,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{nodes: int, ok: int, failed: int, skipped: int, remote: int, stopped_early: bool}
	 */
	public function run(Settings $settings, int $budget): array {
		$stats    = ['nodes' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'remote' => 0, 'stopped_early' => false];
		$started  = $this->clock->now();
		$statuses = $this->collections->all();
		$nodes    = array_values(array_filter($this->nodes->all(), static fn (Node $node): bool => $node->enabled() && $node->deviceExists));

		$last = static fn (Node $node): int => isset($statuses[$node->id]) ? $statuses[$node->id]->finishedAt : 0;

		usort($nodes, static fn (Node $a, Node $b): int => [$last($a), $a->id] <=> [$last($b), $b->id]);

		foreach ($nodes as $node) {
			if ($this->clock->now() - $started >= $budget) {
				$stats['stopped_early'] = true;

				break;
			}

			$device = $this->nodes->device($node);

			if ($device === null || $device->disabled) {
				$stats['skipped']++;

				continue;
			}

			if ($device->pollerId !== self::MAIN_POLLER) {
				$stats['remote']++;

				continue;
			}

			$stats['nodes']++;

			$state = $this->collector->collect($node, $device, $settings);

			if ($state === ExecutionState::Skipped) {
				$stats['skipped']++;
			} elseif ($state->succeeded()) {
				$stats['ok']++;
			} else {
				$stats['failed']++;
			}
		}

		return $stats;
	}
}
