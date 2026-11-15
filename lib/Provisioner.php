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
 * Keeps each node's Cacti device in step with its GPUs (ADR 0004, ADR 0010):
 * the GPU data query attached, its indexes current, and a graph of each
 * type for every present GPU. Every change goes through Cacti's own API
 * (REQ-DEV-004).
 *
 * The data query is attached with reindex method None, because the plugin
 * reindexes after each inventory change itself (REQ-INDEX-004) and must not
 * depend on Cacti's scheduled reindex. Cacti's run_data_query() returns true
 * without reindexing a device that is down or disabled, so a reindex counts
 * as done only once Cacti's cache lists exactly the GPUs the plugin lists;
 * until then the request stays and is retried every cycle.
 */
final class Provisioner {
	// Cacti's DATA_QUERY_AUTOINDEX_NONE.
	public const REINDEX_NONE = 0;

	public function __construct(
		private readonly CactiObjectRepository $cacti,
		private readonly CacheRepository $cache,
		private readonly ReindexRepository $reindex,
		private readonly Clock $clock,
	) {
	}

	public function dataQueryId(): int {
		return $this->cacti->idByHash('snmp_query', TemplateXml::hash('04', TemplateXml::DATA_QUERY));
	}

	/**
	 * Attach the GPU data query to the node's device, or reset its reindex
	 * method to None after a device template change set another. A new
	 * attachment asks for a reindex, which the next poller cycle verifies.
	 */
	public function attach(Node $node): bool {
		$query = $this->dataQueryId();

		if ($query === 0 || !$node->deviceExists) {
			return false;
		}

		$method = $this->cacti->reindexMethod($node->hostId, $query);

		if ($method === self::REINDEX_NONE) {
			return true;
		}

		api_device_dq_add($node->hostId, $query, self::REINDEX_NONE);

		if ($method === null) {
			$this->reindex->request($node->id, 'data query attached', $this->clock->now());
		}

		return $this->cacti->reindexMethod($node->hostId, $query) === self::REINDEX_NONE;
	}

	/**
	 * One poller cycle's work for a node: attach, run a requested reindex,
	 * and create missing graphs.
	 *
	 * @param array{requested_at: int, reason: string, attempts: int, detail: string, generation: int}|null $request
	 *
	 * @return array{reindexed: bool, deferred: bool, graphs: int}
	 */
	public function provision(Node $node, NodeDevice $device, ?array $request): array {
		$result = ['reindexed' => false, 'deferred' => false, 'graphs' => 0];
		$query  = $this->dataQueryId();

		if ($query === 0 || !$this->attach($node)) {
			return $result;
		}

		if ($request !== null) {
			if ($this->reindexNode($node, $device, $query, $request['generation'])) {
				$result['reindexed'] = true;
			} else {
				$result['deferred'] = true;
			}
		}

		if (!$device->disabled) {
			$result['graphs'] = $this->createGraphs($node, $query);
		}

		return $result;
	}

	private function reindexNode(Node $node, NodeDevice $device, int $query, int $generation): bool {
		$now = $this->clock->now();

		if ($device->reindexBlocked()) {
			$this->reindex->deferred($node->id, 'Cacti does not reindex a device that is down or disabled; retried once it is up', $now);

			return false;
		}

		// Forced, because Cacti refreshes titles only for indexes that
		// changed, and a GPU's index (its identity key) never does: a new
		// GPU index, name, or bus ID would otherwise stay out of the titles.
		run_data_query($node->hostId, $query, false, true);

		$expected = array_map(static fn (array $row): string => Row::string($row, 'vendor') . '/' . Row::string($row, 'uuid'), $this->cache->listed($node->hostId) ?? []);
		$cached   = $this->cacti->cachedIndexes($node->hostId, $query);

		sort($expected);

		if ($cached !== null) {
			sort($cached);
		}

		if ($cached === null || $cached !== $expected) {
			$this->reindex->deferred($node->id, sprintf('The reindex listed %d of %d GPUs; retried next cycle', count(array_intersect($expected, $cached ?? [])), count($expected)), $now);

			return false;
		}

		$this->reindex->done($node->id, $generation);

		Log::info(sprintf('Node %d: GPU data query reindexed (%d GPUs)', $node->id, count($expected)));

		return true;
	}

	/**
	 * A graph of each type for every present GPU that Cacti has cached and
	 * that has none yet. A GPU's graphs share one data source, which
	 * create_complete_graph_from_template() reuses once it exists. Absent
	 * GPUs get no new graphs; the graphs they have stay.
	 */
	private function createGraphs(Node $node, int $query): int {
		$graphed = $this->cacti->graphed($node->hostId, $query);
		$cached  = $this->cacti->cachedIndexes($node->hostId, $query);
		$listed  = $this->cache->listed($node->hostId);

		if ($graphed === null || $cached === null || $listed === null) {
			return 0;
		}

		$cachedSet = array_flip($cached);
		$created   = 0;

		foreach ($listed as $row) {
			$index = Row::string($row, 'vendor') . '/' . Row::string($row, 'uuid');

			if (Row::string($row, 'presence') !== Presence::Present->value || !isset($cachedSet[$index])) {
				continue;
			}

			foreach ($this->cacti->queryGraphs($query) as $queryGraph => $template) {
				if (isset($graphed[$queryGraph . ':' . $index])) {
					continue;
				}

				$suggested = [];
				$graph     = create_complete_graph_from_template($template, $node->hostId, [
					'snmp_query_id'       => $query,
					// Always the identity key, never Cacti's best guess.
					'snmp_index_on'       => TemplateXml::SORT_FIELD,
					'snmp_query_graph_id' => $queryGraph,
					'snmp_index'          => $index,
				], $suggested);

				if (!is_array($graph) || !isset($graph['local_graph_id'])) {
					Log::warning(sprintf('Node %d: a GPU graph could not be created', $node->id));

					continue;
				}

				foreach ((array) ($graph['local_data_id'] ?? []) as $dataSource) {
					if (is_numeric($dataSource)) {
						push_out_host($node->hostId, (int) $dataSource);
					}
				}

				$graphed[$queryGraph . ':' . $index] = true;
				$created++;
			}
		}

		if ($created > 0) {
			Log::info(sprintf('Node %d: %d GPU graphs created', $node->id, $created));
		}

		return $created;
	}
}
