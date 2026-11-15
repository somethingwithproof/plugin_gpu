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
$gpu_page    = new \Somethingwithproof\Gpu\NodesPage($gpu_nodes, $gpu_gpus, function (int $id): bool {
	return is_device_allowed($id);
}, new \Somethingwithproof\Gpu\CollectionRepository(false), $gpu_clock);
$gpu_action  = (string) get_nfilter_request_var('action');
// Creating a device needs Cacti's own device management permission, the
// realm of host.php, in addition to GPU Admin.
$gpu_can_create = is_realm_allowed(3);

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

		if ($gpu_id > 0 && $gpu_node === null) {
			header('Location: gpu_nodes.php');
			exit;
		}

		// Keeping the recorded device is always allowed, even once it was
		// deleted, so a node can still be disabled; a change must name a
		// device that exists.
		$gpu_same_host = $gpu_node !== null && $gpu_host === $gpu_node->hostId;

		if (!$gpu_same_host && (!$gpu_nodes->deviceExists($gpu_host) || !is_device_allowed($gpu_host))) {
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

			if ($gpu_node !== null) {
				gpu_nodes_attach($gpu_node);
			}
		} else {
			raise_message('gpu_saved', __('The GPU node could not be saved.', 'gpu'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'delete':
		if ($gpu_nodes->delete((int) get_filter_request_var('id'))) {
			raise_message('gpu_deleted', __('The GPU node was removed. Its Cacti device was left in place.', 'gpu'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('gpu_deleted', __('The GPU node was not removed because GPU records belong to it. Disable it instead; GPU records are never deleted.', 'gpu'), MESSAGE_LEVEL_WARN);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'confirm_move':
		$gpu_record = $gpu_gpus->find((int) get_filter_request_var('id'));

		if ($gpu_record !== null && $gpu_gpus->confirmMove($gpu_record->id)) {
			// Both nodes' data query listings changed.
			$gpu_reindex->request($gpu_record->nodeId, 'GPU moved away', $gpu_clock->now());
			$gpu_reindex->request($gpu_record->pendingNodeId, 'GPU moved here', $gpu_clock->now());

			raise_message('gpu_move', __('The GPU now belongs to the node it was seen at, with its history.', 'gpu'), MESSAGE_LEVEL_INFO);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'reject_move':
		if ($gpu_gpus->rejectMove((int) get_filter_request_var('id'))) {
			raise_message('gpu_move', __('The GPU stays with its recorded node.', 'gpu'), MESSAGE_LEVEL_INFO);
		}

		header('Location: gpu_nodes.php');
		exit;
	case 'retire':
		$gpu_record = $gpu_gpus->find((int) get_filter_request_var('id'));
		$gpu_user   = isset($_SESSION[SESS_USER_ID]) ? (int) $_SESSION[SESS_USER_ID] : 0;

		if ($gpu_record !== null && $gpu_gpus->retire($gpu_record->id, $gpu_user, $gpu_clock->now())) {
			$gpu_reindex->request($gpu_record->nodeId, 'GPU retired', $gpu_clock->now());

			raise_message('gpu_retire', __('The GPU will leave the data query at the next reindex. Its graphs and RRD files are kept.', 'gpu'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('gpu_retire', __('Only an absent GPU can be removed from the data query.', 'gpu'), MESSAGE_LEVEL_WARN);
		}

		header('Location: gpu_nodes.php' . ($gpu_record !== null ? '?action=edit&id=' . $gpu_record->nodeId : ''));
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
			$gpu_id   = $gpu_host > 0 && !$gpu_nodes->hostTaken($gpu_host, 0) ? $gpu_nodes->save(0, $gpu_host, \Somethingwithproof\Gpu\AdminState::Enabled) : 0;
			$gpu_node = $gpu_id > 0 ? $gpu_nodes->find($gpu_id) : null;

			if ($gpu_node !== null) {
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

		print \Somethingwithproof\Gpu\InventoryExport::json($gpu_nodes->all(), $gpu_gpus->all(), $gpu_info['version'], (new \Somethingwithproof\Gpu\SystemClock())->now());
		exit;
	case 'edit':
		$gpu_id = (int) get_filter_request_var('id');

		top_header();
		$gpu_page->renderEdit($gpu_id > 0 ? $gpu_nodes->find($gpu_id) : null, 0, null, array(), $gpu_can_create);
		bottom_footer();
		exit;
	default:
		top_header();
		$gpu_page->renderList();
		bottom_footer();
}
