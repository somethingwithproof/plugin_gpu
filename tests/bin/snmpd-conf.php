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

/*
 * Print the snmpd.conf the end-to-end test runs the agent with:
 *
 *   php tests/bin/snmpd-conf.php <secrets file> [agent address]
 *
 * The extend lines are taken from docs/node-setup.md as written, so the test
 * exercises what an administrator installs. The rest follows the same page:
 * an unprivileged agentuser, and an SNMPv3 authPriv user limited to the
 * system group and the extend tables. The createUser line goes in this file
 * rather than the persistent one, which net-snmp also accepts, because the
 * test agent starts from nothing each run. The secrets file holds the
 * authentication and privacy passphrases, one per line; they never appear on
 * a command line.
 */

$doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/node-setup.md');

if (preg_match('/<!-- extend-lines:start -->\n```\n(.*)\n```\n<!-- extend-lines:end -->/s', $doc, $match) !== 1) {
	fwrite(STDERR, "docs/node-setup.md has no extend lines block\n");
	exit(1);
}

$secrets = file((string) ($argv[1] ?? ''), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (!is_array($secrets) || count($secrets) < 2 || preg_match('/^[A-Za-z0-9]{16,64}$/', $secrets[0]) !== 1 || preg_match('/^[A-Za-z0-9]{16,64}$/', $secrets[1]) !== 1) {
	fwrite(STDERR, "usage: snmpd-conf.php <secrets file with two alphanumeric passphrases> [agent address]\n");
	exit(2);
}

print 'agentaddress ' . ($argv[2] ?? 'udp:161') . "\n"
	. "agentuser Debian-snmp\n"
	. "dontLogTCPWrappersConnects yes\n"
	. "view   cactigpu included .1.3.6.1.2.1.1\n"
	. "view   cactigpu included .1.3.6.1.4.1.8072.1.3.2\n"
	. "rouser -s usm cactigpu priv -V cactigpu\n"
	. "createUser cactigpu SHA-256 {$secrets[0]} AES {$secrets[1]}\n"
	. $match[1] . "\n";
