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
 * NET-SNMP-EXTEND-MIB through Cacti's own SNMP functions, with the
 * credentials Cacti holds for the device (ADR 0008).
 *
 * The tables are indexed by the entry name, encoded as its length and then
 * one sub-identifier per byte, and, in nsExtendOutput2Table, by line number.
 * The line number is a position, never an identity: the GPU UUID comes from
 * the line itself (ADR 0003).
 */
final class SnmpExtendReader implements ExtendReader {
	// NET-SNMP-EXTEND-MIB::nsExtendObjects
	private const OBJECTS = '.1.3.6.1.4.1.8072.1.3.2';
	// nsExtendOutput1Entry columns 3 and 4.
	private const NUM_LINES = self::OBJECTS . '.3.1.3';
	private const RESULT    = self::OBJECTS . '.3.1.4';
	// nsExtendOutput2Entry column 2.
	private const OUT_LINE  = self::OBJECTS . '.4.1.2';
	// SNMPv2-MIB::sysUpTime.0, read only to tell a missing entry from a
	// silent agent.
	private const UPTIME    = '.1.3.6.1.2.1.1.3.0';

	// The first read after snmpd's cache expires waits while the agent runs
	// the command, up to timeout(1)'s limit and kill delay, so it gets that
	// long plus a margin for the network, whatever the device's timeout.
	public const EXTEND_TIMEOUT_MS = (SnmpdConfig::TIMEOUT + SnmpdConfig::KILL_AFTER + 3) * 1000;

	public function __construct(private readonly int $retries) {
	}

	public static function index(string $token): string {
		return strlen($token) . '.' . implode('.', array_map('ord', str_split($token)));
	}

	public function read(NodeDevice $device, string $token): ExtendOutput {
		$index  = self::index($token);
		$result = $this->get($device, self::RESULT . '.' . $index, max($device->snmpTimeout, self::EXTEND_TIMEOUT_MS));

		if (!is_numeric($result)) {
			$uptime = $this->get($device, self::UPTIME);

			throw $uptime !== '' && $uptime !== 'U'
				? new CollectionError(ExecutionState::NotConfigured, sprintf('The SNMP agent has no extend entry named %s', $token))
				: new CollectionError(ExecutionState::Unreachable, 'The SNMP agent did not answer');
		}

		$declared = $this->get($device, self::NUM_LINES . '.' . $index);
		$lines    = [];
		$prefix   = ltrim(self::OUT_LINE . '.' . $index . '.', '.');

		foreach ($this->walk($device, self::OUT_LINE . '.' . $index) as $entry) {
			$oid = ltrim(Row::string($entry, 'oid'), '.');

			if (!str_starts_with($oid, $prefix) || !ctype_digit(substr($oid, strlen($prefix)))) {
				continue;
			}

			$lines[(int) substr($oid, strlen($prefix))] = Row::string($entry, 'value');
		}

		ksort($lines);

		// The result, the line count, and the lines are separate requests,
		// and snmpd may run the command again between them when its cache
		// expires. Reading the result and count again after the lines shows
		// whether they all came from one run; a run that changed them (a
		// timeout that left partial output, for example) is not used.
		$after         = $this->get($device, self::RESULT . '.' . $index, max($device->snmpTimeout, self::EXTEND_TIMEOUT_MS));
		$declaredAfter = $this->get($device, self::NUM_LINES . '.' . $index);

		if ($after !== $result || $declaredAfter !== $declared) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('The output of %s changed while it was read; the next collection reads it again', $token));
		}

		return new ExtendOutput((int) $result, is_numeric($declared) ? (int) $declared : -1, array_values($lines));
	}

	private function get(NodeDevice $device, string $oid, ?int $timeout = null): string {
		return trim((string) cacti_snmp_get($device->hostname, $device->snmpCommunity, $oid, $device->snmpVersion, $device->snmpUsername, $device->snmpPassword, $device->snmpAuthProtocol, $device->snmpPrivPassphrase, $device->snmpPrivProtocol, $device->snmpContext, $device->snmpPort, $timeout ?? $device->snmpTimeout, $this->retries, 'GPU', $device->snmpEngineId));
	}

	/**
	 * @return list<array<array-key, mixed>>
	 */
	private function walk(NodeDevice $device, string $oid): array {
		$rows = cacti_snmp_walk($device->hostname, $device->snmpCommunity, $oid, $device->snmpVersion, $device->snmpUsername, $device->snmpPassword, $device->snmpAuthProtocol, $device->snmpPrivPassphrase, $device->snmpPrivProtocol, $device->snmpContext, $device->snmpPort, $device->snmpTimeout, $this->retries, $device->maxOids, 'GPU', $device->snmpEngineId);

		return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
	}
}
