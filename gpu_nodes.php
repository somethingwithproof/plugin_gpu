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

gpu_require_post('gpu_nodes.php', array('save', 'delete', 'confirm_move', 'reject_move'));

$gpu_nodes  = gpu_nodes();
$gpu_gpus   = gpu_gpus();
$gpu_page   = new \Somethingwithproof\Gpu\NodesPage($gpu_nodes, $gpu_gpus);
$gpu_action = (string) get_nfilter_request_var('action');

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

		if (!$gpu_nodes->deviceExists($gpu_host)) {
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

		if ($gpu_nodes->save($gpu_id, $gpu_host, $gpu_state) > 0) {
			raise_message('gpu_saved', __('The GPU node was saved.', 'gpu'), MESSAGE_LEVEL_INFO);
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
		if ($gpu_gpus->confirmMove((int) get_filter_request_var('id'))) {
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
		$gpu_page->renderEdit($gpu_id > 0 ? $gpu_nodes->find($gpu_id) : null);
		bottom_footer();
		exit;
	default:
		top_header();
		$gpu_page->renderList();
		bottom_footer();
}
