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
 * Page permissions and POST-only actions over HTTP (REQ-UI-001 to
 * REQ-UI-005). Requests each plugin page as four users:
 *
 *   anonymous  not logged in: both pages show the login form
 *   admin      GPU View and GPU Admin: both pages load
 *   viewer     Console and GPU View: inventory loads, nodes is denied, and
 *              GPUs of a device the viewer may not see are left out
 *   norealm    Console only: both pages are denied
 *
 * Then shows that tool-reported strings and device descriptions are escaped,
 * that a delete or move confirmation sent as GET, or as POST without the
 * csrf-magic token, has no effect, and that the same action as a proper POST
 * works. Disposable databases only: it resets the admin password and adds
 * users and devices. Must parse on PHP 8.0.
 *
 *   GPU_TEST_DATABASE=disposable php plugins/gpu/tests/bin/page-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('GPU_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set GPU_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/gpu/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Gpu-' . bin2hex(random_bytes(8));
$failures = 0;

$view = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'gpu' AND file = 'gpu_inventory.php'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

$users = array();

// Realm 8 is Console access. The viewer's policies deny by default (2), so
// only the devices granted to it below are visible, as Cacti's permissive
// model evaluates them; an allow policy with no tree exception is treated by
// core as access to every device.
foreach (array('viewer' => array(8, $view), 'norealm' => array(8)) as $name => $realms) {
	$policy = $name === 'viewer' ? 2 : 1;

	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', ?, 1, ?, ?, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name, $policy, $policy, $policy));
	$users[$name] = (int) db_fetch_insert_id();

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $users[$name]));
	}
}

// Two devices with GPU nodes. The viewer is granted only the first one,
// through a device permission (user_auth_perms type 3 under a deny policy).
$devices = array();
$nodes   = array();

foreach (array('open' => '<b id="gpuprobe">Open node</b>', 'hidden' => 'Hidden node') as $key => $description) {
	db_execute_prepared('INSERT INTO host (description, hostname) VALUES (?, ?)', array($description, $key . '.invalid'));
	$devices[$key] = (int) db_fetch_insert_id();
	db_execute_prepared("INSERT INTO plugin_gpu_node (host_id, admin_state) VALUES (?, 'enabled')", array($devices[$key]));
	$nodes[$key] = (int) db_fetch_insert_id();
}

db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)', array($users['viewer'], $devices['open']));

$open   = 'GPU-00000000-0000-4000-8000-0000000000a1';
$hidden = 'GPU-00000000-0000-4000-8000-0000000000b2';

db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, pci_bus_id, name) VALUES ('nvidia', ?, ?, '00000000:3B:00.0', ?)", array($open, $nodes['open'], '<img src=x id="gpuname">'));
db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, pci_bus_id, name, pending_node_id, pending_since) VALUES ('nvidia', ?, ?, '00000000:3C:00.0', 'Hidden GPU', ?, 1)", array($hidden, $nodes['hidden'], $nodes['open']));
$moving = (int) db_fetch_insert_id();

function perm_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function perm_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

function perm_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function perm_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'gpuperm');

	if ($user === 'anonymous') {
		return $jar;
	}

	list(, $login) = perm_http($root . 'index.php', $jar);
	perm_http($root . 'index.php', $jar, array('__csrf_magic' => perm_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

// Markers that only the rendered plugin page contains.
$pages = array('gpu_nodes.php' => 'Export Records', 'gpu_inventory.php' => '<th>PCI Bus ID</th>');
$plans = array(
	'anonymous' => array('gpu_nodes.php' => false, 'gpu_inventory.php' => false),
	'admin'     => array('gpu_nodes.php' => true, 'gpu_inventory.php' => true),
	'viewer'    => array('gpu_nodes.php' => false, 'gpu_inventory.php' => true),
	'norealm'   => array('gpu_nodes.php' => false, 'gpu_inventory.php' => false),
);

foreach ($plans as $user => $expect) {
	$jar = perm_login($user);

	foreach ($expect as $page => $allowed) {
		list($code, $body) = perm_http($base . $page, $jar);
		$shown = strpos($body, $pages[$page]) !== false;

		perm_expect($shown === $allowed && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$user " . ($allowed ? 'sees' : 'is denied') . " $page (HTTP $code)");

		if ($user === 'anonymous') {
			perm_expect(strpos($body, 'login_username') !== false, "anonymous is sent to the login form from $page");
		}

		if ($page === 'gpu_inventory.php' && $allowed) {
			$sees = strpos($body, $hidden) !== false;
			perm_expect(strpos($body, $open) !== false && $sees === ($user === 'admin'), "$user " . ($user === 'admin' ? 'sees' : 'does not see') . ' the GPU of a device it ' . ($user === 'admin' ? 'may' : 'may not') . ' view');

			// Tool-reported names and device descriptions are text.
			perm_expect(strpos($body, '<img src=x id="gpuname">') === false && strpos($body, '&lt;img src=x id=&quot;gpuname&quot;&gt;') !== false, "$user's inventory escapes the GPU name");
			perm_expect(strpos($body, '<b id="gpuprobe">') === false && strpos($body, '&lt;b id=&quot;gpuprobe&quot;&gt;') !== false, "$user's inventory escapes the device description");
		}
	}

	unlink($jar);
}

$jar = perm_login('admin');

foreach (array('gpu_nodes.php', 'gpu_nodes.php?action=edit&id=' . $nodes['open']) as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, '<b id="gpuprobe">') === false && strpos($body, '&lt;b id=&quot;gpuprobe&quot;&gt;') !== false, "$page escapes the device description");
}

$pending = function () use ($moving) {
	return (int) db_fetch_cell_prepared('SELECT pending_node_id FROM plugin_gpu_gpu WHERE id = ?', array($moving));
};

perm_http($base . 'gpu_nodes.php?action=confirm_move&id=' . $moving, $jar);
perm_expect($pending() === $nodes['open'], 'a move confirmation sent as GET has no effect');

perm_http($base . 'gpu_nodes.php', $jar, array('action' => 'confirm_move', 'id' => $moving));
perm_expect($pending() === $nodes['open'], 'a move confirmation POST without the csrf-magic token has no effect');

list(, $list) = perm_http($base . 'gpu_nodes.php', $jar);
perm_expect(strpos($list, 'GPU Moves Awaiting Confirmation') !== false, 'the pending move is listed for confirmation');
perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'confirm_move', 'id' => $moving));
perm_expect($pending() === 0 && (int) db_fetch_cell_prepared('SELECT node_id FROM plugin_gpu_gpu WHERE id = ?', array($moving)) === $nodes['open'], 'the same confirmation as a POST with the token moves the GPU');

// A node with GPU records is refused; an empty one is removed, and its Cacti
// device stays.
db_execute_prepared("INSERT INTO host (description, hostname) VALUES ('Empty node', 'empty.invalid')");
$emptyDevice = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_gpu_node (host_id, admin_state) VALUES (?, 'enabled')", array($emptyDevice));
$empty = (int) db_fetch_insert_id();

$exists = function ($id) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_gpu_node WHERE id = ?', array($id)) === 1;
};

perm_http($base . 'gpu_nodes.php?action=delete&id=' . $empty, $jar);
perm_expect($exists($empty), 'a delete sent as GET has no effect');

list(, $list) = perm_http($base . 'gpu_nodes.php', $jar);
perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'delete', 'id' => $nodes['open']));
perm_expect($exists($nodes['open']), 'a node that owns GPU records is not deleted');

list(, $list) = perm_http($base . 'gpu_nodes.php', $jar);
perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'delete', 'id' => $empty));
perm_expect(!$exists($empty) && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', array($emptyDevice)) === 1, 'an empty node is deleted as a POST with the token, and its device stays');

// Registering a device through the form, and refusing it a second time.
list(, $form) = perm_http($base . 'gpu_nodes.php?action=edit', $jar);
perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => perm_token($form), 'action' => 'save', 'id' => 0, 'host_id' => $emptyDevice, 'enabled' => 'on'));
perm_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_gpu_node WHERE host_id = ?', array($emptyDevice)) === 1, 'a device is registered as a node through the form');

list(, $again) = perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => perm_token($form), 'action' => 'save', 'id' => 0, 'host_id' => $emptyDevice, 'enabled' => 'on'));
perm_expect(strpos($again, 'already a GPU node') !== false && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_gpu_node WHERE host_id = ?', array($emptyDevice)) === 1, 'the same device is refused a second time');

// The export is GPU Admin only and holds every record.
list($code, $export) = perm_http($base . 'gpu_nodes.php?action=export', $jar);
$document = json_decode($export, true);
perm_expect($code === 200 && is_array($document) && count($document['gpus']) === 2 && count($document['nodes']) === 3, 'GPU Admin downloads every node and GPU record');

unlink($jar);

$jar = perm_login('viewer');
list(, $export) = perm_http($base . 'gpu_nodes.php?action=export', $jar);
perm_expect(strpos($export, $open) === false, 'a GPU View user cannot download the export');
unlink($jar);

exit($failures > 0 ? 1 : 0);
