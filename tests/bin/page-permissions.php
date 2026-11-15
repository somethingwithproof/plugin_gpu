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
 *   scoped     Console and GPU Admin, granted only the first device: every
 *              node and GPU action on the second device is refused and
 *              writes nothing, and its records are not listed or exported
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

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'gpu' AND FIND_IN_SET('gpu_inventory.php', file) > 0");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'gpu' AND FIND_IN_SET('gpu_nodes.php', file) > 0");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

$users = array();

// Realm 8 is Console access. The viewer's policies deny by default (2), so
// only the devices granted to it below are visible, as Cacti's permissive
// model evaluates them; an allow policy with no tree exception is treated by
// core as access to every device.
foreach (array('viewer' => array(8, $view), 'norealm' => array(8), 'scoped' => array(8, $admin)) as $name => $realms) {
	$policy = $name === 'norealm' ? 1 : 2;

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
db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)', array($users['scoped'], $devices['open']));

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
$pages = array('gpu_nodes.php' => 'Export Records', 'gpu_inventory.php' => '<th>PCI Bus ID</th>', 'gpu_health.php' => '<th>Clock Event Reasons</th>');
$plans = array(
	'anonymous' => array('gpu_nodes.php' => false, 'gpu_inventory.php' => false, 'gpu_health.php' => false),
	'admin'     => array('gpu_nodes.php' => true, 'gpu_inventory.php' => true, 'gpu_health.php' => true),
	'viewer'    => array('gpu_nodes.php' => false, 'gpu_inventory.php' => true, 'gpu_health.php' => true),
	'norealm'   => array('gpu_nodes.php' => false, 'gpu_inventory.php' => false, 'gpu_health.php' => false),
	// GPU Admin alone does not include the GPU View pages: each page file
	// is checked against its own realm (REQ-PERM-004).
	'scoped'    => array('gpu_nodes.php' => true, 'gpu_inventory.php' => false, 'gpu_health.php' => false),
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

		if ($page !== 'gpu_nodes.php' && $allowed) {
			$sees = strpos($body, $hidden) !== false;
			perm_expect(strpos($body, $open) !== false && $sees === ($user === 'admin'), "$user " . ($user === 'admin' ? 'sees' : 'does not see') . ' the GPU of a device it ' . ($user === 'admin' ? 'may' : 'may not') . ' view');

			// Tool-reported names and device descriptions are text.
			perm_expect(strpos($body, '<img src=x id="gpuname">') === false && strpos($body, '&lt;img src=x id=&quot;gpuname&quot;&gt;') !== false, "$user's $page escapes the GPU name");
			perm_expect(strpos($body, '<b id="gpuprobe">') === false && strpos($body, '&lt;b id=&quot;gpuprobe&quot;&gt;') !== false, "$user's $page escapes the device description");
		}
	}

	unlink($jar);
}

// Revocation takes effect on the viewer's next request, in the same session
// (REQ-PERM-002): first the device permission, then the GPU View realm,
// which Cacti caches per session until user_auth.reset_perms changes, as
// it does when an administrator saves the user.
$jar = perm_login('viewer');

foreach (array('gpu_inventory.php', 'gpu_health.php') as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, $open) !== false, "viewer sees the open GPU on $page before revocation");
}

db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ? AND item_id = ? AND type = 3', array($users['viewer'], $devices['open']));

foreach (array('gpu_inventory.php', 'gpu_health.php') as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, $pages[$page]) !== false && strpos($body, $open) === false && strpos($body, 'Open node') === false, "viewer no longer sees the GPU or node on $page on the request after its device permission is revoked");
}

db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)', array($users['viewer'], $devices['open']));
db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', array($users['viewer'], $view));
db_execute_prepared('UPDATE user_auth SET reset_perms = ? WHERE id = ?', array(mt_rand(), $users['viewer']));

foreach (array('gpu_inventory.php', 'gpu_health.php') as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, $pages[$page]) === false && strpos($body, $open) === false, "viewer is denied $page on the request after GPU View is revoked");
}

db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($view, $users['viewer']));
unlink($jar);

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

// A GPU Admin restricted to the first device acts on the second one. Each
// attempt must be refused and leave every plugin table and the device's
// data query attachment as they were.
db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, pci_bus_id, name, presence) VALUES ('nvidia', 'GPU-00000000-0000-4000-8000-0000000000c3', ?, '00000000:3D:00.0', 'Absent hidden GPU', 'absent')", array($nodes['hidden']));
$absentHidden = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, pci_bus_id, name, pending_node_id, pending_since) VALUES ('nvidia', 'GPU-00000000-0000-4000-8000-0000000000d4', ?, '00000000:3E:00.0', 'Hidden to open', ?, 1)", array($nodes['hidden'], $nodes['open']));
$intoOpen = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, pci_bus_id, name, pending_node_id, pending_since) VALUES ('nvidia', 'GPU-00000000-0000-4000-8000-0000000000e5', ?, '00000000:3F:00.0', 'Open to hidden', ?, 1)", array($nodes['open'], $nodes['hidden']));
$intoHidden = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO host (description, hostname) VALUES ('Hidden empty node', 'hidden-empty.invalid')");
$hiddenEmptyDevice = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_gpu_node (host_id, admin_state) VALUES (?, 'enabled')", array($hiddenEmptyDevice));
$hiddenEmpty = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO host (description, hostname) VALUES ('Unregistered hidden', 'unregistered.invalid')");
$unregistered = (int) db_fetch_insert_id();

$state = function () {
	$tables = array();

	foreach (array('plugin_gpu_node', 'plugin_gpu_gpu', 'plugin_gpu_retired', 'plugin_gpu_reindex', 'host_snmp_query', 'host') as $table) {
		$tables[$table] = db_fetch_assoc('SELECT * FROM `' . $table . '` ORDER BY 1, 2');
	}

	return md5(serialize($tables));
};

$before = $state();
$jar    = perm_login('scoped');

list(, $list) = perm_http($base . 'gpu_nodes.php', $jar);
$token        = perm_token($list);

perm_expect(strpos($list, 'Export Records') !== false && strpos($list, 'Hidden node') === false && strpos($list, 'Hidden empty node') === false, 'scoped GPU Admin lists only the nodes of devices it may see');
perm_expect(strpos($list, '0000000000d4') === false && strpos($list, '0000000000e5') === false, 'scoped GPU Admin is not offered moves that involve a device it may not see');

list(, $edit) = perm_http($base . 'gpu_nodes.php?action=edit&id=' . $nodes['hidden'], $jar);
perm_expect(strpos($edit, 'do not have access') !== false && strpos($edit, 'Absent hidden GPU') === false, 'scoped GPU Admin cannot open a node on a device it may not see');
perm_expect(strpos($edit, 'hidden.invalid') === false && strpos($edit, 'Hidden node') === false && strpos($edit, $hidden) === false && strpos($edit, '0000000000c3') === false && strpos($edit, 'plugins/gpu/lib/') === false && strpos($edit, 'plugins/gpu/setup.php') === false && preg_match('#\.php(:| on line )\d#', $edit) !== 1, 'the refusal names no hostname, device, UUID, or path of the hidden node (REQ-PERM-005)');

list(, $edit) = perm_http($base . 'gpu_nodes.php?action=edit', $jar);
perm_expect(strpos($edit, 'Unregistered hidden') === false && strpos($edit, 'Hidden empty node') === false, 'scoped GPU Admin is offered only devices it may see');

$attempts = array(
	'disable a hidden node, keeping its device' => array('action' => 'save', 'id' => $nodes['hidden'], 'host_id' => $devices['hidden']),
	'enable a hidden node, keeping its device'  => array('action' => 'save', 'id' => $nodes['hidden'], 'host_id' => $devices['hidden'], 'enabled' => 'on'),
	'move its own node to a hidden device'      => array('action' => 'save', 'id' => $nodes['open'], 'host_id' => $unregistered, 'enabled' => 'on'),
	'register a hidden device as a node'        => array('action' => 'save', 'id' => 0, 'host_id' => $unregistered, 'enabled' => 'on'),
	'delete a hidden node'                      => array('action' => 'delete', 'id' => $hiddenEmpty),
	'retire a hidden absent GPU'                => array('action' => 'retire', 'id' => $absentHidden),
	'confirm a move from a hidden node'         => array('action' => 'confirm_move', 'id' => $intoOpen),
	'reject a move from a hidden node'          => array('action' => 'reject_move', 'id' => $intoOpen),
	'confirm a move to a hidden node'           => array('action' => 'confirm_move', 'id' => $intoHidden),
	'reject a move to a hidden node'            => array('action' => 'reject_move', 'id' => $intoHidden),
	'create a device without device management' => array('action' => 'create_device', 'description' => 'Created', 'hostname' => 'created.invalid'),
);

foreach ($attempts as $what => $post) {
	perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => $token) + $post);
	perm_expect($state() === $before, "scoped GPU Admin cannot $what: nothing is written");
}

list(, $export) = perm_http($base . 'gpu_nodes.php?action=export', $jar);
$document = json_decode($export, true);
$exportedGpus = is_array($document) ? array_column($document['gpus'], 'node_id') : array(-1);
perm_expect(is_array($document) && array_column($document['nodes'], 'id') === array($nodes['open']) && array_unique($exportedGpus) === array($nodes['open']) && strpos($export, '0000000000c3') === false && strpos($export, '0000000000d4') === false, 'scoped GPU Admin exports only the records of devices it may see');

// The controls: the same user can act on its own device, and may disable,
// but not re-enable, a node whose device was deleted.
perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => $token, 'action' => 'save', 'id' => $nodes['open'], 'host_id' => $devices['open']));
perm_expect(db_fetch_cell_prepared('SELECT admin_state FROM plugin_gpu_node WHERE id = ?', array($nodes['open'])) === 'disabled', 'scoped GPU Admin can disable the node of a device it may see');

db_execute_prepared("INSERT INTO plugin_gpu_node (host_id, admin_state) VALUES (999999, 'enabled')");
$orphan      = (int) db_fetch_insert_id();
$orphanState = function () use ($orphan) {
	return db_fetch_cell_prepared('SELECT admin_state FROM plugin_gpu_node WHERE id = ?', array($orphan));
};

perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => $token, 'action' => 'save', 'id' => $orphan, 'host_id' => $devices['open'], 'enabled' => 'on'));
perm_expect($orphanState() === 'enabled' && (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_gpu_node WHERE id = ?', array($orphan)) === 999999, 'a node whose device was deleted cannot be moved to another device');

perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => $token, 'action' => 'save', 'id' => $orphan, 'host_id' => 999999));
perm_expect($orphanState() === 'disabled', 'a node whose device was deleted can be disabled');

perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => $token, 'action' => 'save', 'id' => $orphan, 'host_id' => 999999, 'enabled' => 'on'));
perm_expect($orphanState() === 'disabled', 'a node whose device was deleted cannot be enabled again');

list(, $export) = perm_http($base . 'gpu_nodes.php?action=export', $jar);
$document = json_decode($export, true);
perm_expect(is_array($document) && !in_array($orphan, array_column($document['nodes'], 'id'), true), 'a node whose device was deleted is left out of the export');

// One with GPU records stays, as any node with records does; an empty one
// may be deleted by any GPU Admin, since no device permission applies.
db_execute_prepared("INSERT INTO plugin_gpu_node (host_id, admin_state) VALUES (999998, 'disabled')");
$orphanWithGpu = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, pci_bus_id, name, presence) VALUES ('nvidia', 'GPU-00000000-0000-4000-8000-0000000000f6', ?, '00000000:40:00.0', 'Orphaned GPU', 'absent')", array($orphanWithGpu));

perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => $token, 'action' => 'delete', 'id' => $orphanWithGpu));
perm_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_gpu_node WHERE id = ?', array($orphanWithGpu)) === 1, 'a node whose device was deleted is kept while GPU records belong to it');

perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => $token, 'action' => 'delete', 'id' => $orphan));
perm_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_gpu_node WHERE id = ?', array($orphan)) === 0, 'an empty node whose device was deleted is deleted by a scoped GPU Admin');

// With Cacti's device management, a device this user would not be allowed
// to see is removed again in the same request, and no node is made for it.
db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (3, ?)', array($users['scoped']));
// A new session loads the added realm with a token of its own.
$jar = perm_login('scoped');
list(, $form)   = perm_http($base . 'gpu_nodes.php?action=edit', $jar);
list(, $result) = perm_http($base . 'gpu_nodes.php', $jar, array('__csrf_magic' => perm_token($form), 'action' => 'create_device', 'description' => 'Invisible created', 'hostname' => 'invisible.invalid'));
perm_expect((int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE hostname = 'invisible.invalid'") === 0 && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_gpu_node WHERE host_id NOT IN (SELECT id FROM host) AND host_id NOT IN (999998)') === 0 && strpos($result, 'was not kept') !== false, 'a created device the creator may not see is removed again, with an error (' . (int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE hostname = 'invisible.invalid'") . ' devices kept, message ' . (($perm_at = strpos($result, '"message":')) !== false ? substr($result, $perm_at, 300) : 'none') . ')');
db_execute_prepared('DELETE FROM user_auth_realm WHERE realm_id = 3 AND user_id = ?', array($users['scoped']));

unlink($jar);

$jar = perm_login('viewer');
list(, $export) = perm_http($base . 'gpu_nodes.php?action=export', $jar);
perm_expect(strpos($export, $open) === false, 'a GPU View user cannot download the export');
unlink($jar);

exit($failures > 0 ? 1 : 0);
