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
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\GpuRecord;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\InventoryPage;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\NodeAccess;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\NodesPage;
use Somethingwithproof\Gpu\Presence;
use Somethingwithproof\Gpu\Vendor;

function page_node(int $id): Node {
	return new Node($id, 100 + $id, AdminState::Enabled, 'node' . $id, true, 0, 0);
}

it('names a pending destination only to a viewer allowed to see it', function () {
	$page   = new InventoryPage(new NodeRepository(false, new FixedClock(0)), new GpuRepository(false), static fn (int $host): bool => true, new FixedClock(1000));
	$gpu    = new GpuRecord(1, Vendor::Nvidia, 'GPU-00000000-0000-4000-8000-0000000000a1', 1, 0, '00000000:3B:00.0', '<b>x</b>', '580.95.05', 'nvidia-smi', '580.95.05', Presence::Present, 0, 9, 2, 900, 100, 940);
	$nodes  = [1 => page_node(1), 2 => page_node(2)];

	$seen   = $page->row($gpu, $nodes);
	$hidden = $page->row($gpu, [1 => page_node(1)]);

	expect($seen[0])->toBe('node1')
		->and($seen[2])->toBe('<b>x</b>')
		->and($seen[6])->toBe('nvidia-smi 580.95.05')
		->and($seen[7])->toBe('Present; possible replacement of GPU record 9; seen at node2, move not confirmed')
		->and($seen[8])->toBe('60 seconds ago')
		->and($hidden[7])->toBe('Present; possible replacement of GPU record 9; seen at another node, move not confirmed');
});

it('keeps a deleted device selected when a node is edited, instead of the first device listed', function () {
	$GLOBALS['__gpu_db_rows'] = ['FROM host AS h LEFT JOIN' => [['id' => '5', 'description' => 'other device']]];

	$page = new NodesPage(new NodeRepository(false, new FixedClock(0)), new GpuRepository(false), new NodeAccess(fn (int $id): bool => true));
	$node = new Node(1, 101, AdminState::Enabled, '', false, 0, 0);

	ob_start();
	$page->renderEdit($node);
	$html = (string) ob_get_clean();

	$GLOBALS['__gpu_db_rows'] = [];

	expect($html)->toContain('<option value="101" selected>Deleted device (ID 101)</option>')
		->and($html)->toContain('<option value="5">other device</option>');
});

it('offers Delete for a node whose device was deleted, in the list and on the edit page', function () {
	$GLOBALS['__gpu_db_rows'] = ['FROM plugin_gpu_node' => [
		['id' => 4, 'host_id' => 101, 'admin_state' => 'enabled', 'description' => null, 'device_id' => null, 'created_at' => 1, 'updated_at' => 1],
		['id' => 5, 'host_id' => 5, 'admin_state' => 'enabled', 'description' => 'hidden device', 'device_id' => 5, 'created_at' => 1, 'updated_at' => 1],
	]];

	$page = new NodesPage(new NodeRepository(false, new FixedClock(0)), new GpuRepository(false), new NodeAccess(fn (int $id): bool => false));

	ob_start();
	$page->renderList();
	$list = (string) ob_get_clean();

	ob_start();
	$page->renderEdit(new Node(4, 101, AdminState::Enabled, '', false, 0, 0));
	$edit = (string) ob_get_clean();

	$GLOBALS['__gpu_db_rows'] = [];

	expect($list)->toContain('name="id" value="4"')
		->and($list)->toContain('value="Delete"')
		->and($list)->not->toContain('name="id" value="5"')
		->and($edit)->toContain('name="action" value="delete"');
});
