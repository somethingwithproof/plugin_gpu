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
 * Use checks and removal for the templates the plugin imported, for an
 * uninstall that was asked to remove them (REQ-LIFE-004).
 *
 * Cacti 1.2 has no library call that removes a template; its own pages
 * delete the rows. The removals here delete the same rows as the delete
 * action of core's graph_templates.php, data_templates.php,
 * data_queries.php, and host_templates.php at 1.2.31, for a template that
 * nothing uses, so core's "undo" updates for templated graphs and data
 * sources have nothing to change and are left out. Nothing here deletes a
 * device, data source, graph, or RRD file.
 *
 * Each in-use check counts what lies outside the plugin's own objects: the
 * plugin's device template links its data query and graph templates, and
 * its data query links its graph and data templates, so those links do not
 * keep a template.
 */
final class CactiTemplateRepository {
		/**
	 * Construct a CactiTemplateRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Null means the lookup failed; zero means the template is absent.
	 * @param 'snmp_query'|'host_template'|'data_template'|'graph_templates' $table
	 * @param string $hash
	 * @return ?int
	 */
	public function idByHash(string $table, string $hash): ?int {
		$row = db_fetch_row_prepared("SELECT id FROM `$table` WHERE hash = ?", [substr($hash, -32)], true, $this->connection);

		return is_array($row) ? Row::int($row, 'id') : null;
	}

		/**
	 * Graph template in use.
	 *
	 * @param int $graphTemplate
	 * @param int $dataQuery
	 * @param int $hostTemplate
	 * @return bool
	 */
	public function graphTemplateInUse(int $graphTemplate, int $dataQuery, int $hostTemplate): bool {
		return $this->any('SELECT COUNT(*) FROM graph_local WHERE graph_template_id = ?', [$graphTemplate])
			|| $this->any('SELECT COUNT(*) FROM host_template_graph WHERE graph_template_id = ? AND host_template_id != ?', [$graphTemplate, $hostTemplate])
			|| $this->any('SELECT COUNT(*) FROM snmp_query_graph WHERE graph_template_id = ? AND snmp_query_id != ?', [$graphTemplate, $dataQuery])
			|| ($this->table('aggregate_graph_templates') && $this->any('SELECT COUNT(*) FROM aggregate_graph_templates WHERE graph_template_id = ?', [$graphTemplate]));
	}

	/**
	 * @param list<int> $pluginGraphTemplates the plugin's graph template ids
	 * @param int $dataTemplate
	 * @param int $dataQuery
	 * @return bool
	 */
	public function dataTemplateInUse(int $dataTemplate, int $dataQuery, array $pluginGraphTemplates): bool {
		$others = $pluginGraphTemplates === [] ? '' : ' AND gti.graph_template_id NOT IN (' . implode(', ', array_fill(0, count($pluginGraphTemplates), '?')) . ')';

		return $this->any('SELECT COUNT(*) FROM data_local WHERE data_template_id = ?', [$dataTemplate])
			|| $this->any('SELECT COUNT(*) FROM snmp_query_graph_rrd AS r INNER JOIN snmp_query_graph AS g ON g.id = r.snmp_query_graph_id WHERE r.data_template_id = ? AND g.snmp_query_id != ?', [$dataTemplate, $dataQuery])
			|| $this->any(
				'SELECT COUNT(*) FROM graph_templates_item AS gti INNER JOIN data_template_rrd AS dtr ON dtr.id = gti.task_item_id
				WHERE dtr.data_template_id = ? AND dtr.local_data_id = 0 AND gti.local_graph_id = 0' . $others,
				array_merge([$dataTemplate], $pluginGraphTemplates),
			)
			// Thold, when installed, keeps threshold templates by data template.
			|| ($this->table('thold_template') && $this->any('SELECT COUNT(*) FROM thold_template WHERE data_template_id = ?', [$dataTemplate]));
	}

		/**
	 * Data query in use.
	 *
	 * @param int $dataQuery
	 * @param int $hostTemplate
	 * @return bool
	 */
	public function dataQueryInUse(int $dataQuery, int $hostTemplate): bool {
		return $this->any('SELECT COUNT(*) FROM host_snmp_query WHERE snmp_query_id = ?', [$dataQuery])
			|| $this->any('SELECT COUNT(*) FROM host_template_snmp_query WHERE snmp_query_id = ? AND host_template_id != ?', [$dataQuery, $hostTemplate])
			|| $this->any('SELECT COUNT(*) FROM data_local WHERE snmp_query_id = ?', [$dataQuery])
			|| $this->any('SELECT COUNT(*) FROM graph_local WHERE snmp_query_id = ?', [$dataQuery]);
	}

		/**
	 * Host template in use.
	 *
	 * @param int $hostTemplate
	 * @return bool
	 */
	public function hostTemplateInUse(int $hostTemplate): bool {
		return $this->any('SELECT COUNT(*) FROM host WHERE host_template_id = ?', [$hostTemplate]);
	}

		/**
	 * Remove host template.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function removeHostTemplate(int $id): bool {
		return $this->run('DELETE FROM host_template WHERE id = ?', [$id])
			&& $this->run('DELETE FROM host_template_snmp_query WHERE host_template_id = ?', [$id])
			&& $this->run('DELETE FROM host_template_graph WHERE host_template_id = ?', [$id]);
	}

		/**
	 * Remove data query.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function removeDataQuery(int $id): bool {
		return $this->run('DELETE FROM snmp_query_graph_rrd WHERE snmp_query_graph_id IN (SELECT id FROM snmp_query_graph WHERE snmp_query_id = ?)', [$id])
			&& $this->run('DELETE FROM snmp_query_graph_rrd_sv WHERE snmp_query_graph_id IN (SELECT id FROM snmp_query_graph WHERE snmp_query_id = ?)', [$id])
			&& $this->run('DELETE FROM snmp_query_graph_sv WHERE snmp_query_graph_id IN (SELECT id FROM snmp_query_graph WHERE snmp_query_id = ?)', [$id])
			&& $this->run('DELETE FROM snmp_query WHERE id = ?', [$id])
			&& $this->run('DELETE FROM snmp_query_graph WHERE snmp_query_id = ?', [$id])
			&& $this->run('DELETE FROM host_template_snmp_query WHERE snmp_query_id = ?', [$id])
			&& $this->run('DELETE FROM host_snmp_query WHERE snmp_query_id = ?', [$id])
			&& $this->run('DELETE FROM host_snmp_cache WHERE snmp_query_id = ?', [$id]);
	}

		/**
	 * Remove graph template.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function removeGraphTemplate(int $id): bool {
		return $this->run('DELETE FROM graph_templates WHERE id = ?', [$id])
			&& $this->run('DELETE FROM snmp_query_graph_rrd WHERE snmp_query_graph_id IN (SELECT id FROM snmp_query_graph WHERE graph_template_id = ?)', [$id])
			&& $this->run('DELETE FROM snmp_query_graph_rrd_sv WHERE snmp_query_graph_id IN (SELECT id FROM snmp_query_graph WHERE graph_template_id = ?)', [$id])
			&& $this->run('DELETE FROM snmp_query_graph_sv WHERE snmp_query_graph_id IN (SELECT id FROM snmp_query_graph WHERE graph_template_id = ?)', [$id])
			&& $this->run('DELETE FROM snmp_query_graph WHERE graph_template_id = ?', [$id])
			&& $this->run('DELETE FROM graph_template_input_defs WHERE graph_template_input_id IN (SELECT id FROM graph_template_input WHERE graph_template_id = ?)', [$id])
			&& $this->run('DELETE FROM graph_template_input WHERE graph_template_id = ?', [$id])
			&& $this->run('DELETE FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', [$id])
			&& $this->run('DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0', [$id])
			&& $this->run('DELETE FROM host_template_graph WHERE graph_template_id = ?', [$id]);
	}

		/**
	 * Remove data template.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function removeDataTemplate(int $id): bool {
		return $this->run('DELETE FROM data_input_data WHERE data_template_data_id IN (SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0)', [$id])
			&& $this->run('DELETE FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', [$id])
			&& $this->run('DELETE FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0', [$id])
			&& $this->run('DELETE FROM snmp_query_graph_rrd WHERE data_template_id = ?', [$id])
			&& $this->run('DELETE FROM snmp_query_graph_rrd_sv WHERE data_template_id = ?', [$id])
			&& $this->run('DELETE FROM data_template WHERE id = ?', [$id]);
	}

		/**
	 * Delete setting.
	 *
	 * @param string $name
	 * @return bool
	 */
	public function deleteSetting(string $name): bool {
		return $this->run('DELETE FROM settings WHERE name = ?', [$name]);
	}

	/**
	 * @param list<int|string> $params
	 * @param string $sql
	 * @return bool
	 */
	private function any(string $sql, array $params): bool {
		// A read that fails counts as in use, so a template is never removed
		// on a guess.
		$count = db_fetch_cell_prepared($sql, $params, '', true, $this->connection);

		return $count === false || (int) $count > 0;
	}

	/**
	 * @param list<int|string> $params
	 * @param string $sql
	 * @return bool
	 */
	private function run(string $sql, array $params): bool {
		return (bool) db_execute_prepared($sql, $params, true, $this->connection);
	}

		/**
	 * Table.
	 *
	 * @param string $name
	 * @return bool
	 */
	private function table(string $name): bool {
		$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name], '', true, $this->connection);

		return $count === false || (int) $count > 0;
	}
}
