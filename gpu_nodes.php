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
 * GPU Nodes (GPU Admin realm). This file must parse on PHP 8.0: Cacti may
 * include it on an unsupported runtime, and gpu_page_start() refuses there
 * before any plugin class loads.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/gpu/setup.php');

if (!gpu_page_start()) {
	exit;
}

gpu_require_post('gpu_nodes.php', array('save', 'delete', 'confirm_move', 'reject_move', 'retire', 'create_device'));

$gpu_nodes   = gpu_nodes();
$gpu_gpus    = gpu_gpus();
$gpu_clock   = new \Somethingwithproof\Gpu\SystemClock();
$gpu_reindex = new \Somethingwithproof\Gpu\ReindexRepository(false);
// Every node and GPU action is checked against Cacti's device permissions
// for the node's device (and both nodes' for a move) before any write.
$gpu_access  = new \Somethingwithproof\Gpu\NodeAccess(function (int $id): bool {
	return is_device_allowed($id);
});
$gpu_page    = new \Somethingwithproof\Gpu\NodesPage($gpu_nodes, $gpu_gpus, $gpu_access, new \Somethingwithproof\Gpu\CollectionRepository(false), $gpu_clock);
$gpu_action  = (string) get_nfilter_request_var('action');
// Creating a device needs Cacti's own device management permission, the
// realm of host.php, in addition to GPU Admin.
$gpu_can_create = is_realm_allowed(3);

/**
 * Refuse an action on a node or GPU the user may not act on, the same way
 * whether it does not exist or is on a device the user cannot see.
 *
 * @return never
 */
function gpu_nodes_refuse(): void {
	raise_message('gpu_denied', __('That GPU node or GPU does not exist, or is on a device you do not have access to.', 'gpu'), MESSAGE_LEVEL_ERROR);
	header('Location: gpu_nodes.php');
	exit;
}

/**
 * The GPU record and its node or nodes, when the user may act on them.
 */
function gpu_nodes_gpu(\Somethingwithproof\Gpu\NodeAccess $access, int $id, bool $move = true): \Somethingwithproof\Gpu\GpuRecord {
	$record = gpu_gpus()->find($id);
	$nodes  = array();

	if ($record !== null) {
		// A move needs both nodes; anything else only the GPU's own node.
		foreach ($move ? array($record->nodeId, $record->pendingNodeId) : array($record->nodeId) as $node_id) {
			$node = $node_id > 0 ? gpu_nodes()->find($node_id) : null;

			if ($node !== null) {
				$nodes[$node->id] = $node;
			}
		}
	}

	if ($record === null || !($move ? $access->canManageGpu($record, $nodes) : $access->canManage($nodes[$record->nodeId] ?? null))) {
		gpu_nodes_refuse();
	}

	return $record;
}

/**
 * Attach the GPU data query to a saved, enabled node's device.
 */
function gpu_nodes_attach(\Somethingwithproof\Gpu\Node $node): void {
	if ($node->enabled() && $node->deviceExists && !gpu_provisioner()->attach($node)) {
		raise_message('gpu_attach', __('The GPU data query could not be added to the device. Check that the plugin\'s templates are installed.', 'gpu'), MESSAGE_LEVEL_WARN);
	}
}

switch ($gpu_action) {
	case 'save':
		$gpu_id      = (int) get_filter_request_var('id');
		$gpu_host    = (int) get_filter_request_var('host_id');
		$gpu_enabled = get_nfilter_request_var('enabled') === 'on';
		$gpu_node    = $gpu_id > 0 ? $gpu_nodes->find($gpu_id) : null;
		$gpu_errors  = array();

		// An existing node needs access to its device. The one exemption: a
		// node whose device was deleted may be disabled, and nothing else.
		$gpu_disable_only = $gpu_node !== null && !$gpu_node->deviceExists;

		if ($gpu_id > 0 && !($gpu_access->canManage($gpu_node) || $gpu_disable_only)) {
			gpu_nodes_refuse();
		}

		if ($gpu_disable_only && ($gpu_host !== $gpu_node->hostId || $gpu_enabled)) {
			$gpu_errors['host_id'] = __('Device: the node\'s device was deleted, so the node can only be disabled.', 'gpu');
		} elseif (!$gpu_disable_only && (!$gpu_nodes->deviceExists($gpu_host) || !$gpu_access->deviceAllowed($gpu_host))) {
			$gpu_errors['host_id'] = __('Device: choose an existing Cacti device.', 'gpu');
		} elseif ($gpu_nodes->hostTaken($gpu_host, $gpu_id)) {
			$gpu_errors['host_id'] = __('Device: this device is already a GPU node.', 'gpu');
		}

		if ($gpu_errors !== array()) {
			top_header();
			$gpu_page->renderEdit($gpu_node, $gpu_host, $gpu_enabled, $gpu_errors);
			bottom_footer();
			exit;
		}

		$gpu_state = $gpu_enabled ? \Somethingwithproof\Gpu\AdminState::Enabled : \Somethingwithproof\Gpu\AdminState::Disabled;

		$gpu_saved = $gpu_nodes->save($gpu_id, $gpu_host, $gpu_state);

		if ($gpu_saved > 0) {
			raise_message('gpu_saved', __('The GPU node was saved.', 'gpu'), MESSAGE_LEVEL_INFO);

			$gpu_node = $gpu_nodes->find($gpu_saved);

			if ($gpu_node !== null && $gpu_access->canManage($gpu_node)) {
				gpu_nodes_attach($gpu_node);
			}
		} else {
			raise_message('gpu_saved', __('The GPU node could not be saved.', 'gpu'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'delete':
		$gpu_node = $gpu_nodes->find((int) get_filter_request_var('id'));

		// A node whose device was deleted may be deleted too, by any GPU
		// Admin; see NodeAccess.
		if ($gpu_node === null || !$gpu_access->canRemove($gpu_node)) {
			gpu_nodes_refuse();
		}

		if ($gpu_nodes->delete($gpu_node->id)) {
			raise_message('gpu_deleted', __('The GPU node was removed. Its Cacti device was left in place.', 'gpu'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('gpu_deleted', __('The GPU node was not removed because GPU records belong to it. Disable it instead; GPU records are never deleted.', 'gpu'), MESSAGE_LEVEL_WARN);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'confirm_move':
		$gpu_record = gpu_nodes_gpu($gpu_access, (int) get_filter_request_var('id'));

		// Applied only as authorized: if a collection changed the pending
		// node since it was read, nothing moves.
		if ($gpu_gpus->confirmMove($gpu_record->id, $gpu_record->nodeId, $gpu_record->pendingNodeId)) {
			// Both nodes' data query listings changed.
			$gpu_reindex->request($gpu_record->nodeId, 'GPU moved away', $gpu_clock->now());
			$gpu_reindex->request($gpu_record->pendingNodeId, 'GPU moved here', $gpu_clock->now());

			raise_message('gpu_move', __('The GPU now belongs to the node it was seen at, with its history.', 'gpu'), MESSAGE_LEVEL_INFO);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'reject_move':
		$gpu_record = gpu_nodes_gpu($gpu_access, (int) get_filter_request_var('id'));

		if ($gpu_gpus->rejectMove($gpu_record->id, $gpu_record->nodeId, $gpu_record->pendingNodeId)) {
			raise_message('gpu_move', __('The GPU stays with its recorded node.', 'gpu'), MESSAGE_LEVEL_INFO);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'retire':
		$gpu_record = gpu_nodes_gpu($gpu_access, (int) get_filter_request_var('id'), false);
		$gpu_user   = is_numeric($_SESSION['sess_user_id'] ?? null) ? (int) $_SESSION['sess_user_id'] : 0;

		if ($gpu_gpus->retire($gpu_record->id, $gpu_record->nodeId, $gpu_user, $gpu_clock->now())) {
			$gpu_reindex->request($gpu_record->nodeId, 'GPU retired', $gpu_clock->now());

			raise_message('gpu_retire', __('The GPU will leave the data query at the next reindex. Its graphs and RRD files are kept.', 'gpu'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('gpu_retire', __('Only an absent GPU can be removed from the data query.', 'gpu'), MESSAGE_LEVEL_WARN);
		}

		header('Location: gpu_nodes.php?action=edit&id=' . $gpu_record->nodeId);
		exit;
	case 'create_device':
		$gpu_description = trim((string) get_nfilter_request_var('description'));
		$gpu_hostname    = trim((string) get_nfilter_request_var('hostname'));
		$gpu_template    = (new \Somethingwithproof\Gpu\CactiObjectRepository(false))->idByHash('host_template', \Somethingwithproof\Gpu\TemplateXml::hash('02', \Somethingwithproof\Gpu\TemplateXml::DEVICE_TEMPLATE));

		if (!$gpu_can_create) {
			raise_message('gpu_device', __('Creating a device needs permission to manage Cacti devices.', 'gpu'), MESSAGE_LEVEL_ERROR);
		} elseif ($gpu_description === '' || strlen($gpu_description) > 150 || preg_match('/[\x00-\x1F\x7F]/', $gpu_description) === 1) {
			raise_message('gpu_device', __('Description: enter up to 150 printable characters.', 'gpu'), MESSAGE_LEVEL_ERROR);
		} elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9.:_-]{0,99}$/', $gpu_hostname) !== 1) {
			raise_message('gpu_device', __('Hostname: enter a host name or IP address.', 'gpu'), MESSAGE_LEVEL_ERROR);
		} elseif ($gpu_template === 0) {
			raise_message('gpu_device', __('The GPU Node device template is not installed.', 'gpu'), MESSAGE_LEVEL_ERROR);
		} else {
			$gpu_host = gpu_create_device($gpu_description, $gpu_hostname, $gpu_template);

			// The new device is checked like any other: under a restrictive
			// device policy the user may not see a device they just created.
			// Such a device is removed again through Cacti's API in the same
			// request, so the user does not leave behind a device they cannot
			// see or manage (owner decision for milestone 4).
			if ($gpu_host > 0 && !$gpu_access->deviceAllowed($gpu_host)) {
				if (gpu_remove_device($gpu_host)) {
					raise_message('gpu_device', __('The device was not kept: your account may not see devices it creates. Ask an administrator to create it.', 'gpu'), MESSAGE_LEVEL_ERROR);
				} else {
					raise_message('gpu_device', __('The device could not be removed after access was refused. Ask an administrator to remove it.', 'gpu'), MESSAGE_LEVEL_ERROR);
				}

				header('Location: gpu_nodes.php');
				exit;
			}

			$gpu_id   = $gpu_host > 0 && !$gpu_nodes->hostTaken($gpu_host, 0) ? $gpu_nodes->save(0, $gpu_host, \Somethingwithproof\Gpu\AdminState::Enabled) : 0;
			$gpu_node = $gpu_id > 0 ? $gpu_nodes->find($gpu_id) : null;

			if ($gpu_node !== null && $gpu_access->canManage($gpu_node)) {
				gpu_nodes_attach($gpu_node);

				raise_message('gpu_device', __('The device and its GPU node were created. Set the device\'s SNMP settings on its device page.', 'gpu'), MESSAGE_LEVEL_INFO);
			} else {
				raise_message('gpu_device', __('The device could not be created; see the Cacti log.', 'gpu'), MESSAGE_LEVEL_ERROR);
			}
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'export':
		$gpu_info = plugin_gpu_version();

		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="gpu-records-' . gmdate('Ymd-His') . '.json"');
		header('X-Content-Type-Options: nosniff');

		// Only nodes the user may manage, with their GPUs: a node whose
		// device was deleted can no longer be checked, so it is left out.
		$gpu_export = array_values(array_filter($gpu_nodes->all(), function ($node) use ($gpu_access) {
			return $gpu_access->canManage($node);
		}));
		$gpu_by_id  = array();

		foreach ($gpu_export as $gpu_export_node) {
			$gpu_by_id[$gpu_export_node->id] = $gpu_export_node;
		}

		print \Somethingwithproof\Gpu\InventoryExport::json($gpu_export, $gpu_access->visibleGpus($gpu_gpus->all(), $gpu_by_id), $gpu_info['version'], $gpu_clock->now());
		exit;
	case 'edit':
		$gpu_id   = (int) get_filter_request_var('id');
		$gpu_node = $gpu_id > 0 ? $gpu_nodes->find($gpu_id) : null;

		if ($gpu_id > 0 && !$gpu_access->canSee($gpu_node)) {
			gpu_nodes_refuse();
		}

		top_header();
		$gpu_page->renderEdit($gpu_node, 0, null, array(), $gpu_can_create);
		bottom_footer();
		exit;
	default:
		top_header();
		$gpu_page->renderList();
		bottom_footer();
}
