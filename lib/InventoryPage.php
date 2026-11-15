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
 * The GPU Inventory page (GPU View). It shows the stored records only and
 * never contacts a node. A viewer sees a GPU only when Cacti lets them see
 * its node's device, and every tool-reported string is escaped where it is
 * printed (REQ-UI-002).
 */
final class InventoryPage {
	public const FILE = 'gpu_inventory.php';

	/**
	 * @param \Closure(int): bool $deviceAllowed Cacti's device permission check for the viewer
	 */
	public function __construct(
		private readonly NodeRepository $nodes,
		private readonly GpuRepository $gpus,
		private readonly \Closure $deviceAllowed,
		private readonly Clock $clock,
	) {
	}

	public function render(): void {
		$nodes = [];

		foreach ($this->nodes->all() as $node) {
			if (($this->deviceAllowed)($node->hostId)) {
				$nodes[$node->id] = $node;
			}
		}

		$headers = [__('Node', 'gpu'), __('Index', 'gpu'), __('Name', 'gpu'), __('UUID', 'gpu'), __('PCI Bus ID', 'gpu'), __('Driver', 'gpu'), __('Reported By', 'gpu'), __('State', 'gpu'), __('Last Seen', 'gpu')];

		html_start_box(__('GPU Inventory', 'gpu'), '100%', '', '3', 'center', '');

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', $headers)) . '</th></tr>';

		$shown = 0;

		foreach ($this->gpus->all() as $gpu) {
			if (!isset($nodes[$gpu->nodeId])) {
				continue;
			}

			print '<tr class="tableRow"><td>' . implode('</td><td>', array_map('html_escape', $this->row($gpu, $nodes))) . '</td></tr>';
			$shown++;
		}

		if ($shown === 0) {
			print '<tr><td colspan="' . count($headers) . '"><em>' . html_escape(__('No GPUs recorded yet. GPUs appear here once collection, which arrives in a later release, reports them.', 'gpu')) . '</em></td></tr>';
		}

		html_end_box();
	}

	/**
	 * The cells of one GPU's row, as plain text.
	 *
	 * @param array<int, Node> $nodes the nodes the viewer may see, by id
	 * @return list<string>
	 */
	public function row(GpuRecord $gpu, array $nodes): array {
		$state = [$gpu->presence === Presence::Present ? __('Present', 'gpu') : __('Absent', 'gpu')];

		if ($gpu->possibleReplacement()) {
			$state[] = __('possible replacement of GPU record %d', $gpu->replacesGpuId, 'gpu');
		}

		if ($gpu->movePending()) {
			// The destination is named only to a viewer who may see it.
			$state[] = isset($nodes[$gpu->pendingNodeId]) ? __('seen at %s, move not confirmed', $nodes[$gpu->pendingNodeId]->description, 'gpu') : __('seen at another node, move not confirmed', 'gpu');
		}

		return [
			$nodes[$gpu->nodeId]->description,
			$gpu->index === null ? '-' : (string) $gpu->index,
			$gpu->name,
			$gpu->uuid,
			$gpu->pciBusId,
			$gpu->driverVersion,
			trim($gpu->tool . ' ' . $gpu->toolVersion),
			implode('; ', $state),
			$gpu->lastSeen > 0 ? $this->age($gpu->lastSeen) : __('Never', 'gpu'),
		];
	}

	private function age(int $time): string {
		$seconds = max(0, $this->clock->now() - $time);

		return match (true) {
			$seconds < 120    => __('%d seconds ago', $seconds, 'gpu'),
			$seconds < 7200   => __('%d minutes ago', intdiv($seconds, 60), 'gpu'),
			$seconds < 172800 => __('%d hours ago', intdiv($seconds, 3600), 'gpu'),
			default           => __('%d days ago', intdiv($seconds, 86400), 'gpu'),
		};
	}
}
