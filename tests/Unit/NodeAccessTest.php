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

use Somethingwithproof\Gpu\AdminState;
use Somethingwithproof\Gpu\GpuRecord;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\NodeAccess;

function access_node(int $id, int $host, bool $exists = true): Node {
	return new Node($id, $host, AdminState::Enabled, 'node' . $id, $exists, 0, 0);
}

function access_gpu(int $node, int $pending = 0): GpuRecord {
	return GpuRecord::fromRow(['id' => 1, 'vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-000000000001', 'node_id' => $node, 'pending_node_id' => $pending]);
}

// The user may see device 10 only.
function access(): NodeAccess {
	return new NodeAccess(fn (int $host): bool => $host === 10);
}

it('lets a user manage only nodes on devices it may see', function () {
	expect(access()->canManage(access_node(1, 10)))->toBeTrue()
		->and(access()->canManage(access_node(2, 20)))->toBeFalse()
		->and(access()->canManage(null))->toBeFalse()
		->and(access()->deviceAllowed(0))->toBeFalse();
});

it('shows a node whose device was deleted, but never lets it be managed', function () {
	$orphan = access_node(3, 10, false);

	expect(access()->canSee($orphan))->toBeTrue()
		->and(access()->canManage($orphan))->toBeFalse()
		->and(access()->canSee(access_node(2, 20)))->toBeFalse();
});

it('lets a node be deleted on a device the user may see, or whose device was deleted', function () {
	expect(access()->canRemove(access_node(1, 10)))->toBeTrue()
		->and(access()->canRemove(access_node(3, 20, false)))->toBeTrue()
		->and(access()->canRemove(access_node(2, 20)))->toBeFalse()
		->and(access()->canRemove(null))->toBeFalse();
});

it('requires both nodes of a GPU move', function () {
	$nodes = [1 => access_node(1, 10), 2 => access_node(2, 20), 4 => access_node(4, 10)];

	expect(access()->canManageGpu(access_gpu(1), $nodes))->toBeTrue()
		->and(access()->canManageGpu(access_gpu(1, 4), $nodes))->toBeTrue()
		->and(access()->canManageGpu(access_gpu(1, 2), $nodes))->toBeFalse()
		->and(access()->canManageGpu(access_gpu(2, 1), $nodes))->toBeFalse()
		->and(access()->canManageGpu(access_gpu(1, 9), $nodes))->toBeFalse();
});

it('lists only GPUs recorded at nodes the user may manage', function () {
	$nodes = access()->visibleNodes([access_node(1, 10), access_node(2, 20), access_node(3, 30, false)]);
	$gpus  = access()->visibleGpus([access_gpu(1), access_gpu(2), access_gpu(3)], $nodes);

	expect(array_keys($nodes))->toBe([1, 3])
		->and(array_column(array_map(fn (GpuRecord $gpu): array => $gpu->toRow(), $gpus), 'node_id'))->toBe([1]);
});
