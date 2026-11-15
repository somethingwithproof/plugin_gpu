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
 * The Cacti device a node is mapped to, with what the collector needs: its
 * SNMP settings, availability, and poller. The SNMP secrets stay in this
 * object and are never logged or printed.
 */
final readonly class NodeDevice {
	public const HOST_DOWN = 1;

	public function __construct(
		public int $id,
		public string $description,
		public string $hostname,
		public int $pollerId,
		public int $status,
		public bool $disabled,
		public int $snmpVersion,
		public string $snmpCommunity,
		public string $snmpUsername,
		public string $snmpPassword,
		public string $snmpAuthProtocol,
		public string $snmpPrivPassphrase,
		public string $snmpPrivProtocol,
		public string $snmpContext,
		public string $snmpEngineId,
		public int $snmpPort,
		public int $snmpTimeout,
		public int $maxOids,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row a host row
	 */
	public static function fromRow(array $row): self {
		return new self(
			id: Row::int($row, 'id'),
			description: Row::string($row, 'description'),
			hostname: Row::string($row, 'hostname'),
			pollerId: Row::int($row, 'poller_id', 1, 1),
			status: Row::int($row, 'status'),
			disabled: Row::string($row, 'disabled') === 'on',
			snmpVersion: Row::int($row, 'snmp_version'),
			snmpCommunity: Row::string($row, 'snmp_community'),
			snmpUsername: Row::string($row, 'snmp_username'),
			snmpPassword: Row::string($row, 'snmp_password'),
			snmpAuthProtocol: Row::string($row, 'snmp_auth_protocol'),
			snmpPrivPassphrase: Row::string($row, 'snmp_priv_passphrase'),
			snmpPrivProtocol: Row::string($row, 'snmp_priv_protocol'),
			snmpContext: Row::string($row, 'snmp_context'),
			snmpEngineId: Row::string($row, 'snmp_engine_id'),
			snmpPort: Row::int($row, 'snmp_port', 161, 1),
			snmpTimeout: Row::int($row, 'snmp_timeout', 500, 1),
			maxOids: Row::int($row, 'max_oids', 10, 1),
		);
	}

	/**
	 * Cacti's run_data_query() returns true without reindexing a device in
	 * this state, even when forced (research/cacti-core.md).
	 */
	public function reindexBlocked(): bool {
		return $this->status === self::HOST_DOWN || $this->disabled;
	}
}
