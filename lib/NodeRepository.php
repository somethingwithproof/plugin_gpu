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
 * Registered GPU nodes. Reads Cacti's host table for descriptions and never
 * writes it: registering, disabling, or removing a node leaves the Cacti
 * device as it was (REQ-LIFE-003).
 */
final class NodeRepository {
	private const SELECT = 'SELECT n.*, h.id AS device_id, h.description FROM plugin_gpu_node AS n LEFT JOIN host AS h ON h.id = n.host_id';

	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function find(int $id): ?Node {
		$row = db_fetch_row_prepared(self::SELECT . ' WHERE n.id = ?', [$id], true, $this->connection);

		return is_array($row) && $row !== [] ? Node::fromRow($row) : null;
	}

	/**
	 * @return list<Node>
	 */
	public function all(): array {
		return array_map(Node::fromRow(...), Row::rows(db_fetch_assoc_prepared(self::SELECT . ' ORDER BY h.description, n.id', [], true, $this->connection)));
	}

	/**
	 * Cacti devices an operator can register: those not registered yet, plus
	 * the node's own device when one is being edited.
	 *
	 * @return array<int, string> host id => description
	 */
	public function devicesFor(int $nodeId): array {
		$devices = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT h.id, h.description FROM host AS h LEFT JOIN plugin_gpu_node AS n ON n.host_id = h.id WHERE n.id IS NULL OR n.id = ? ORDER BY h.description', [$nodeId], true, $this->connection)) as $row) {
			$devices[Row::int($row, 'id')] = Row::string($row, 'description');
		}

		return $devices;
	}

	/**
	 * The node's Cacti device with its SNMP settings, for the collector.
	 */
	public function device(Node $node): ?NodeDevice {
		$row = db_fetch_row_prepared('SELECT id, description, hostname, poller_id, status, disabled, snmp_version, snmp_community, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id, snmp_port, snmp_timeout, max_oids FROM host WHERE id = ?', [$node->hostId], true, $this->connection);

		return is_array($row) && $row !== [] ? NodeDevice::fromRow($row) : null;
	}

	public function deviceExists(int $hostId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', [$hostId], '', true, $this->connection) === 1;
	}

	public function hostTaken(int $hostId, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_gpu_node WHERE host_id = ? AND id != ?', [$hostId, $exceptId], '', true, $this->connection) > 0;
	}

	/**
	 * Insert ($id 0) or update a node, returning its id, or 0 when nothing
	 * was written.
	 */
	public function save(int $id, int $hostId, AdminState $state): int {
		$now = $this->clock->now();

		if ($id === 0) {
			$ok = db_execute_prepared('INSERT INTO plugin_gpu_node (host_id, admin_state, created_at, updated_at) VALUES (?, ?, ?, ?)', [$hostId, $state->value, $now, $now], true, $this->connection);

			return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
		}

		if ($this->find($id) === null) {
			return 0;
		}

		$ok = db_execute_prepared('UPDATE plugin_gpu_node SET host_id = ?, admin_state = ?, updated_at = ? WHERE id = ?', [$hostId, $state->value, $now, $id], true, $this->connection);

		return $ok ? $id : 0;
	}

	/**
	 * Remove a node that owns no GPU records. A node with records keeps them
	 * and can only be disabled, because GPU records are never deleted for
	 * the operator by accident (REQ-ID-005).
	 */
	public function delete(int $id): bool {
		// One statement, so a GPU recorded between a check and the delete
		// cannot be orphaned.
		$ok = db_execute_prepared('DELETE FROM plugin_gpu_node WHERE id = ? AND NOT EXISTS (SELECT 1 FROM plugin_gpu_gpu WHERE node_id = ? OR pending_node_id = ?)', [$id, $id, $id], true, $this->connection);

		return $ok && db_affected_rows($this->connection) === 1;
	}
}
