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
 * Plugin tables, their migrations, and the plugin's registration records.
 *
 * Tables are created through api_plugin_db_table_create() so Cacti tracks them
 * in plugin_db_changes and drops them on uninstall. The schema version is kept
 * in its own setting because api_plugin_install() writes plugin_config.version
 * before the plugin's install function runs, so that column cannot tell us
 * whether the tables exist.
 *
 * Migrations run once each, in order, and the stored schema version moves
 * past a migration only after it succeeds (REQ-LIFE-002). Every migration is
 * written to be safe to repeat, so one interrupted part way is completed by
 * the next run. No migration touches a Cacti device, data source, graph, or
 * RRD file, or contacts a GPU node (REQ-LIFE-003, REQ-LIFE-005).
 */
final class Schema {
	public const VERSION = 1;

	public const HOOKS = [
		'config_arrays'        => 'gpu_config_arrays',
		'config_settings'      => 'gpu_config_settings',
		'draw_navigation_text' => 'gpu_draw_navigation_text',
	];

	private const PLUGIN  = 'gpu';
	private const SETTING = 'gpu_schema_version';

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [self::SETTING], '', true, $this->connection);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration, or stop at the first that fails.
	 *
	 * @param int $through the last migration to run; tests pass a lower
	 *                     number to leave an upgrade interrupted
	 */
	public function migrate(int $through = self::VERSION): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than this code (%d); refusing to downgrade', $from, self::VERSION));

			return false;
		}

		foreach ($this->migrations() as $version => $migration) {
			if ($version <= $from || $version > $through) {
				continue;
			}

			if (!$migration()) {
				Log::error(sprintf('Schema migration %d failed; schema left at version %d', $version, $this->storedVersion()));

				return false;
			}

			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) $version], true, $this->connection);

			Log::info(sprintf('Schema migrated to version %d', $version));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared('UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?', [$version, $author, $homepage, self::PLUGIN], true, $this->connection);
	}

	/**
	 * Whether core has the plugin enabled (plugin_config status 1).
	 */
	public function pluginEnabled(): bool {
		return (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection) === 1;
	}

		public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));

		$hooks  = (int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)", array_merge([self::PLUGIN], array_keys(self::HOOKS)), '', true, $this->connection);
		$realms = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', [self::PLUGIN], '', true, $this->connection);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Stop Cacti from dropping the plugin tables on uninstall, and keep the
	 * schema version so a reinstall reuses them. True only when no table is
	 * still tracked afterwards, because core drops every tracked table.
	 */
	public function untrackTables(): bool {
		$ok = db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], true, $this->connection);

		return $ok !== false && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], '', true, $this->connection) === 0;
	}

	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	/**
	 * @return array<int, \Closure(): bool> schema version => migration
	 */
	private function migrations(): array {
		return [
			1 => fn (): bool => $this->createTables(self::tables()),
		];
	}

	/**
	 * Track every existing plugin table for uninstall again.
	 *
	 * Core records a table only when it creates one, so tables kept through an
	 * uninstall would otherwise never be dropped by a later one. The row is
	 * the one api_plugin_db_table_create() writes; the table has no unique
	 * key, so an existing row is checked for rather than replaced.
	 */
	public function trackTables(): void {
		foreach (array_keys(self::tables()) as $table) {
			if ($this->tableExists($table)) {
				db_execute_prepared("INSERT INTO plugin_db_changes (plugin, `table`, `column`, `method`) SELECT ?, ?, '', 'create' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND `method` = 'create')", [self::PLUGIN, $table, self::PLUGIN, $table], true, $this->connection);
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $tables
	 */
	private function createTables(array $tables): bool {
		foreach ($tables as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s', $table));

				return false;
			}
		}

		return true;
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], '', true, $this->connection) > 0;
	}

	/**
	 * Table definitions in api_plugin_db_table_create() format.
	 *
	 * State values are stored as varchar enum values, never list positions.
	 * Times are Unix seconds. No table holds a secret in the clear.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		$innodb   = ['type' => 'InnoDB', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic'];
		$id       = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
		$time     = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$text     = static fn (string $name, int $length, string $default = ''): array => ['name' => $name, 'type' => "varchar($length)", 'NULL' => false, 'default' => $default];

		return [
			// GPU nodes: Cacti devices an operator has registered for GPU
			// monitoring. How a node is reached is decided by #7 and added
			// by the collector's migration; nothing here is a command, path,
			// or URL.
			'plugin_gpu_node' => $innodb + [
				'comment' => 'Cacti devices registered as GPU nodes',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('host_id'),
					$text('admin_state', 16, 'enabled'),
					$time('created_at'),
					$time('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'host_id', 'columns' => ['host_id']]],
			],
			// One row per GPU identity, keyed by vendor and UUID (ADR 0003,
			// REQ-ID-001). Index, bus ID, name, driver, and node are
			// attributes that change in place (REQ-ID-002). Rows are never
			// deleted by collection (REQ-ID-005). Serial numbers are not
			// stored.
			'plugin_gpu_gpu' => $innodb + [
				'comment' => 'GPU identity records',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('vendor', 16),
					$text('uuid', 96),
					$id('node_id'),
					['name' => 'gpu_index', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => true],
					$text('pci_bus_id', 32),
					$text('name', 128),
					$text('driver_version', 64),
					$text('tool', 32),
					$text('tool_version', 64),
					$text('presence', 16, 'present'),
					$id('missing_count') + ['default' => '0'],
					// The absent record this GPU appeared in place of (REQ-ID-003); 0 for none.
					$id('replaces_gpu_id') + ['default' => '0'],
					// The node this GPU was last seen at, awaiting operator confirmation (REQ-ID-004); 0 for none.
					$id('pending_node_id') + ['default' => '0'],
					$time('pending_since'),
					$time('first_seen'),
					$time('last_seen'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'vendor_uuid', 'columns' => ['vendor', 'uuid']]],
				'keys'        => [['name' => 'node_id', 'columns' => ['node_id']]],
			],
		];
	}
}
