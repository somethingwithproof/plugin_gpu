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

	public function setting(string $name): string {
		return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [$name], '', true, $this->connection);
	}

	public function saveSetting(string $name, string $value): bool {
		return (bool) db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value], true, $this->connection);
	}
}
