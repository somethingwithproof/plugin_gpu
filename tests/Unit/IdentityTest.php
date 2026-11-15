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
 * Identity rules (REQ-ID-001 to REQ-ID-005) driven by the sequences in
 * tests/fixtures/identity/. Each sequence is replayed against an in-memory
 * store that behaves as GpuRepository does: ids in insert order, updates by
 * id, and an operator's move confirmation as confirmMove() writes it.
 */

use Somethingwithproof\Gpu\AdminState;
use Somethingwithproof\Gpu\GpuRecord;
use Somethingwithproof\Gpu\GpuReport;
use Somethingwithproof\Gpu\GpuSnapshot;
use Somethingwithproof\Gpu\IdentityReconciler;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\Presence;
use Somethingwithproof\Gpu\Vendor;

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function identity_fixtures(): array {
	$fixtures = [];

	foreach (glob(dirname(__DIR__) . '/fixtures/identity/*.json') as $file) {
		$fixtures[basename($file, '.json')] = [json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)];
	}

	return $fixtures;
}

/**
 * @param array<string, mixed> $fixture
 * @return array{records: array<int, GpuRecord>, refused: int}
 */
function identity_replay(array $fixture): array {
	$reconciler = new IdentityReconciler();
	$records    = [];
	$refused    = 0;
	$nextId     = 1;

	foreach ($fixture['steps'] as $step) {
		if (isset($step['confirm_move'])) {
			foreach ($records as $id => $record) {
				if ($record->uuid === $step['confirm_move'] && $record->pendingNodeId > 0) {
					$records[$id] = new GpuRecord($record->id, $record->vendor, $record->uuid, $record->pendingNodeId, $record->index, $record->pciBusId, $record->name, $record->driverVersion, $record->tool, $record->toolVersion, Presence::Present, 0, $record->replacesGpuId, 0, 0, $record->firstSeen, $record->lastSeen);
				}
			}

			continue;
		}

		// A failed collection produces no snapshot at all.
		if (!empty($step['failed'])) {
			continue;
		}

		try {
			$snapshot = GpuSnapshot::create($step['tool'], $step['tool_version'], array_map(
				static fn (array $gpu): GpuReport => GpuReport::create(Vendor::Nvidia, $gpu['uuid'], $gpu['index'], $gpu['bus'], $gpu['name'], $gpu['driver']),
				$step['gpus']
			));
		} catch (InvalidArgumentException) {
			$refused++;

			continue;
		}

		$state = $fixture['nodes'][(string) $step['node']] === 'enabled' ? AdminState::Enabled : AdminState::Disabled;
		$node  = new Node($step['node'], 100 + $step['node'], $state, 'node' . $step['node'], true, 0, 0);
		$known = array_values(array_filter($records, static fn (GpuRecord $r): bool => $r->nodeId === $node->id || $snapshot->has($r->key())));

		$changes = $reconciler->reconcile($node, $known, $snapshot, $fixture['absent_after'], $step['at']);

		foreach ($changes->created as $record) {
			$id           = $nextId++;
			$records[$id] = new GpuRecord($id, $record->vendor, $record->uuid, $record->nodeId, $record->index, $record->pciBusId, $record->name, $record->driverVersion, $record->tool, $record->toolVersion, $record->presence, $record->missingCount, $record->replacesGpuId, $record->pendingNodeId, $record->pendingSince, $record->firstSeen, $record->lastSeen);
		}

		foreach ($changes->updated as $record) {
			expect($records)->toHaveKey($record->id);

			$records[$record->id] = $record;
		}
	}

	return ['records' => $records, 'refused' => $refused];
}

it('produces the documented records', function (array $fixture) {
	$result  = identity_replay($fixture);
	$records = $result['records'];
	$uuids   = [];

	foreach ($records as $record) {
		$uuids[$record->id] = $record->uuid;
	}

	expect(count($records))->toBe(count($fixture['expect']))
		->and($result['refused'])->toBe($fixture['refused'] ?? 0);

	foreach ($fixture['expect'] as $expected) {
		$matches = array_values(array_filter($records, static fn (GpuRecord $r): bool => $r->uuid === $expected['uuid']));

		expect($matches)->toHaveCount(1, $expected['uuid']);

		$record = $matches[0];
		$actual = [
			'uuid'          => $record->uuid,
			'node'          => $record->nodeId,
			'index'         => $record->index,
			'bus'           => $record->pciBusId,
			'driver'        => $record->driverVersion,
			'tool'          => $record->tool,
			'tool_version'  => $record->toolVersion,
			'presence'      => $record->presence->value,
			'missing'       => $record->missingCount,
			'replaces'      => $record->replacesGpuId > 0 ? $uuids[$record->replacesGpuId] : null,
			'pending_node'  => $record->pendingNodeId,
			'pending_since' => $record->pendingSince,
			'first_seen'    => $record->firstSeen,
			'last_seen'     => $record->lastSeen,
		];

		$actual = array_intersect_key($actual, $expected);

		ksort($actual);
		ksort($expected);

		expect($actual)->toBe($expected);
	}
})->with(identity_fixtures());

it('never produces two records for one UUID', function (array $fixture) {
	$keys = array_map(static fn (GpuRecord $r): string => $r->key(), identity_replay($fixture)['records']);

	expect($keys)->toBe(array_unique($keys));
})->with(identity_fixtures());

it('covers every identity scenario the plan names', function () {
	expect(array_keys(identity_fixtures()))->toContain('reboot-reorder', 'card-swap', 'slot-move', 'driver-change', 'lost-gpu', 'node-move-pending', 'node-move-confirmed', 'unavailable-values', 'refused-output');
});

it('uses only synthetic UUIDs in fixtures', function (array $fixture) {
	// REQ-TEST-005: no UUID of real hardware. Every UUID in a fixture uses
	// the reserved all-zero prefix.
	preg_match_all('/(?:GPU|MIG)-[0-9a-fA-F-]{8,}/', (string) json_encode($fixture), $found);

	expect($found[0])->not->toBeEmpty();

	foreach ($found[0] as $uuid) {
		expect($uuid)->toMatch('/^(GPU|MIG)-00000000-0000-4000-8000-0000000000[0-9a-f]{2}$/');
	}

	expect($fixture['source'])->toStartWith('synthetic');
})->with(identity_fixtures());

it('changes nothing for a disabled node or an empty snapshot', function () {
	$reconciler = new IdentityReconciler();
	$snapshot   = GpuSnapshot::create('nvidia-smi', '580.95.05', [GpuReport::create(Vendor::Nvidia, 'GPU-00000000-0000-4000-8000-0000000000a1', 0, '00000000:3B:00.0', 'GPU', '580.95.05')]);
	$known      = [GpuRecord::discovered($snapshot->reports[0], $snapshot, 1, 0, 10)];

	expect($reconciler->reconcile(new Node(1, 101, AdminState::Disabled, 'n', true, 0, 0), $known, $snapshot, 1, 20)->isEmpty())->toBeTrue()
		->and($reconciler->reconcile(new Node(1, 101, AdminState::Enabled, 'n', true, 0, 0), $known, GpuSnapshot::create('nvidia-smi', '580.95.05', []), 1, 20)->isEmpty())->toBeTrue();
});
