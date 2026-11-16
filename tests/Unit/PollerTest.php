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

use Somethingwithproof\Gpu\CactiObjectRepository;
use Somethingwithproof\Gpu\CacheRepository;
use Somethingwithproof\Gpu\Clock;
use Somethingwithproof\Gpu\CollectionRepository;
use Somethingwithproof\Gpu\Collector;
use Somethingwithproof\Gpu\ExtendOutput;
use Somethingwithproof\Gpu\ExtendReader;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\IdentityReconciler;
use Somethingwithproof\Gpu\Inventory;
use Somethingwithproof\Gpu\LockRepository;
use Somethingwithproof\Gpu\NodeDevice;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\NvidiaSmiParser;
use Somethingwithproof\Gpu\ObservationRepository;
use Somethingwithproof\Gpu\Poller;
use Somethingwithproof\Gpu\Provisioner;
use Somethingwithproof\Gpu\ReindexRepository;
use Somethingwithproof\Gpu\Settings;

/**
 * A clock that moves one second forward on every read, so a time budget
 * runs out after a known number of nodes whatever the machine's speed.
 */
final class SteppingClock implements Clock {
	public function __construct(private int $time) {
	}

	public function now(): int {
		return $this->time++;
	}
}

/**
 * The tests pass over every node on its disabled device, so no node is
 * read; a read fails the test.
 */
final class UnusedReader implements ExtendReader {
	public function read(NodeDevice $device, string $token): ExtendOutput {
		throw new LogicException('No node should be read');
	}
}

function budget_poller(): Poller {
	$clock = new FixedClock(1000);
	$gpus  = new GpuRepository(false);

	$collector = new Collector(new UnusedReader(), new NvidiaSmiParser(), new Inventory($gpus, new IdentityReconciler(), $clock), $gpus, new ObservationRepository(false), new CollectionRepository(false), new ReindexRepository(false), new LockRepository(false), $clock);

	return new Poller(new NodeRepository(false, $clock), new CollectionRepository(false), $collector, new Provisioner(new CactiObjectRepository(false), new CacheRepository(false), new ReindexRepository(false), $clock), new ReindexRepository(false), new SteppingClock(5000));
}

beforeEach(function () {
	$GLOBALS['__gpu_db_exec'] = [];
	$GLOBALS['__gpu_db_rows'] = [
		'FROM plugin_gpu_node' => array_map(fn (int $id) => ['id' => $id, 'host_id' => 100 + $id, 'admin_state' => 'enabled', 'description' => "node $id", 'device_id' => 100 + $id, 'created_at' => 1, 'updated_at' => 1], range(1, 5)),
		'FROM host WHERE id' => [['id' => 101, 'description' => 'd', 'hostname' => 'h', 'poller_id' => 1, 'status' => 3, 'disabled' => 'on']],
		'FROM plugin_gpu_collection' => [],
	];
});

it('stops when the time budget runs out and counts every node it did not reach', function () {
	// The clock reads 5000 at the start and one more on each node, so the
	// third node finds 3 seconds gone.
	$stats = budget_poller()->run(Settings::fromRow([]), 3);

	expect($stats['skipped'])->toBe(2)
		->and($stats['left'])->toBe(3)
		->and($stats['stopped_early'])->toBeTrue()
		->and($stats['nodes'])->toBe(0);
});

it('reports no early stop and nothing left when the budget covers every node', function () {
	$stats = budget_poller()->run(Settings::fromRow([]), 60);

	expect($stats['skipped'])->toBe(5)
		->and($stats['left'])->toBe(0)
		->and($stats['stopped_early'])->toBeFalse();
});
