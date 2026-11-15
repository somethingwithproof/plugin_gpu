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
 * Which GPU nodes and GPU records the acting user may see and act on, from
 * Cacti's device permissions (REQ-DEV-002). A GPU Admin acts only on nodes
 * whose device the user may see. A move needs both nodes.
 *
 * One exemption: a node whose device was deleted is visible to every GPU
 * Admin, and may only be disabled or deleted, so a stale node can be stopped
 * or cleared away (owner decision for milestone 4). No device permission can
 * be checked for it, so nothing else is allowed on it, and it and its GPU
 * records are left out of the export, which carries only what the user may
 * manage.
 */
final class NodeAccess {
	/**
	 * @param \Closure(int): bool $deviceAllowed Cacti's is_device_allowed() for the acting user
	 */
	public function __construct(private readonly \Closure $deviceAllowed) {
	}

	public function deviceAllowed(int $hostId): bool {
		return $hostId > 0 && ($this->deviceAllowed)($hostId);
	}

	public function canManage(?Node $node): bool {
		return $node !== null && $node->deviceExists && $this->deviceAllowed($node->hostId);
	}

	public function canSee(?Node $node): bool {
		return $node !== null && (!$node->deviceExists || $this->canManage($node));
	}

	/**
	 * Whether the user may delete the node: a node they may manage, or one
	 * whose device was deleted.
	 */
	public function canRemove(?Node $node): bool {
		return $node !== null && (!$node->deviceExists || $this->canManage($node));
	}

	/**
	 * @param array<int, Node> $nodes by id
	 */
	public function canManageGpu(GpuRecord $gpu, array $nodes): bool {
		return $this->canManage($nodes[$gpu->nodeId] ?? null)
			&& ($gpu->pendingNodeId === 0 || $this->canManage($nodes[$gpu->pendingNodeId] ?? null));
	}

	/**
	 * @param list<Node> $nodes
	 * @return array<int, Node> the nodes the user may see, by id
	 */
	public function visibleNodes(array $nodes): array {
		$visible = [];

		foreach ($nodes as $node) {
			if ($this->canSee($node)) {
				$visible[$node->id] = $node;
			}
		}

		return $visible;
	}

	/**
	 * @param list<GpuRecord> $gpus
	 * @param array<int, Node> $nodes by id
	 * @return list<GpuRecord> GPUs recorded at a node the user may manage
	 */
	public function visibleGpus(array $gpus, array $nodes): array {
		return array_values(array_filter($gpus, fn (GpuRecord $gpu): bool => $this->canManage($nodes[$gpu->nodeId] ?? null)));
	}
}
