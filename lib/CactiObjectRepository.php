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
 * Reads of Cacti's own objects that the plugin shipped or attaches to. The
 * plugin never writes these tables: objects change only through Cacti's
 * APIs (REQ-DEV-004).
 */
final class CactiObjectRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * The site's default data source profile, as core's template import
	 * picks it.
	 */
	public function defaultProfileId(): int {
		return (int) db_fetch_cell_prepared('SELECT id FROM data_source_profiles ORDER BY `default` DESC, id LIMIT 1', [], '', true, $this->connection);
	}

	/**
	 * The id of an object by its export hash (the 32 hex characters after
	 * the type and version codes), or 0.
	 *
	 * @param 'snmp_query'|'host_template'|'data_template'|'graph_templates' $table
	 */
	public function idByHash(string $table, string $hash): int {
		return (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ?", [substr($hash, -32)], '', true, $this->connection);
	}

	/**
	 * The device's reindex method for a data query, or null when the data
	 * query is not attached to the device.
	 */
	public function reindexMethod(int $hostId, int $dataQueryId): ?int {
		$row = db_fetch_row_prepared('SELECT reindex_method FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $dataQueryId], true, $this->connection);

		return is_array($row) && $row !== [] ? Row::int($row, 'reindex_method') : null;
	}

	/**
	 * The indexes Cacti has cached for the device's data query, which is
	 * what its last reindex found.
	 *
	 * @return list<string>|null null when the read failed
	 */
	public function cachedIndexes(int $hostId, int $dataQueryId): ?array {
		$rows = db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ? ORDER BY snmp_index', [$hostId, $dataQueryId], true, $this->connection);

		return is_array($rows) ? array_map(static fn (array $row): string => Row::string($row, 'snmp_index'), Row::rows($rows)) : null;
	}

	/**
	 * The values Cacti has cached for the given fields of the device's data
	 * query: index => field name => value, each index's fields in the order
	 * given.
	 *
	 * @param list<string> $fields
	 *
	 * @return array<string, array<string, string>>|null null when the read failed
	 */
	public function cachedFields(int $hostId, int $dataQueryId, array $fields): ?array {
		$rows = db_fetch_assoc_prepared('SELECT snmp_index, field_name, field_value FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ? AND field_name IN (' . implode(', ', array_fill(0, count($fields), '?')) . ')', array_merge([$hostId, $dataQueryId], $fields), true, $this->connection);

		if (!is_array($rows)) {
			return null;
		}

		$values = [];

		foreach (Row::rows($rows) as $row) {
			$values[Row::string($row, 'snmp_index')][Row::string($row, 'field_name')] = Row::untrimmed($row, 'field_value');
		}

		$out = [];

		foreach ($values as $index => $byField) {
			foreach ($fields as $field) {
				if (isset($byField[$field])) {
					$out[(string) $index][$field] = $byField[$field];
				}
			}
		}

		return $out;
	}

	/**
	 * Whether Cacti would skip a reindex of the device now: it is down or
	 * disabled. Null when the device row could not be read.
	 */
	public function reindexBlocked(int $hostId): ?bool {
		$row = db_fetch_row_prepared('SELECT status, disabled FROM host WHERE id = ?', [$hostId], true, $this->connection);

		if (!is_array($row) || $row === []) {
			return null;
		}

		return NodeDevice::fromRow($row + ['id' => $hostId])->reindexBlocked();
	}

	/**
	 * Remove every cached index of the device's data query and mark its
	 * data sources orphaned: what run_data_query() does for removed
	 * indexes, for the case it does not handle, a listing with none.
	 */
	public function clearIndexes(int $hostId, int $dataQueryId): bool {
		$cleared = db_execute_prepared('DELETE FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $dataQueryId], true, $this->connection);

		return db_execute_prepared('UPDATE data_local SET orphan = 1 WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $dataQueryId], true, $this->connection) && $cleared;
	}

	/**
	 * The data query's graph types: snmp_query_graph id => graph template id.
	 *
	 * @return array<int, int>
	 */
	public function queryGraphs(int $dataQueryId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT id, graph_template_id FROM snmp_query_graph WHERE snmp_query_id = ? ORDER BY id', [$dataQueryId], true, $this->connection)) as $row) {
			$out[Row::int($row, 'id')] = Row::int($row, 'graph_template_id');
		}

		return $out;
	}

	/**
	 * The ids of the data sources the device already has for the data query.
	 *
	 * @return list<int>|null
	 */
	public function dataSources(int $hostId, int $dataQueryId): ?array {
		$rows = db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $dataQueryId], true, $this->connection);

		return is_array($rows) ? array_map(static fn (array $row): int => Row::int($row, 'id'), Row::rows($rows)) : null;
	}

	/**
	 * The graphs the device already has for the data query, as
	 * "graph type id:index" keys.
	 *
	 * @return array<string, true>|null
	 */
	public function graphed(int $hostId, int $dataQueryId): ?array {
		$rows = db_fetch_assoc_prepared('SELECT snmp_query_graph_id, snmp_index FROM graph_local WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $dataQueryId], true, $this->connection);

		if (!is_array($rows)) {
			return null;
		}

		$out = [];

		foreach (Row::rows($rows) as $row) {
			$out[Row::int($row, 'snmp_query_graph_id') . ':' . Row::string($row, 'snmp_index')] = true;
		}

		return $out;
	}

	public function setting(string $name): string {
		return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [$name], '', true, $this->connection);
	}

	public function saveSetting(string $name, string $value): bool {
		return (bool) db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value], true, $this->connection);
	}

	/** @return list<int>|null */
	public function inheritedRrds(int $hostId, int $query): ?array {
		$rows = db_fetch_assoc_prepared("SELECT dl.id FROM data_local AS dl INNER JOIN settings AS s ON s.name = CONCAT('gpu_inherited_rrd_', dl.id) AND s.value = CAST(dl.host_id AS CHAR) WHERE dl.host_id = ? AND dl.snmp_query_id = ?", [$hostId, $query], true, $this->connection);

		return is_array($rows) ? array_values(array_map(static fn (array $row): int => Row::int($row, 'id'), $rows)) : null;
	}

	public function forgetInheritedRrd(int $id): bool {
		return db_execute_prepared('DELETE FROM settings WHERE name = ?', ['gpu_inherited_rrd_' . $id], true, $this->connection);
	}

	public function deviceRemoved(int $hostId): bool {
		return db_fetch_row_prepared('SELECT id FROM host WHERE id = ?', [$hostId], true, $this->connection) === [];
	}
}
