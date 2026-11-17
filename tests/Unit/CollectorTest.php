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
use Somethingwithproof\Gpu\CollectionError;
use Somethingwithproof\Gpu\CollectionRepository;
use Somethingwithproof\Gpu\Collector;
use Somethingwithproof\Gpu\ExecutionState;
use Somethingwithproof\Gpu\ExtendOutput;
use Somethingwithproof\Gpu\ExtendReader;
use Somethingwithproof\Gpu\FixedClock;
use Somethingwithproof\Gpu\GpuRecord;
use Somethingwithproof\Gpu\GpuRepository;
use Somethingwithproof\Gpu\IdentityReconciler;
use Somethingwithproof\Gpu\Inventory;
use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\LockRepository;
use Somethingwithproof\Gpu\Node;
use Somethingwithproof\Gpu\NodeDevice;
use Somethingwithproof\Gpu\NvidiaSmiParser;
use Somethingwithproof\Gpu\ObservationRepository;
use Somethingwithproof\Gpu\Presence;
use Somethingwithproof\Gpu\Reconciliation;
use Somethingwithproof\Gpu\ReindexRepository;
use Somethingwithproof\Gpu\Schema;
use Somethingwithproof\Gpu\Settings;
use Somethingwithproof\Gpu\SnmpdConfig;
use Somethingwithproof\Gpu\Vendor;

const COLLECT_NOW = 1800000100;

beforeEach(function () {
	$GLOBALS['__gpu_db_exec']       = [];
	$GLOBALS['__gpu_db_rows']       = ['GET_LOCK' => [['l' => 1]]];
	$GLOBALS['__gpu_log']           = [];
	$GLOBALS['__gpu_affected_rows'] = 0;
	$GLOBALS['__gpu_db_fail']       = [];
});

/**
 * An extend reader that answers from nvidia-smi fixture files, or fails.
 * With $refuseCurrent it plays a driver that knows only the earlier clock
 * event reasons field name: the metrics entry exits with nvidia-smi's
 * invalid-argument status, and the legacy entry, if the node has it, prints
 * the fixture.
 */
final class FixtureReader implements ExtendReader {
	/** @var list<string> */
	public array $reads = [];

	public function __construct(private readonly string $fixture, private readonly ?CollectionError $error = null, private readonly int $status = 0, private readonly string $sampled = '2027/01/15 08:00:00.250', private readonly bool $refuseCurrent = false, private readonly bool $hasLegacy = true) {
	}

	public function read(NodeDevice $device, string $token): ExtendOutput {
		$this->reads[] = $token;

		if ($this->error !== null) {
			throw $this->error;
		}

		if ($this->refuseCurrent && $token === SnmpdConfig::METRICS) {
			return new ExtendOutput(SnmpdConfig::INVALID_ARGUMENT, 1, ['Field "clocks_event_reasons.active" is not a valid field to query.']);
		}

		if ($token === SnmpdConfig::METRICS_LEGACY && !$this->hasLegacy) {
			throw new CollectionError(ExecutionState::NotConfigured, 'The SNMP agent has no extend entry named ' . $token);
		}

		$metrics = $token === SnmpdConfig::METRICS || $token === SnmpdConfig::METRICS_LEGACY;
		$file    = dirname(__DIR__) . '/fixtures/nvidia-smi/' . $this->fixture . ($metrics ? '.metrics' : '.names');
		$text    = $this->fixture === '' ? '' : str_replace('@NOW@', $this->sampled, (string) file_get_contents($file));
		$lines   = $text === '' ? [] : explode("\n", rtrim($text, "\n"));

		return new ExtendOutput($metrics ? $this->status : 0, count($lines), $lines);
	}
}

function collector(ExtendReader $reader): Collector {
	$clock = new FixedClock(COLLECT_NOW);
	$gpus  = new GpuRepository(false);

	return new Collector($reader, new NvidiaSmiParser(), new Inventory($gpus, new IdentityReconciler(), $clock), $gpus, new ObservationRepository(false), new CollectionRepository(false), new ReindexRepository(false), new LockRepository(false), $clock);
}

function collect_node(AdminState $state = AdminState::Enabled): Node {
	return new Node(3, 40, $state, 'gpu-node', true, 0, 0);
}

function collect_device(): NodeDevice {
	return NodeDevice::fromRow(['id' => 40, 'hostname' => 'gpu-node', 'snmp_version' => 3]);
}

/**
 * @return list<string> statements that write, in order
 */
function collect_writes(string $table): array {
	return array_values(array_map(fn ($e) => $e['sql'], array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => preg_match('/^\s*(INSERT|UPDATE|DELETE)\b.*\b' . $table . '\b/s', $e['sql']) === 1)));
}

/**
 * Stored records for the r595-1gpu fixture's GPU, at node 3.
 */
function collect_known(int $node = 3): array {
	return [[
		'id' => 9, 'vendor' => 'nvidia', 'uuid' => 'GPU-00000000-0000-4000-8000-000000000001', 'node_id' => $node, 'gpu_index' => 0,
		'pci_bus_id' => '00000000:18:00.0', 'name' => 'NVIDIA H100 80GB HBM3', 'driver_version' => '595.45.04', 'tool' => 'nvidia-smi',
		'tool_version' => '595.45.04', 'presence' => 'present', 'first_seen' => 1, 'last_seen' => 1,
	]];
}

it('stores nothing and changes no GPU record when the collection fails', function (CollectionError|int $failure, ExecutionState $state) {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = collect_known();

	$reader = is_int($failure) ? new FixtureReader('r595-1gpu', status: $failure) : new FixtureReader('r595-1gpu', $failure);

	expect(collector($reader)->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe($state)
		->and(collect_writes('plugin_gpu_gpu'))->toBe([])
		->and(collect_writes('plugin_gpu_observation'))->toBe([])
		->and(collect_writes('plugin_gpu_reindex'))->toBe([]);

	$finish = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'last_ok_at')));

	expect($finish)->toHaveCount(1)
		->and($finish[0]['params'][1])->toBe($state->value)
		->and(end($finish[0]['params']))->toBe(0);

	expect(array_column($GLOBALS['__gpu_log'], 'message'))->toContain('WARNING: Node 3: collection failed (' . $state->value . '): ' . $finish[0]['params'][2]);
})->with([
	'agent down'       => [new CollectionError(ExecutionState::Unreachable, 'The SNMP agent did not answer'), ExecutionState::Unreachable],
	'entry missing'    => [new CollectionError(ExecutionState::NotConfigured, 'The SNMP agent has no extend entry named cacti_gpu'), ExecutionState::NotConfigured],
	'tool timed out'   => [124, ExecutionState::TimedOut],
	'driver not ready' => [9, ExecutionState::DriverNotLoaded],
]);

it('refuses a sample stamped further ahead of Cacti than clock skew explains, and records nothing', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = collect_known();

	// COLLECT_NOW is 08:01:40; 120 seconds ahead is allowed, 121 is not.
	expect(collector(new FixtureReader('r595-1gpu', sampled: '2027/01/15 08:03:41.000'))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::InvalidOutput)
		->and(collect_writes('plugin_gpu_gpu'))->toBe([])
		->and(collect_writes('plugin_gpu_observation'))->toBe([]);

	$finish = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'last_ok_at')));

	expect($finish[0]['params'][2])->toBe("The sample time is 121 seconds ahead of the Cacti server's clock");

	$GLOBALS['__gpu_db_exec'] = [];

	expect(collector(new FixtureReader('r595-1gpu', sampled: '2027/01/15 08:03:40.000'))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::Ok);
});

/**
 * The observation rows one collection of $reader stores.
 *
 * @return list<list<mixed>>
 */
function collect_observations(FixtureReader $reader): array {
	$GLOBALS['__gpu_db_exec'] = [];

	expect(collector($reader)->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::Ok);

	return array_values(array_map(fn ($e) => $e['params'], array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'INSERT INTO plugin_gpu_observation'))));
}

it('reads the earlier clock event reasons field name when the driver refuses the current one, decoding the same bits', function (string $fixture) {
	// A stored record for each GPU of the fixture, at node 3.
	$records = [];

	foreach (file(dirname(__DIR__) . '/fixtures/nvidia-smi/' . $fixture . '.metrics', FILE_IGNORE_NEW_LINES) as $i => $line) {
		[, $uuid, $index, $bus, $driver] = explode(', ', $line);

		$records[] = ['id' => 10 + $i, 'vendor' => 'nvidia', 'uuid' => $uuid, 'node_id' => 3, 'gpu_index' => (int) $index, 'pci_bus_id' => $bus,
			'name' => 'x', 'driver_version' => $driver, 'tool' => 'nvidia-smi', 'tool_version' => $driver, 'presence' => 'present', 'first_seen' => 1, 'last_seen' => 1];
	}

	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = $records;

	$current = new FixtureReader($fixture);
	$legacy  = new FixtureReader($fixture, refuseCurrent: true);
	$stored  = collect_observations($current);

	expect($stored)->not->toBe([])
		->and(collect_observations($legacy))->toBe($stored)
		->and($current->reads)->toBe([SnmpdConfig::METRICS, SnmpdConfig::NAMES])
		->and($legacy->reads)->toBe([SnmpdConfig::METRICS, SnmpdConfig::METRICS_LEGACY, SnmpdConfig::NAMES]);
})->with(['r580-8gpu', 'r595-1gpu', 'r620-16gpu']);

it('keeps the refusal of the current field name as the outcome when the node has no legacy entry', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = collect_known();

	$reader = new FixtureReader('r595-1gpu', refuseCurrent: true, hasLegacy: false);

	expect(collector($reader)->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::ToolError)
		->and(collect_writes('plugin_gpu_observation'))->toBe([]);
});

it('records an empty collection as successful without marking any GPU absent', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = collect_known();

	expect(collector(new FixtureReader(''))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::Empty)
		->and(collect_writes('plugin_gpu_gpu'))->toBe([])
		->and(collect_writes('plugin_gpu_observation'))->toBe([]);
});

it('stores each reported GPU\'s values against its record and asks for a reindex for a new GPU', function () {
	// The first read finds no record; the second finds the one just inserted.
	$reads = 0;
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = function () use (&$reads) {
		return $reads++ === 0 ? [] : collect_known();
	};

	expect(collector(new FixtureReader('r595-1gpu'))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::Ok)
		->and(collect_writes('plugin_gpu_gpu'))->toHaveCount(1)
		->and(collect_writes('plugin_gpu_observation'))->toHaveCount(1)
		->and(collect_writes('plugin_gpu_reindex'))->toHaveCount(1);

	$store = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'INSERT INTO plugin_gpu_observation')))[0];
	$named = array_combine(array_merge(['gpu_id', 'node_id', 'observed_at', 'ingested_at', 'reported_at', 'backend', 'tool_version'], array_values(array_diff(Schema::observationMetrics(), array_keys(MetricModel::DELTAS))), ['thr_mask', 'ecc_mode']), $store['params']);

	expect($named['gpu_id'])->toBe(9)
		->and($named['node_id'])->toBe(3)
		->and($named['observed_at'])->toBe(1800000000)
		->and($named['ingested_at'])->toBe(COLLECT_NOW)
		->and($named['backend'])->toBe('nvidia-smi')
		->and($named['util_gpu'])->toBe(30.0)
		->and($named['power'])->toBeNull()
		->and($named['thr_mask'])->toBe(0)
		->and($named['ecc_mode'])->toBe('unavailable')
		->and($named)->not->toHaveKey('ecc_corr_new')
		->and($store['sql'])->toContain('observed_at = IF(VALUES(observed_at) > observed_at, VALUES(observed_at), observed_at)')
		->and(strrpos($store['sql'], 'observed_at = IF('))->toBeGreaterThan(strrpos($store['sql'], 'thr_mask = IF('));

	$reindex = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'plugin_gpu_reindex')))[0];

	expect($reindex['params'])->toBe([3, COLLECT_NOW, 'new GPU']);
});

it('fails the collection as not stored when a new GPU\'s record could not be inserted', function () {
	// The insert fails, so the second read still finds no record.
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = [];
	$GLOBALS['__gpu_db_fail']                        = ['INSERT INTO plugin_gpu_gpu'];

	expect(collector(new FixtureReader('r595-1gpu'))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::NotStored)
		->and(collect_writes('plugin_gpu_observation'))->toBe([]);

	$finish = array_values(array_filter($GLOBALS['__gpu_db_exec'], fn ($e) => str_contains($e['sql'], 'INSERT INTO plugin_gpu_collection (node_id, state')));

	expect($finish[0]['params'][1])->toBe('not_stored')
		->and($finish[0]['params'][2])->toBe('1 of 1 GPU samples could not be stored');
});

it('keeps the reindex request for a new GPU when its sample could not be stored', function () {
	$reads = 0;
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = function () use (&$reads) {
		return $reads++ === 0 ? [] : collect_known();
	};
	$GLOBALS['__gpu_db_fail'] = ['INSERT INTO plugin_gpu_observation'];

	expect(collector(new FixtureReader('r595-1gpu'))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::NotStored)
		->and(collect_writes('plugin_gpu_reindex'))->toHaveCount(1);
});

it('reports a collection whose status could not be stored as not stored', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = collect_known();
	$GLOBALS['__gpu_db_fail']                        = ['INSERT INTO plugin_gpu_collection (node_id, state'];

	expect(collector(new FixtureReader('r595-1gpu'))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::NotStored);
});

it('does not store values for a GPU whose record waits for a move to be confirmed', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = collect_known(node: 5);

	expect(collector(new FixtureReader('r595-1gpu'))->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::Ok)
		->and(collect_writes('plugin_gpu_observation'))->toBe([]);
});

it('asks for no reindex when the listing is unchanged', function () {
	$GLOBALS['__gpu_db_rows']['FROM plugin_gpu_gpu'] = collect_known();

	collector(new FixtureReader('r595-1gpu'))->collect(collect_node(), collect_device(), Settings::fromRow([]));

	expect(collect_writes('plugin_gpu_observation'))->toHaveCount(1)
		->and(collect_writes('plugin_gpu_reindex'))->toBe([]);
});

it('skips a node another collection holds, and never collects a disabled node', function () {
	$GLOBALS['__gpu_db_rows']['GET_LOCK'] = [['l' => 0]];
	$reader = new FixtureReader('r595-1gpu');

	expect(collector($reader)->collect(collect_node(), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::Skipped)
		->and($reader->reads)->toBe([])
		->and($GLOBALS['__gpu_db_exec'][0]['sql'])->toContain('skipped = skipped + 1');

	$GLOBALS['__gpu_db_rows']['GET_LOCK'] = [['l' => 1]];
	$GLOBALS['__gpu_db_exec']             = [];

	expect(collector($reader)->collect(collect_node(AdminState::Disabled), collect_device(), Settings::fromRow([])))->toBe(ExecutionState::Skipped)
		->and($reader->reads)->toBe([])
		->and($GLOBALS['__gpu_db_exec'])->toBe([]);
});

it('asks for a reindex when a GPU\'s index, name, bus ID, or node changes, and not for presence or driver alone', function () {
	$read = GpuRecord::fromRow(collect_known()[0]);

	$changed = static fn (array $diff): Reconciliation => new Reconciliation([], [GpuRecord::fromRow(array_merge(collect_known()[0], $diff))], [9 => $read]);

	expect(Collector::reindexReason(new Reconciliation()))->toBe('')
		->and(Collector::reindexReason(new Reconciliation(), 1))->toBe('retired GPU returned')
		->and(Collector::reindexReason($changed(['gpu_index' => 1])))->toBe('GPU changed')
		->and(Collector::reindexReason($changed(['name' => 'NVIDIA H200'])))->toBe('GPU changed')
		->and(Collector::reindexReason($changed(['pci_bus_id' => '00000000:28:00.0'])))->toBe('GPU changed')
		->and(Collector::reindexReason($changed(['node_id' => 5])))->toBe('GPU changed')
		->and(Collector::reindexReason($changed(['presence' => Presence::Absent->value, 'missing_count' => 3])))->toBe('')
		->and(Collector::reindexReason($changed(['driver_version' => '620.70.06'])))->toBe('');
});

it('reads the staleness limit within its bounds', function (mixed $stored, int $expected) {
	expect(Settings::fromRow(['gpu_stale_after' => $stored])->staleAfter)->toBe($expected);
})->with([
	['', Settings::DEFAULT_STALE_AFTER],
	['10', Settings::MIN_STALE_AFTER],
	['900', 900],
	['999999', Settings::MAX_STALE_AFTER],
]);

it('keeps one observation column per stored model field', function () {
	expect(Schema::observationMetrics())->toEqualCanonicalizing(array_values(array_diff(\Somethingwithproof\Gpu\MetricModel::names(), ['age', 'power_pct'])));
});

it('computes new ECC errors from the stored total, as unknown across a reset or a missing total', function () {
	(new \Somethingwithproof\Gpu\ObservationRepository(false))->store(9, 3, smi_parse('r580-8gpu')->readings[0], 'nvidia-smi', '580.95.05', COLLECT_NOW);

	$sql = $GLOBALS['__gpu_db_exec'][0]['sql'];

	// Before the totals are replaced, and only for a newer sample.
	expect($sql)->toContain('IF(VALUES(observed_at) > observed_at, IF(VALUES(ecc_corr) >= ecc_corr, VALUES(ecc_corr) - ecc_corr, NULL), ecc_corr_new)')
		->and($sql)->toContain('IF(VALUES(observed_at) > observed_at, IF(VALUES(ecc_uncorr) >= ecc_uncorr, VALUES(ecc_uncorr) - ecc_uncorr, NULL), ecc_uncorr_new)')
		->and(strpos($sql, 'ecc_corr_new = IF('))->toBeLessThan(strpos($sql, 'ecc_corr = IF('))
		->and(strpos($sql, 'ecc_uncorr_new = IF('))->toBeLessThan(strpos($sql, 'ecc_uncorr = IF('));
});
