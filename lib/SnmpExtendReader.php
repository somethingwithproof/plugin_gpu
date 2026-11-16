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
		$result = $this->value($device, self::RESULT . '.' . $index, max($device->snmpTimeout, self::EXTEND_TIMEOUT_MS));

		if (!is_numeric($result)) {
			$uptime = $this->value($device, self::UPTIME);

			throw $uptime !== '' && $uptime !== 'U'
				? new CollectionError(ExecutionState::NotConfigured, sprintf('The SNMP agent has no extend entry named %s', $token))
				: new CollectionError(ExecutionState::Unreachable, 'The SNMP agent did not answer');
		}

		$declared = $this->value($device, self::NUM_LINES . '.' . $index);
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

		// An agent that gives the status and count but none of the lines
		// stopped answering between the requests, or refused the walk (an
		// SNMPv3 user it no longer accepts, for one); that is not output cut
		// short by snmpd.
		if ($lines === [] && is_numeric($declared) && (int) $declared > 0) {
			throw new CollectionError(ExecutionState::Unreachable, sprintf('The SNMP agent gave none of the %d output lines of %s', (int) $declared, $token));
		}

		// The result, the line count, and the lines are separate requests,
		// and snmpd may run the command again between them when its cache
		// expires. Reading the result and count again after the lines shows
		// whether they all came from one run; a run that changed them (a
		// timeout that left partial output, for example) is not used.
		$after         = $this->value($device, self::RESULT . '.' . $index, max($device->snmpTimeout, self::EXTEND_TIMEOUT_MS));
		$declaredAfter = $this->value($device, self::NUM_LINES . '.' . $index);

		if ($after !== $result || $declaredAfter !== $declared) {
			throw new CollectionError(ExecutionState::InvalidOutput, sprintf('The output of %s changed while it was read; the next collection reads it again', $token));
		}

		return new ExtendOutput((int) $result, is_numeric($declared) ? (int) $declared : -1, array_values($lines));
	}

	/**
	 * One value from the device, trimmed; '' or 'U' when it gave none.
	 *
	 * Read through a Cacti SNMP session built from the device's fields, as
	 * Cacti's poller and data queries read a device. Cacti 1.2.x's
	 * cacti_snmp_get() puts an IPv4 address or a host name in brackets when
	 * the port is not 161, and PHP's SNMP extension then resolves the name
	 * as IPv6 only, so every read of such a device fails ("Unknown failure
	 * while resolving '[192.0.2.10]:1161'"). Its sessions bracket only an
	 * address that contains a colon. cacti_snmp_get() is still used when no
	 * session can be made, such as on Cacti 1.2.31 without PHP's SNMP
	 * extension, where it runs the Net-SNMP tools and is not affected.
	 */
	public function value(NodeDevice $device, string $oid, ?int $timeout = null): string {
		$timeout ??= $device->snmpTimeout;

		try {
			$session = cacti_snmp_session($device->hostname, $device->snmpCommunity, $device->snmpVersion, $device->snmpUsername, $device->snmpPassword, $device->snmpAuthProtocol, $device->snmpPrivPassphrase, $device->snmpPrivProtocol, $device->snmpContext, $device->snmpEngineId, $device->snmpPort, $timeout, $this->retries, $device->maxOids);
		} catch (\Throwable) {
			// Cacti 1.2.31 lets an Error from PHP's SNMP class through: the
			// class is missing, or it rejects an SNMPv3 protocol.
			$session = false;
		}

		if (!is_object($session)) {
			return trim((string) cacti_snmp_get($device->hostname, $device->snmpCommunity, $oid, $device->snmpVersion, $device->snmpUsername, $device->snmpPassword, $device->snmpAuthProtocol, $device->snmpPrivPassphrase, $device->snmpPrivProtocol, $device->snmpContext, $device->snmpPort, $timeout, $this->retries, 'GPU', $device->snmpEngineId));
		}

		try {
			$value = cacti_snmp_session_get($session, $oid);
		} catch (\Throwable) {
			$value = false;
		}

		return is_scalar($value) && $value !== false ? trim((string) $value) : '';
	}

	/**
	 * @return list<array<array-key, mixed>>
	 */
	private function walk(NodeDevice $device, string $oid): array {
		$rows = cacti_snmp_walk($device->hostname, $device->snmpCommunity, $oid, $device->snmpVersion, $device->snmpUsername, $device->snmpPassword, $device->snmpAuthProtocol, $device->snmpPrivPassphrase, $device->snmpPrivProtocol, $device->snmpContext, $device->snmpPort, $device->snmpTimeout, $this->retries, $device->maxOids, 'GPU', $device->snmpEngineId);

		return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
	}
}
