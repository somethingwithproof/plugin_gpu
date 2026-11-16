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
use Somethingwithproof\Gpu\CollectionRepository;
use Somethingwithproof\Gpu\CollectionStatus;
use Somethingwithproof\Gpu\ExecutionState;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\FreshnessState;
use Somethingwithproof\Gpu\GpuRecord;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\HealthPage;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\ObservationRepository;
use Somethingwithproof\Gpu\Presence;
use Somethingwithproof\Gpu\ThrottleMask;
use Somethingwithproof\Gpu\Vendor;

const HEALTH_NOW = 100000;

function health_page(?bool $maintenance = false): HealthPage {
	return new HealthPage(
		new NodeRepository(false, new FixedClock(0)),
		new GpuRepository(false),
		new CollectionRepository(false),
		new ObservationRepository(false),
		static fn (int $host): bool => true,
		static fn (int $host): ?bool => $maintenance,
		new FixedClock(HEALTH_NOW),
		300,
		600,
	);
}

function health_gpu(int $id, Presence $presence = Presence::Present, int $replaces = 0): GpuRecord {
	return new GpuRecord($id, Vendor::Nvidia, sprintf('GPU-00000000-0000-4000-8000-%012x', $id), 1, $id - 1, '00000000:18:00.0', 'NVIDIA H100', '580.95.05', 'nvidia-smi', '580.95.05', $presence, 0, $replaces, 0, 0, 1, HEALTH_NOW);
}

function health_status(ExecutionState $state, string $detail = '', int $lastOk = 0): CollectionStatus {
	return new CollectionStatus(1, $state, $detail, HEALTH_NOW - 40, HEALTH_NOW - 30, HEALTH_NOW - 30, 2, 0, 0, 0, $lastOk);
}

it('names freshness from the sample time and the expected interval, never the page time', function (int $age, FreshnessState $want) {
	expect(FreshnessState::of($age < 0 ? 0 : HEALTH_NOW - $age, HEALTH_NOW, 300, 600))->toBe($want);
})->with([
	'never sampled'            => [-1, FreshnessState::Never],
	'just taken'               => [0, FreshnessState::Current],
	'one and a half intervals' => [450, FreshnessState::Current],
	'later than that'          => [451, FreshnessState::Delayed],
	'at the staleness limit'   => [600, FreshnessState::Delayed],
	'past it'                  => [601, FreshnessState::Stale],
]);

it('never calls a sample current once it is too old to graph', function () {
	expect(FreshnessState::of(HEALTH_NOW - 200, HEALTH_NOW, 300, 120))->toBe(FreshnessState::Stale);
});

it('calls a sample ahead once it is stamped further ahead of Cacti than skew explains', function () {
	expect(FreshnessState::of(HEALTH_NOW + 120, HEALTH_NOW, 300, 600))->toBe(FreshnessState::Current)
		->and(FreshnessState::of(HEALTH_NOW + 121, HEALTH_NOW, 300, 600))->toBe(FreshnessState::Ahead)
		->and(FreshnessState::Ahead->label())->toBe("Ahead of the Cacti server's clock");
});

it('counts the runs that skipped a node another run held', function () {
	$held = new CollectionStatus(1, ExecutionState::Ok, '', HEALTH_NOW - 40, HEALTH_NOW - 30, HEALTH_NOW - 30, 2, 0, 0, 3, HEALTH_NOW - 30);

	expect(health_page()->collection($held))->toBe('Completed: Collected, 2 GPUs (30 seconds ago); 3 later runs skipped while another run held the node')
		->and(health_page()->collection(health_status(ExecutionState::Ok)))->not->toContain('skipped');
});

it('reports each collection outcome as completed, tool error, permission denied, timed out, or unknown', function (ExecutionState $state, string $outcome) {
	$text = health_page()->collection(health_status($state));

	expect($text)->toStartWith($outcome)
		->and($text)->toContain($state->label());
})->with([
	[ExecutionState::Ok, 'Completed'],
	[ExecutionState::Empty, 'Completed'],
	[ExecutionState::DriverNotLoaded, 'Tool error'],
	[ExecutionState::InvalidOutput, 'Tool error'],
	[ExecutionState::Unreachable, 'Tool error'],
	[ExecutionState::PermissionDenied, 'Permission denied'],
	[ExecutionState::TimedOut, 'Timed out'],
	[ExecutionState::Skipped, 'Unknown'],
]);

it('says a node never collected is unknown, and when a failing node last completed', function () {
	$page = health_page();

	expect($page->collection(null))->toBe('Unknown: not collected yet')
		->and($page->collection(health_status(ExecutionState::TimedOut, 'no answer within 5 s', HEALTH_NOW - 3600)))->toBe('Timed out: no answer within 5 s (30 seconds ago); last completed 60 minutes ago');
});

it('bounds the collection detail and strips control characters', function () {
	expect(HealthPage::detail("line one\nline\x1b[31m two\x00"))->toBe('line one line [31m two')
		->and(mb_strlen(HealthPage::detail(str_repeat('é', 500))))->toBe(HealthPage::DETAIL_LIMIT)
		->and(HealthPage::detail(str_repeat('x', 500)))->toEndWith('...');
});

it('counts present, absent, and possibly replaced GPUs, and names backend, tool, and driver per node', function () {
	$node    = new Node(1, 101, AdminState::Enabled, 'node1', true, 0, 0);
	$gpus    = [health_gpu(1), health_gpu(2, Presence::Absent), health_gpu(3, Presence::Present, 2)];
	$samples = [1 => ['observed_at' => HEALTH_NOW - 30, 'backend' => 'nvidia-smi', 'thr_mask' => '1', 'ecc_mode' => 'enabled']];

	expect(health_page(true)->nodeRow($node, health_status(ExecutionState::Ok), $gpus, $samples))->toBe([
		'node1', 'Enabled', 'Current (30 seconds ago)', 'Completed: Collected, 2 GPUs (30 seconds ago)', 'In maintenance', 'nvidia-smi', 'nvidia-smi 580.95.05', '580.95.05', '2 present, 1 absent, 1 possible replacements',
	])->and(health_page(null)->nodeRow($node, null, [], [])[4])->toBe('Maint not installed')
		->and(health_page(null)->nodeRow($node, null, [], [])[2])->toBe('Never observed');
});

it('shows why ECC is unknown, and the raw clock event mask with bits that have no indicator', function () {
	$node = new Node(1, 101, AdminState::Enabled, 'node1', true, 0, 0);
	$page = health_page();

	$off     = $page->gpuRow(health_gpu(1), $node, ['observed_at' => HEALTH_NOW - 700, 'thr_mask' => (string) (0x40 | 0x400), 'ecc_mode' => 'unavailable']);
	$on      = $page->gpuRow(health_gpu(1), $node, ['observed_at' => HEALTH_NOW - 10, 'thr_mask' => null, 'ecc_mode' => 'enabled']);
	$unseen  = $page->gpuRow(health_gpu(2, Presence::Absent), $node, null);

	expect($off[5])->toBe('Stale (11 minutes ago)')
		->and($off[6])->toBe('This GPU does not report ECC')
		->and($off[7])->toBe('0x0000000000000440; bits without an indicator: 0x400')
		->and($on[6])->toBe('Enabled')
		->and($on[7])->toBe('Unknown')
		->and($unseen[4])->toBe('Absent')
		->and($unseen[5])->toBe('Never observed');
});

it('decodes each clock event bit alone and in combination, and keeps bits without an indicator in the raw mask', function () {
	foreach (\Somethingwithproof\Gpu\MetricModel::BITS as $name => $bit) {
		$values = ThrottleMask::indicators($bit);

		expect($values[$name])->toBe(1.0)
			->and(array_sum($values))->toBe(1.0);
	}

	$all = ThrottleMask::indicators(array_sum(\Somethingwithproof\Gpu\MetricModel::BITS));

	expect(array_unique($all))->toBe([array_key_first($all) => 1.0])
		->and(ThrottleMask::indicators(0x1 | 0x2 | 0x10 | 0x100 | 0x200 | 0x400))->toBe(array_fill_keys(array_keys(\Somethingwithproof\Gpu\MetricModel::BITS), 0.0))
		->and(ThrottleMask::describe(0x7ff))->toBe('0x00000000000007FF; bits without an indicator: 0x713')
		->and(ThrottleMask::describe(0))->toBe('0x0000000000000000');
});
