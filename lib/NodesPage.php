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
 * The GPU Nodes page (GPU Admin): register Cacti devices as GPU nodes,
 * confirm or reject GPU moves, and export the records. Every action that
 * changes state is a POST form, and csrf-magic adds its token to each
 * (REQ-UI-004). Nothing here takes a command, path, or URL.
 */
final class NodesPage {
	public const FILE = 'gpu_nodes.php';

	public function __construct(
		private readonly NodeRepository $nodes,
		private readonly GpuRepository $gpus,
	) {
	}

	public function renderList(): void {
		$nodes  = $this->nodes->all();
		$counts = [];

		foreach ($this->gpus->all() as $gpu) {
			$counts[$gpu->nodeId][$gpu->presence->value] = ($counts[$gpu->nodeId][$gpu->presence->value] ?? 0) + 1;
		}

		html_start_box(__('GPU Nodes', 'gpu'), '100%', '', '3', 'center', self::FILE . '?action=edit');

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', [
			__('Device', 'gpu'), __('State', 'gpu'), __('GPUs Present', 'gpu'), __('GPUs Absent', 'gpu'), __('Actions', 'gpu'),
		])) . '</th></tr>';

		if ($nodes === []) {
			print '<tr><td colspan="5"><em>' . html_escape(__('No GPU nodes yet. Add a Cacti device that has GPUs.', 'gpu')) . '</em></td></tr>';
		}

		foreach ($nodes as $node) {
			$label = $node->deviceExists ? $node->description : __('Deleted device %d', $node->hostId, 'gpu');

			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit&id=' . $node->id) . '">' . html_escape($label) . '</a></td>'
				. '<td>' . html_escape($node->enabled() ? __('Enabled', 'gpu') : __('Disabled', 'gpu')) . '</td>'
				. '<td>' . ($counts[$node->id][Presence::Present->value] ?? 0) . '</td>'
				. '<td>' . ($counts[$node->id][Presence::Absent->value] ?? 0) . '</td>'
				. '<td>' . $this->actionForm($node->id, 'delete', __('Delete', 'gpu')) . '</td>'
				. '</tr>';
		}

		html_end_box();

		$this->renderPendingMoves($nodes);

		html_start_box(__('Export', 'gpu'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('Download every node and GPU record as JSON. Take an export before an uninstall that drops the plugin tables.', 'gpu'))
			. ' <a class="linkEditMain" href="' . html_escape(self::FILE . '?action=export') . '">' . html_escape(__('Export Records', 'gpu')) . '</a></td></tr>';
		html_end_box();
	}

	/**
	 * @param list<Node> $nodes
	 */
	private function renderPendingMoves(array $nodes): void {
		$moves = $this->gpus->pendingMoves();

		if ($moves === []) {
			return;
		}

		$names = [];

		foreach ($nodes as $node) {
			$names[$node->id] = $node->description;
		}

		html_start_box(__('GPU Moves Awaiting Confirmation', 'gpu'), '100%', '', '3', 'center', '');

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', [
			__('UUID', 'gpu'), __('Name', 'gpu'), __('Recorded At', 'gpu'), __('Seen At', 'gpu'), __('Actions', 'gpu'),
		])) . '</th></tr>';

		foreach ($moves as $gpu) {
			print '<tr class="tableRow">'
				. '<td>' . html_escape($gpu->uuid) . '</td>'
				. '<td>' . html_escape($gpu->name) . '</td>'
				. '<td>' . html_escape($names[$gpu->nodeId] ?? '-') . '</td>'
				. '<td>' . html_escape($names[$gpu->pendingNodeId] ?? '-') . '</td>'
				. '<td>' . $this->actionForm($gpu->id, 'confirm_move', __('Confirm Move', 'gpu')) . ' ' . $this->actionForm($gpu->id, 'reject_move', __('Reject', 'gpu')) . '</td>'
				. '</tr>';
		}

		html_end_box();
	}

	/**
	 * @param array<string, string> $errors field => message
	 */
	public function renderEdit(?Node $node, int $hostId = 0, ?bool $enabled = null, array $errors = []): void {
		$devices = $this->nodes->devicesFor($node->id ?? 0);
		$current = $hostId > 0 ? $hostId : ($node->hostId ?? 0);
		$enabled ??= $node?->enabled() ?? true;

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($node === null ? __('New GPU Node', 'gpu') : __('GPU Node: %s', html_escape($node->description), 'gpu'), '100%', '', '3', 'center', '');

		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}

		print '<tr><td><label for="host_id">' . html_escape(__('Device', 'gpu')) . '</label></td><td><select id="host_id" name="host_id">';

		foreach ($devices as $id => $description) {
			print '<option value="' . $id . '"' . ($id === $current ? ' selected' : '') . '>' . html_escape($description) . '</option>';
		}

		print '</select></td></tr>';
		print '<tr><td><label for="enabled">' . html_escape(__('Enabled', 'gpu')) . '</label></td><td><input type="checkbox" id="enabled" name="enabled" value="on"' . ($enabled ? ' checked' : '') . '></td></tr>';

		html_end_box();

		print '<input type="hidden" name="action" value="save">'
			. '<input type="hidden" name="id" value="' . ($node->id ?? 0) . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Save', 'gpu')) . '">'
			. '</form>';
	}

	private function actionForm(int $id, string $action, string $label): string {
		return '<form method="post" action="' . html_escape(self::FILE) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape($label) . '">'
			. '</form>';
	}
}
