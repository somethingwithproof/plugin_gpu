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
	public const VERSION = 4;

	public const HOOKS = [
		'auth_alternate_realms'      => 'gpu_auth_alternate_realms',
		'config_arrays'              => 'gpu_config_arrays',
		'config_settings'            => 'gpu_config_settings',
		'draw_navigation_text'       => 'gpu_draw_navigation_text',
		'poller_bottom'              => 'gpu_poller_bottom',
		'device_remove'              => 'gpu_device_remove',
		'api_device_new'             => 'gpu_api_device_new',
		'replicate_out'              => 'gpu_replicate_out',
		'remote_agent'               => 'gpu_remote_agent',
		'abilities_register'         => 'gpu_abilities_register',
		'doctor_register_checks'     => 'gpu_doctor_register_checks',
		'doctor_register_debug_info' => 'gpu_doctor_register_debug_info',
		'casc_register_providers'    => 'gpu_casc_register_providers',
		'api_register_resources'     => 'gpu_api_register_resources',
	];

	// The metric columns of plugin_gpu_observation, as migration 2 created
	// them. A later model change adds a migration; it never edits this list.
	// age is computed when it is read, so it has no column.
	public const OBSERVATION_METRICS = [
		'util_gpu', 'util_mem', 'mem_used', 'mem_total', 'temp_gpu', 'power', 'power_limit',
		'clock_sm', 'clock_mem', 'ecc_corr', 'ecc_uncorr', 'retired_pending',
		'thr_sw_power_cap', 'thr_hw_slowdown', 'thr_sw_thermal', 'thr_hw_thermal', 'thr_hw_power_brake',
	];

	// The metric columns migration 3 added. The two _new columns are
	// computed by ObservationRepository from consecutive samples
	// (MetricModel::DELTAS).
	public const OBSERVATION_METRICS_3 = ['ecc_corr_new', 'ecc_uncorr_new', 'retired_sbe', 'retired_dbe'];

	private const PLUGIN  = 'gpu';
	private const SETTING = 'gpu_schema_version';

		/**
	 * Construct a Schema instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

		/**
	 * Stored version.
	 *
	 * @return int
	 */
	public function storedVersion(): int {
		$value = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [self::SETTING], '', true, $this->connection);

		return is_numeric($value) ? (int) $value : 0;
	}

		/**
	 * Is current.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration, or stop at the first that fails.
	 *
	 * @param int $through the last migration to run; tests pass a lower
	 *                     number to leave an upgrade interrupted
	 * @return bool
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

		/**
	 * Recorded plugin version.
	 *
	 * @return string
	 */
	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection);
	}

		/**
	 * Record plugin version.
	 *
	 * @param string $version
	 * @param string $author
	 * @param string $homepage
	 */
	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared('UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?', [$version, $author, $homepage, self::PLUGIN], true, $this->connection);
	}

	/**
	 * Whether core has the plugin enabled (plugin_config status 1).
	 * @return bool
	 */
	public function pluginEnabled(): bool {
		return (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection) === 1;
	}

		/**
	 * Hooks registered.
	 *
	 * @return bool
	 */
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
	 * @return bool
	 */
	public function untrackTables(): bool {
		$ok = db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], true, $this->connection);

		return $ok !== false && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], '', true, $this->connection) === 0;
	}

		/**
	 * Forget version.
	 */
	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	/**
	 * @return array<int, \Closure(): bool> schema version => migration
	 */
	private function migrations(): array {
		return [
			1 => fn (): bool => $this->createTables(array_intersect_key(self::tables(), array_flip(['plugin_gpu_node', 'plugin_gpu_gpu']))),
			2 => fn (): bool => $this->createTables(array_intersect_key(self::tables(), array_flip(['plugin_gpu_observation', 'plugin_gpu_collection', 'plugin_gpu_reindex', 'plugin_gpu_retired']))),
			// ECC delta, retired-page, and ECC mode columns (#45). A fresh
			// install created them in migration 2, from the current table.
			3 => fn (): bool => $this->addColumns('plugin_gpu_observation', self::observationColumns3()),
			// MIG instance identity and AMD partition attributes (#56, #59).
			// A fresh install created them in migration 1, from the current table.
			4 => fn (): bool => $this->addColumns('plugin_gpu_gpu', self::gpuColumns4()),
		];
	}

	/**
	 * Every metric column of plugin_gpu_observation.
	 *
	 * @return list<string>
	 */
	public static function observationMetrics(): array {
		return array_merge(self::OBSERVATION_METRICS, self::OBSERVATION_METRICS_3);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function observationColumns3(): array {
		return array_merge(
			array_map(static fn (string $name): array => ['name' => $name, 'type' => 'double', 'NULL' => true], self::OBSERVATION_METRICS_3),
			[['name' => 'ecc_mode', 'type' => 'varchar(16)', 'NULL' => false, 'default' => '']],
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function gpuColumns4(): array {
		return [
			['name' => 'mig_mode', 'type' => 'varchar(16)', 'NULL' => false, 'default' => ''],
			['name' => 'mig_instance_id', 'type' => 'varchar(32)', 'NULL' => false, 'default' => ''],
			['name' => 'mig_profile', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
			['name' => 'parent_uuid', 'type' => 'varchar(96)', 'NULL' => false, 'default' => ''],
			['name' => 'partition_info', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
		];
	}

	/**
	 * @param list<array<string, mixed>> $columns
	 * @param string $table
	 * @return bool
	 */
	private function addColumns(string $table, array $columns): bool {
		foreach ($columns as $column) {
			db_add_column($table, $column, true, $this->connection);

			if (db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column['name']], '', true, $this->connection) < 1) {
				Log::error(sprintf('Unable to add column %s to %s', Row::string($column, 'name'), $table));

				return false;
			}
		}

		return true;
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
	 * @return bool
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

		/**
	 * Table exists.
	 *
	 * @param string $table
	 * @return bool
	 */
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
		$count    = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$metric   = static fn (string $name): array => ['name' => $name, 'type' => 'double', 'NULL' => true];

		return [
			// GPU nodes: Cacti devices an operator has registered for GPU
			// monitoring. A node is read through its device's SNMP settings
			// (ADR 0008), so nothing here is a command, path, credential, or
			// URL.
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
					$text('mig_mode', 16),
					$text('mig_instance_id', 32),
					$text('mig_profile', 64),
					$text('parent_uuid', 96),
					$text('partition_info', 128),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'vendor_uuid', 'columns' => ['vendor', 'uuid']]],
				'keys'        => [['name' => 'node_id', 'columns' => ['node_id']]],
			],
			// The latest sample of each GPU, in model units (ADR 0009); NULL
			// is unknown. The script server input reads only this table and
			// the records above (REQ-SS-001). A sample replaces the stored
			// one only when the tool took it later, so a delayed or repeated
			// read never moves a value back in time.
			'plugin_gpu_observation' => $innodb + [
				'comment' => 'Latest GPU samples',
				'columns' => array_merge(
					[
						$id('gpu_id'),
						$id('node_id'),
						// When the tool took the sample, from its own timestamp.
						$time('observed_at'),
						// When the plugin stored it.
						$time('ingested_at'),
						// When a successful collection last reported this GPU,
						// even with an unchanged sample.
						$time('reported_at'),
						$text('backend', 32),
						$text('tool_version', 64),
					],
					array_map($metric, self::OBSERVATION_METRICS),
					// The raw clock event reasons mask, so bits without an
					// indicator are kept (ADR 0009).
					[['name' => 'thr_mask', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => true]],
					self::observationColumns3(),
				),
				'primary' => 'gpu_id',
				'keys'    => [['name' => 'node_id', 'columns' => ['node_id']]],
			],
			// How the latest collection at each node ended (PLAN,
			// Measurement state). The state is an ExecutionState value; the
			// detail never quotes tool output.
			'plugin_gpu_collection' => $innodb + [
				'comment' => 'Latest collection result per node',
				'columns' => [
					$id('node_id'),
					$text('state', 32),
					$text('detail', 255),
					$time('started_at'),
					$time('finished_at'),
					// The newest sample time in the collection.
					$time('observed_at'),
					$count('gpus'),
					$count('new_gpus'),
					$count('rejected_lines'),
					// Runs skipped because another held the node, since the
					// last one that ran.
					$count('skipped'),
					$time('last_ok_at'),
				],
				'primary' => 'node_id',
			],
			// A data query reindex the plugin owes a node's device, kept
			// until it runs. Cacti's run_data_query() returns true without
			// reindexing a device that is down or disabled, so a request
			// stays here until the device is up (REQ-INDEX-004).
			'plugin_gpu_reindex' => $innodb + [
				'comment' => 'Pending data query reindexes per node',
				'columns' => [
					$id('node_id'),
					$time('requested_at'),
					$text('reason', 64),
					// Moves on with every request; see ReindexRepository::done().
					$count('generation'),
					$count('attempts'),
					$time('last_attempt_at'),
					$text('detail', 255),
				],
				'primary' => 'node_id',
			],
			// GPUs an operator removed from the data query (REQ-INDEX-005).
			// The identity record and its graphs stay; the GPU only stops
			// being an index, and returns as one if it is reported again.
			'plugin_gpu_retired' => $innodb + [
				'comment' => 'GPUs removed from the data query by an operator',
				'columns' => [
					$id('gpu_id'),
					$time('retired_at'),
					$id('retired_by') + ['default' => '0'],
				],
				'primary' => 'gpu_id',
			],
		];
	}
}
