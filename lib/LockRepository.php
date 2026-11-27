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
 * Named MySQL advisory locks, scoped to the Cacti database so two Cacti
 * installs on one server never share one. A lock belongs to the database
 * connection, so the server frees it if the holder dies (REQ-EXEC-005,
 * REQ-TPL-005).
 */
final class LockRepository {
	// The server-wide lock name: hashed, because MySQL and MariaDB limit
	// lock names to 64 characters and a database name alone may be longer.
	private const NAME = "SHA2(CONCAT(DATABASE(), '.plugin_gpu.', ?), 256)";

		/**
	 * Construct a LockRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Take a lock without waiting. False when another connection holds it or
	 * the server could not be asked.
	 * @param string $name
	 * @return bool
	 */
	public function acquire(string $name): bool {
		return (int) db_fetch_cell_prepared('SELECT GET_LOCK(' . self::NAME . ', 0)', [$name], '', true, $this->connection) === 1;
	}

		/**
	 * Release.
	 *
	 * @param string $name
	 */
	public function release(string $name): void {
		db_fetch_cell_prepared('SELECT RELEASE_LOCK(' . self::NAME . ')', [$name], '', true, $this->connection);
	}
}
