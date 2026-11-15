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
 * The GPU Nodes page (GPU Admin): map Cacti devices to GPU nodes, see how
 * each node's collection went, confirm or reject GPU moves, remove absent
 * GPUs from the data query, and export the records. Every action that
 * changes state is a POST form, and csrf-magic adds its token to each
 * (REQ-UI-004). Nothing here takes a command, path, or URL.
 *
 * Only nodes, GPUs, and devices the acting user may see are shown or
 * offered (NodeAccess, REQ-DEV-002).
 */
final class NodesPage {
	public const FILE = 'gpu_nodes.php';

	public function __construct(
		private readonly NodeRepository $nodes,
		private readonly GpuRepository $gpus,
		private readonly NodeAccess $access,
		private readonly ?CollectionRepository $collections = null,
		private readonly ?Clock $clock = null,
	) {
	}

	public function renderList(): void {
		$nodes    = $this->access->visibleNodes($this->nodes->all());
		$statuses = $this->collections?->all() ?? [];
		$counts   = [];

		foreach ($this->access->visibleGpus($this->gpus->all(), $nodes) as $gpu) {
			$counts[$gpu->nodeId][$gpu->presence->value] = ($counts[$gpu->nodeId][$gpu->presence->value] ?? 0) + 1;
		}

		html_start_box(__('GPU Nodes', 'gpu'), '100%', '', '3', 'center', self::FILE . '?action=edit');

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', [
			__('Device', 'gpu'), __('State', 'gpu'), __('Last Collection', 'gpu'), __('GPUs Present', 'gpu'), __('GPUs Absent', 'gpu'), __('Actions', 'gpu'),
		])) . '</th></tr>';

		if ($nodes === []) {
			print '<tr><td colspan="6"><em>' . html_escape(__('No GPU nodes yet. Add a Cacti device that has GPUs.', 'gpu')) . '</em></td></tr>';
		}

		foreach ($nodes as $node) {
			$label = $node->deviceExists ? $node->description : __('Deleted device %d', $node->hostId, 'gpu');

			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit&id=' . $node->id) . '">' . html_escape($label) . '</a></td>'
				. '<td>' . html_escape($node->enabled() ? __('Enabled', 'gpu') : __('Disabled', 'gpu')) . '</td>'
				. '<td>' . html_escape($this->collection($statuses[$node->id] ?? null)) . '</td>'
				. '<td>' . ($counts[$node->id][Presence::Present->value] ?? 0) . '</td>'
				. '<td>' . ($counts[$node->id][Presence::Absent->value] ?? 0) . '</td>'
				. '<td>' . ($this->access->canManage($node) ? $this->actionForm($node->id, 'delete', __('Delete', 'gpu')) : '') . '</td>'
				. '</tr>';
		}

		html_end_box();

		$this->renderPendingMoves($nodes);

		html_start_box(__('Export', 'gpu'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('Download the node and GPU records of the devices you may see as JSON. Take an export before an uninstall that drops the plugin tables.', 'gpu'))
			. ' <a class="linkEditMain" href="' . html_escape(self::FILE . '?action=export') . '">' . html_escape(__('Export Records', 'gpu')) . '</a></td></tr>';
		html_end_box();
	}

	/**
	 * How the node's latest collection went, as one line of text. The
	 * detail never contains tool output (CollectionError).
	 */
	public function collection(?CollectionStatus $status): string {
		if ($status === null || $status->state === null) {
			return __('Not collected yet', 'gpu');
		}

		$text = $status->state->label();

		if ($status->succeeded()) {
			$text = __('%s: %d GPUs', $text, $status->gpus, 'gpu');

			if ($status->newGpus > 0) {
				$text .= '; ' . __('%d new GPUs recorded', $status->newGpus, 'gpu');
			}
		} elseif ($status->detail !== '') {
			$text .= ': ' . $status->detail;
		}

		if ($this->clock !== null && $status->finishedAt > 0) {
			$text .= ' (' . __('%d s ago', max(0, $this->clock->now() - $status->finishedAt), 'gpu') . ')';
		}

		if ($status->skipped > 0) {
			$text .= '; ' . __('%d runs skipped while one was still running', $status->skipped, 'gpu');
		}

		return $text;
	}

	/**
	 * @param array<int, Node> $nodes the visible nodes, by id
	 */
	private function renderPendingMoves(array $nodes): void {
		$moves = array_values(array_filter($this->gpus->pendingMoves(), fn (GpuRecord $gpu): bool => $this->access->canManageGpu($gpu, $nodes)));

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
	public function renderEdit(?Node $node, int $hostId = 0, ?bool $enabled = null, array $errors = [], bool $canCreateDevice = false): void {
		$devices = array_filter($this->nodes->devicesFor($node->id ?? 0), fn (int $id): bool => $this->access->deviceAllowed($id), ARRAY_FILTER_USE_KEY);
		$current = $hostId > 0 ? $hostId : ($node->hostId ?? 0);
		$enabled ??= $node?->enabled() ?? true;

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($node === null ? __('New GPU Node', 'gpu') : __('GPU Node: %s', html_escape($node->description), 'gpu'), '100%', '', '3', 'center', '');

		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}

		print '<tr><td><label for="host_id">' . html_escape(__('Device', 'gpu')) . '</label></td><td><select id="host_id" name="host_id">';

		// A node whose device was deleted keeps showing that device, so saving
		// only the Enabled box never moves the node to whichever device the
		// browser would otherwise select first.
		if ($current > 0 && !isset($devices[$current])) {
			print '<option value="' . $current . '" selected>' . html_escape(__('Deleted device (ID %d)', $current, 'gpu')) . '</option>';
		}

		foreach ($devices as $id => $description) {
			print '<option value="' . $id . '"' . ($id === $current ? ' selected' : '') . '>' . html_escape($description) . '</option>';
		}

		print '</select></td></tr>';
		print '<tr><td><label for="enabled">' . html_escape(__('Enabled', 'gpu')) . '</label></td><td><input type="checkbox" id="enabled" name="enabled" value="on"' . ($enabled ? ' checked' : '') . '></td></tr>';
		print '<tr><td colspan="2">' . html_escape(__('Saving adds the GPU data query to the device, with Cacti\'s automatic reindex turned off: the plugin reindexes after every GPU change. Configure the device\'s SNMP agent first; see docs/node-setup.md.', 'gpu')) . '</td></tr>';

		html_end_box();

		print '<input type="hidden" name="action" value="save">'
			. '<input type="hidden" name="id" value="' . ($node->id ?? 0) . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Save', 'gpu')) . '">'
			. '</form>';

		if ($node !== null && $this->access->canManage($node)) {
			$this->renderRetire($node);
		} elseif ($canCreateDevice) {
			$this->renderCreateDevice();
		}
	}

	/**
	 * Absent GPUs of a node, each with the explicit action that removes it
	 * from the data query (REQ-INDEX-005).
	 */
	private function renderRetire(Node $node): void {
		$absent = array_values(array_filter($this->gpus->all(), static fn (GpuRecord $gpu): bool => $gpu->nodeId === $node->id && $gpu->presence === Presence::Absent));

		if ($absent === []) {
			return;
		}

		$retired = array_flip($this->gpus->retiredIds());

		html_start_box(__('Absent GPUs', 'gpu'), '100%', '', '3', 'center', '');

		print '<tr><td colspan="4">' . html_escape(__('An absent GPU stays an index of the data query, so its graphs and RRD files stay attached and show a gap. Removing it from the data query stops that: at the next reindex Cacti no longer lists it, its graphs stop updating and its data sources show as orphaned, and its RRD files and graphs are kept until you delete them. The GPU record stays, and the GPU returns to the data query if it is reported again.', 'gpu')) . '</td></tr>';
		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', [__('UUID', 'gpu'), __('Name', 'gpu'), __('Last Seen', 'gpu'), __('Actions', 'gpu')])) . '</th></tr>';

		foreach ($absent as $gpu) {
			print '<tr class="tableRow">'
				. '<td>' . html_escape($gpu->uuid) . '</td>'
				. '<td>' . html_escape($gpu->name) . '</td>'
				. '<td>' . ($gpu->lastSeen > 0 ? html_escape(date('Y-m-d H:i', $gpu->lastSeen)) : '-') . '</td>'
				. '<td>' . (isset($retired[$gpu->id]) ? html_escape(__('Removed from the data query', 'gpu')) : $this->actionForm($gpu->id, 'retire', __('Remove from Data Query', 'gpu'))) . '</td>'
				. '</tr>';
		}

		html_end_box();
	}

	/**
	 * Create a device for a node that has none (ADR 0010). The device takes
	 * the site's device defaults and the GPU Node device template; its SNMP
	 * settings are then set on Cacti's own device page.
	 */
	private function renderCreateDevice(): void {
		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box(__('Create a Device for a New Node', 'gpu'), '100%', '', '3', 'center', '');
		print '<tr><td colspan="2">' . html_escape(__('Only when the node is not a Cacti device yet. The device uses the site\'s device defaults and the GPU Node device template; set its SNMP settings on the device page afterwards.', 'gpu')) . '</td></tr>';
		print '<tr><td><label for="description">' . html_escape(__('Description', 'gpu')) . '</label></td><td><input type="text" id="description" name="description" maxlength="150" size="40"></td></tr>';
		print '<tr><td><label for="hostname">' . html_escape(__('Hostname', 'gpu')) . '</label></td><td><input type="text" id="hostname" name="hostname" maxlength="100" size="40"></td></tr>';
		html_end_box();
		print '<input type="hidden" name="action" value="create_device">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Create Device and Node', 'gpu')) . '">'
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
