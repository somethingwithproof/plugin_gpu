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
use Somethingwithproof\Gpu\NodeRepository;
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
