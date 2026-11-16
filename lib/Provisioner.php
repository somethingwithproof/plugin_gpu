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
 * as done only once Cacti's cache lists exactly the GPUs the plugin lists
 * and the device was not down when it ran; until then the request stays and
 * is retried every cycle. A cycle without a request still compares the two
 * listings and asks for a reindex when they differ, so a request that was
 * lost (a failed write, an interrupted collection) is made again.
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
		} elseif (!$device->reindexBlocked()) {
			$expected = $this->expected($node);
			$cached   = $this->cached($node, $query);

			if ($expected !== null && $cached !== null && $expected !== $cached) {
				$this->reindex->request($node->id, 'listing differs from the cache', $this->clock->now());
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
		$expected = $this->expected($node);

		if ($expected === null) {
			$this->reindex->deferred($node->id, 'The GPU records could not be read; retried next cycle', $now);

			return false;
		}

		if ($expected === []) {
			// Cacti keeps its cache when a script data query lists no index,
			// so the last GPU removed from the listing would stay cached.
			// Drop the device's cache for the query and mark its data
			// sources orphaned, as Cacti does for each removed index. An
			// empty cache would match the empty listing below even if the
			// data sources were not marked, so a failure keeps the request.
			if (!$this->cacti->clearIndexes($node->hostId, $query)) {
				$this->reindex->deferred($node->id, 'The GPU data query cache could not be cleared; retried next cycle', $now);

				return false;
			}
		} else {
			run_data_query($node->hostId, $query, false, true);
		}

		$cached = $this->cached($node, $query);

		// Cacti reads the device's status when it starts, and skips the
		// reindex (still returning true) if the device went down after this
		// cycle read it. The comparison below also catches a skipped
		// reindex the status no longer shows, through the fields.
		if ($this->cacti->reindexBlocked($node->hostId) !== false) {
			$this->reindex->deferred($node->id, 'The device went down or was disabled during the reindex; retried once it is up', $now);

			return false;
		}

		if ($cached === null || array_keys($cached) !== array_keys($expected)) {
			$this->reindex->deferred($node->id, sprintf('The reindex listed %d of %d GPUs; retried next cycle', count(array_intersect_key($expected, $cached ?? [])), count($expected)), $now);

			return false;
		}

		if ($cached !== $expected) {
			$this->reindex->deferred($node->id, 'Cacti did not cache every GPU\'s current index, name, and bus ID; retried next cycle', $now);

			return false;
		}

		$this->reindex->done($node->id, $generation);

		Log::info(sprintf('Node %d: GPU data query reindexed (%d GPUs)', $node->id, count($expected)));

		return true;
	}

	/**
	 * What the plugin lists for the node, as Cacti caches it: identity key
	 * => input field values, sorted by key; null when the records could not
	 * be read. The fields are compared too, so a changed GPU index, name,
	 * or bus ID that Cacti has not cached is found without a request.
	 *
	 * @return array<string, array<string, string>>|null
	 */
	private function expected(Node $node): ?array {
		$listed = $this->cache->listed($node->hostId);

		if ($listed === null) {
			return null;
		}

		$out = [];

		foreach ($listed as $row) {
			$out[ScriptServer::key($row)] = ScriptServer::inputs($row);
		}

		ksort($out, SORT_STRING);

		return $out;
	}

	/**
	 * @return array<string, array<string, string>>|null what Cacti has cached, in the same form
	 */
	private function cached(Node $node, int $query): ?array {
		$cached = $this->cacti->cachedFields($node->hostId, $query, array_keys(TemplateXml::INPUT_FIELDS));

		if ($cached !== null) {
			ksort($cached, SORT_STRING);
		}

		return $cached;
	}

	/**
	 * A graph of each type for every present GPU that Cacti has cached and
	 * that has none yet. A GPU's graphs share one data source, which
	 * create_complete_graph_from_template() reuses once it exists. Absent
	 * GPUs get no new graphs; the graphs they have stay.
	 */
	private function createGraphs(Node $node, int $query): int {
		$pending = $this->cacti->inheritedRrds($node->hostId, $query);

		if ($pending === null) {
			return 0;
		}

		$blocked = [];
		foreach ($pending as $id => $renamed) {
			if (!($renamed ? $this->enableRecovered($node, $id) : $this->setAsideInherited($node, $id, true))) {
				$blocked[$id] = true;
			}
		}

		$graphed = $this->cacti->graphed($node->hostId, $query);
		$cached  = $this->cacti->cachedIndexes($node->hostId, $query);
		$listed  = $this->cache->listed($node->hostId);
		$sources = $this->cacti->dataSources($node->hostId, $query);

		if ($graphed === null || $cached === null || $listed === null || $sources === null) {
			return 0;
		}

		$sources = array_flip($sources);

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
					if (!is_numeric($dataSource)) {
						continue;
					}

					if (!isset($sources[(int) $dataSource])) {
						if (!$this->setAsideInherited($node, (int) $dataSource)) {
							$blocked[(int) $dataSource] = true;
						}
						$sources[(int) $dataSource] = true;
					}

					if (!isset($blocked[(int) $dataSource])) {
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

	/**
	 * A data source this run created has no history, so an RRD file already
	 * at its path belongs to something else: typically a GPU whose records
	 * were lost when the database was restored from a backup older than the
	 * RRD directory, after which Cacti hands out the same data source ids,
	 * and so the same file names, again. Cacti would write the new GPU's
	 * values into that file. The file is renamed instead, kept for the
	 * operator, and a warning names the data source but not the path,
	 * which contains the device description (REQ-BACKUP-003).
	 */
	private function setAsideInherited(Node $node, int $dataSource, bool $pending = false): bool {
		$path = (string) get_data_source_path($dataSource, true);

		if (!$pending && ($path === '' || !is_file($path))) {
			return true;
		}

		// A failed rename must survive this process and graph creation.
		// Keep the source disabled until the old file is safely set aside.
		api_data_source_disable($dataSource);
		if (!$this->cacti->saveSetting('gpu_inherited_rrd_' . $dataSource, (string) $node->hostId)) {
			Log::error(sprintf('Node %d: data source %d is disabled; its inherited RRD retry could not be recorded', $node->id, $dataSource));
			return false;
		}

		$suffix = '.gpu-orphan-' . $this->clock->now();

		if ($path === '' || (is_file($path) && (file_exists($path . $suffix) || !@rename($path, $path . $suffix)))) {
			Log::warning(sprintf('Node %d: data source %d remains disabled because its inherited RRD could not be renamed; retried next cycle', $node->id, $dataSource));
			return false;
		}

		if (is_file($path . $suffix)) {
			Log::warning(sprintf('Node %d: new data source %d found an RRD file already at its path; the file was renamed with the suffix %s so its history attaches to no other GPU', $node->id, $dataSource, $suffix));
		}

		// Persist the phase before enabling: retries must never rename a new
		// file written by the poller after a successful but interrupted enable.
		if (!$this->cacti->saveSetting('gpu_inherited_rrd_' . $dataSource, $node->hostId . ':ready')) {
			return false;
		}

		return $this->enableRecovered($node, $dataSource);
	}

	private function enableRecovered(Node $node, int $dataSource): bool {
		api_data_source_enable($dataSource);
		if (!$this->cacti->dataSourceReady($dataSource)) {
			return false;
		}
		push_out_host($node->hostId, $dataSource);

		return $this->cacti->forgetInheritedRrd($dataSource);
	}
}
