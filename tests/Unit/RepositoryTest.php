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
use Somethingwithproof\Gpu\GpuReport;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\GpuSnapshot;
use Somethingwithproof\Gpu\IdentityReconciler;
use Somethingwithproof\Gpu\Inventory;
use Somethingwithproof\Gpu\InventoryExport;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\NodeRepository;
use Somethingwithproof\Gpu\Presence;
use Somethingwithproof\Gpu\Vendor;

beforeEach(function () {
	$GLOBALS['__gpu_db_exec']       = [];
	$GLOBALS['__gpu_db_rows']       = [];
	$GLOBALS['__gpu_log']           = [];
	$GLOBALS['__gpu_affected_rows'] = 1;
});

function repo_snapshot(string ...$uuids): GpuSnapshot {
	$reports = [];

	foreach (array_values($uuids) as $i => $uuid) {
		$reports[] = GpuReport::create(Vendor::Nvidia, $uuid, $i, sprintf('00000000:%02X:00.0', 0x3B + $i), 'NVIDIA Test GPU', '580.95.05');
	}

	return GpuSnapshot::create('nvidia-smi', '580.95.05', $reports);
}

function repo_node(int $id = 1, AdminState $state = AdminState::Enabled): Node {
	return new Node($id, 100 + $id, $state, 'node' . $id, true, 0, 0);
}

it('inserts new records with every column and updates others without touching the key', function () {
	$snapshot = repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1');
	$created  = GpuRecord::discovered($snapshot->reports[0], $snapshot, 1, 0, 50);
	$updated  = new GpuRecord(7, Vendor::Nvidia, 'GPU-00000000-0000-4000-8000-0000000000b2', 1, null, '00000000:3C:00.0', 'n', 'd', 't', 'v', Presence::Absent, 3, 0, 0, 0, 10, 20);
	$read     = new GpuRecord(7, Vendor::Nvidia, $updated->uuid, 1, null, '00000000:3C:00.0', 'n', 'd', 't', 'v', Presence::Present, 2, 0, 4, 15, 10, 20);

	expect((new GpuRepository(false))->apply(new \Somethingwithproof\Gpu\Reconciliation([$created], [$updated], [7 => $read])))->toBeTrue();

	[$insert, $update] = $GLOBALS['__gpu_db_exec'];

	expect($insert['sql'])->toStartWith('INSERT INTO plugin_gpu_gpu (vendor, uuid, node_id, gpu_index,')
		->and($insert['params'])->toBe(array_values($created->toRow()))
		->and($update['sql'])->toStartWith('UPDATE plugin_gpu_gpu SET node_id = ?')
		->and($update['sql'])->not->toContain('uuid')
		->and($update['sql'])->not->toContain('vendor')
		->and($update['sql'])->toEndWith('WHERE id = ? AND node_id = ? AND pending_node_id = ?')
		->and($update['params'][1])->toBeNull()
		->and(array_slice($update['params'], -3))->toBe([7, 1, 4]);
});

it('leaves a record an operator changed after the read, and refuses an update with no read state', function () {
	$updated = new GpuRecord(7, Vendor::Nvidia, 'GPU-00000000-0000-4000-8000-0000000000b2', 1, null, '00000000:3C:00.0', 'n', 'd', 't', 'v', Presence::Present, 1, 0, 0, 0, 10, 20);

	// The guarded update matched no row: a confirm or reject got there first.
	$GLOBALS['__gpu_affected_rows'] = 0;

	expect((new GpuRepository(false))->apply(new \Somethingwithproof\Gpu\Reconciliation([], [$updated], [7 => $updated])))->toBeTrue()
		->and($GLOBALS['__gpu_log'][0]['message'])->toBe('DEBUG: GPU record 7 changed after it was read, so this collection left it alone');

	$GLOBALS['__gpu_db_exec'] = [];

	expect((new GpuRepository(false))->apply(new \Somethingwithproof\Gpu\Reconciliation([], [$updated])))->toBeFalse()
		->and($GLOBALS['__gpu_db_exec'])->toBe([]);
});

it('keeps each record as it was read, so the write can be checked against it', function () {
	$snapshot = repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1');
	$stored   = GpuRecord::discovered($snapshot->reports[0], $snapshot, 1, 0, 10);
	$stored   = new GpuRecord(3, $stored->vendor, $stored->uuid, 1, 0, $stored->pciBusId, $stored->name, $stored->driverVersion, $stored->tool, $stored->toolVersion, Presence::Present, 0, 0, 2, 5, 10, 10);

	$changes = (new IdentityReconciler())->reconcile(repo_node(1), [$stored], $snapshot, 3, 20);

	expect($changes->updated[0]->pendingNodeId)->toBe(0)
		->and($changes->read[3])->toBe($stored);
});

it('records nothing when the stored records cannot be read', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu WHERE node_id'] = false;

	$gpus      = new GpuRepository(false);
	$inventory = new Inventory($gpus, new IdentityReconciler(), new FixedClock(500));

	expect($gpus->forReconcile(repo_node(2), repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1')))->toBeNull()
		->and($inventory->record(repo_node(2), repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1'), 3)->isEmpty())->toBeTrue()
		->and($GLOBALS['__gpu_db_exec'])->toBe([])
		->and($GLOBALS['__gpu_log'][0]['message'])->toBe('WARNING: The GPU records for node 2 could not be read, so this collection was not recorded');
});

it('reads stored records back, skipping rows of a vendor this code does not know', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = [
		['id' => '3', 'vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-0000000000a1', 'node_id' => '4', 'gpu_index' => null, 'pci_bus_id' => '00000000:3B:00.0', 'presence' => 'absent', 'missing_count' => '2', 'replaces_gpu_id' => '0', 'pending_node_id' => '0', 'pending_since' => '0', 'first_seen' => '10', 'last_seen' => '20'],
		['id' => '4', 'vendor' => 'future', 'uuid' => 'X', 'node_id' => '4'],
	];

	$records = (new GpuRepository(false))->forReconcile(repo_node(4), repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1'));

	expect($records)->toHaveCount(1)
		->and($records[0]->id)->toBe(3)
		->and($records[0]->index)->toBeNull()
		->and($records[0]->presence)->toBe(Presence::Absent)
		->and($records[0]->missingCount)->toBe(2);
});

it('confirms or rejects only a pending move, and reports whether one changed', function () {
	$gpus = new GpuRepository(false);

	expect($gpus->confirmMove(5))->toBeTrue()
		->and($GLOBALS['__gpu_db_exec'][0]['sql'])->toContain('SET node_id = pending_node_id')
		->and($GLOBALS['__gpu_db_exec'][0]['sql'])->toContain('AND pending_node_id > 0');

	$GLOBALS['__gpu_affected_rows'] = 0;

	expect($gpus->confirmMove(5))->toBeFalse()
		->and($gpus->rejectMove(5))->toBeFalse();
});

it('deletes a node only in the statement that checks it owns no GPU', function () {
	$nodes = new NodeRepository(false, new FixedClock(100));

	expect($nodes->delete(3))->toBeTrue()
		->and($GLOBALS['__gpu_db_exec'][0]['sql'])->toContain('NOT EXISTS (SELECT 1 FROM plugin_gpu_gpu WHERE node_id = ? OR pending_node_id = ?)')
		->and($GLOBALS['__gpu_db_exec'][0]['params'])->toBe([3, 3, 3]);

	$GLOBALS['__gpu_affected_rows'] = 0;

	expect($nodes->delete(3))->toBeFalse();
});

it('records a snapshot through the repository and logs new identities by node id only', function () {
	$inventory = new Inventory(new GpuRepository(false), new IdentityReconciler(), new FixedClock(500));
	$changes   = $inventory->record(repo_node(2), repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1'), 3);

	expect($changes->created)->toHaveCount(1)
		->and($changes->created[0]->firstSeen)->toBe(500)
		->and($GLOBALS['__gpu_db_exec'])->toHaveCount(1)
		->and($GLOBALS['__gpu_log'])->toBe([['message' => 'NOTE: Node 2: 1 new GPU records, 0 updated', 'environ' => 'GPU', 'level' => POLLER_VERBOSITY_MEDIUM]]);
});

it('writes nothing for a disabled node', function () {
	$inventory = new Inventory(new GpuRepository(false), new IdentityReconciler(), new FixedClock(500));

	expect($inventory->record(repo_node(2, AdminState::Disabled), repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1'), 3)->isEmpty())->toBeTrue()
		->and($GLOBALS['__gpu_db_exec'])->toBe([]);
});

it('exports every node and GPU record, without serial numbers', function () {
	$snapshot = repo_snapshot('GPU-00000000-0000-4000-8000-0000000000a1');
	$json     = InventoryExport::json([repo_node()], [GpuRecord::discovered($snapshot->reports[0], $snapshot, 1, 0, 0)], '0.1.0-dev', 1800000000);
	$document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

	expect($document['plugin'])->toBe('gpu')
		->and($document['exported_at'])->toBe('2027-01-15T08:00:00Z')
		->and($document['nodes'][0]['host_id'])->toBe(101)
		->and($document['gpus'][0]['uuid'])->toBe('GPU-00000000-0000-4000-8000-0000000000a1')
		->and($document['gpus'][0]['tool_version'])->toBe('580.95.05')
		->and($json)->not->toContain('serial');
});

it('keeps HTML delimiters out of the export, which csrf-magic would otherwise rewrite', function () {
	$node     = new Node(1, 101, AdminState::Enabled, '<html> rack 4', true, 0, 0);
	$json     = InventoryExport::json([$node], [], '0.1.0-dev', 0);
	$document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

	expect(stripos($json, '<html'))->toBeFalse()
		->and($document['nodes'][0]['description'])->toBe('<html> rack 4');
});
