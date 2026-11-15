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

use Somethingwithproof\Gpu\CollectionError;
use Somethingwithproof\Gpu\ExecutionState;
use Somethingwithproof\Gpu\ExtendOutput;
use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\NvidiaSmiParser;
use Somethingwithproof\Gpu\SnmpdConfig;
use Somethingwithproof\Gpu\SnmpExtendReader;
use Somethingwithproof\Gpu\ThrottleMask;

const SMI_NOW = '2027/01/15 08:00:00.250';
const SMI_TS  = 1800000000;

/**
 * @return list<string>
 */
function smi_lines(string $fixture): array {
	$text = (string) file_get_contents(dirname(__DIR__) . '/fixtures/nvidia-smi/' . $fixture);

	return explode("\n", rtrim(str_replace('@NOW@', SMI_NOW, $text), "\n"));
}

/**
 * @param list<string> $lines
 */
function smi_out(array $lines, int $result = 0, ?int $declared = null): ExtendOutput {
	return new ExtendOutput($result, $declared ?? count($lines), $lines);
}

function smi_parse(string $name): \Somethingwithproof\Gpu\Collection {
	return (new NvidiaSmiParser())->parse(smi_out(smi_lines("$name.metrics")), smi_out(smi_lines("$name.names")));
}

function smi_error(ExtendOutput $metrics, ?ExtendOutput $names = null): CollectionError {
	try {
		(new NvidiaSmiParser())->parse($metrics, $names ?? smi_out([]));
	} catch (CollectionError $e) {
		return $e;
	}

	throw new RuntimeException('the output was accepted');
}

it('parses each driver line\'s fixture into one reading per GPU, in model units', function (string $fixture, int $gpus, string $driver) {
	$collection = smi_parse($fixture);
	$first      = $collection->readings[0];

	expect($collection->readings)->toHaveCount($gpus)
		->and($collection->snapshot->reports)->toHaveCount($gpus)
		->and($collection->state())->toBe(ExecutionState::Ok)
		->and($collection->backend)->toBe('nvidia-smi')
		->and($collection->snapshot->toolVersion)->toBe($driver)
		->and($first->observedAt)->toBe(SMI_TS)
		->and($first->report->uuid)->toBe('GPU-00000000-0000-4000-8000-000000000001')
		->and($first->report->index)->toBe(0)
		->and($first->report->pciBusId)->toBe('00000000:18:00.0')
		->and($first->report->name)->toBe('NVIDIA H100 80GB HBM3')
		->and($first->values['util_gpu'])->toBe(30.0)
		->and($first->values['mem_used'])->toBe(2048 * 1048576.0)
		->and($first->values['mem_total'])->toBe(81559 * 1048576.0)
		->and($first->values['clock_sm'])->toBe(1410.0)
		->and(array_keys($first->values))->toEqualCanonicalizing(array_values(array_diff(MetricModel::names(), ['age'])));
})->with([
	['r580-8gpu', 8, '580.95.05'],
	['r595-1gpu', 1, '595.45.04'],
	['r620-16gpu', 16, '620.70.06'],
]);

it('stores placeholders as unknown, never zero', function () {
	$values = smi_parse('r595-1gpu')->readings[0]->values;

	expect($values['power'])->toBeNull()
		->and($values['power_limit'])->toBeNull()
		->and($values['ecc_corr'])->toBeNull()
		->and($values['ecc_uncorr'])->toBeNull()
		->and($values['retired_pending'])->toBeNull()
		->and($values['thr_hw_slowdown'])->toBe(0.0);
});

it('decodes the throttle mask in hex or decimal, keeping the raw bits', function () {
	$eight   = smi_parse('r580-8gpu')->readings;
	$sixteen = smi_parse('r620-16gpu')->readings;

	expect($eight[1]->mask)->toBe(0x4)
		->and($eight[1]->values['thr_sw_power_cap'])->toBe(1.0)
		->and($eight[0]->mask)->toBe(0x1)
		->and($eight[0]->values['thr_sw_power_cap'])->toBe(0.0)
		->and($sixteen[3]->values['thr_hw_slowdown'])->toBe(1.0)
		->and($sixteen[3]->values['thr_hw_thermal'])->toBe(1.0)
		->and($sixteen[3]->values['thr_hw_power_brake'])->toBe(0.0);

	expect(ThrottleMask::parse('0x0000000000000400'))->toBe(0x400)
		->and(ThrottleMask::parse('1024'))->toBe(1024)
		->and(ThrottleMask::parse('0x7FF'))->toBe(0x7ff)
		->and(ThrottleMask::parse('[N/A]'))->toBeNull()
		->and(ThrottleMask::parse('Not Active'))->toBeNull()
		->and(ThrottleMask::parse('-4'))->toBeNull()
		->and(ThrottleMask::indicators(null))->toBe(array_fill_keys(array_keys(MetricModel::BITS), null));
});

it('reads a product name with commas and shell metacharacters as one field, and keeps it off every command line', function () {
	$hostile = 'NVIDIA $(reboot); `id` | x, "y" & z';
	$metrics = smi_lines('r595-1gpu.metrics');
	$names   = ['GPU-00000000-0000-4000-8000-000000000001, ' . $hostile];

	$reading = (new NvidiaSmiParser())->parse(smi_out($metrics), smi_out($names))->readings[0];

	expect($reading->report->name)->toBe($hostile)
		->and($reading->values['util_gpu'])->toBe(30.0)
		->and($reading->report->pciBusId)->toBe('00000000:18:00.0');

	// The commands are fixed in the documented snmpd.conf; nothing the node
	// or a GPU reports is part of them.
	foreach (SnmpdConfig::commands() as $command) {
		expect($command)->not->toContain('$(')
			->and($command)->not->toContain('`')
			->and($command)->not->toContain(';')
			->and($command)->not->toContain('|');
	}
});

it('rejects a line with fewer or more fields than requested', function () {
	$line  = smi_lines('r595-1gpu.metrics')[0];
	$short = smi_error(smi_out([substr($line, 0, (int) strrpos($line, ','))]));
	$extra = smi_error(smi_out([$line . ', 42']));

	expect($short->state)->toBe(ExecutionState::InvalidOutput)
		->and($short->getMessage())->toBe('GPU metrics line 1 has 17 fields where 18 were requested')
		->and($short->rejectedLines)->toBe(1)
		->and($extra->getMessage())->toBe('GPU metrics line 1 has 19 fields where 18 were requested');
});

it('rejects stderr text that snmpd merged into the output', function () {
	$lines = smi_lines('r580-8gpu.metrics');
	array_splice($lines, 2, 0, ['Failed to initialize NVML: Driver/library version mismatch']);

	$error = smi_error(smi_out($lines));

	expect($error->state)->toBe(ExecutionState::InvalidOutput)
		->and($error->getMessage())->toBe('GPU metrics line 3 has 1 fields where 18 were requested')
		->and($error->getMessage())->not->toContain('NVML');

	expect(smi_error(smi_out(smi_lines('r595-1gpu.metrics')), smi_out(['WARNING: infoROM is corrupted at gpu 0000:18:00.0']))->getMessage())
		->toBe('GPU names line 1 is not a UUID and a name');
});

it('treats a nonzero exit status as an error state, never as values', function (int $status, ExecutionState $state) {
	expect(smi_error(smi_out(smi_lines('r595-1gpu.metrics'), $status))->state)->toBe($state)
		->and(smi_error(smi_out(smi_lines('r595-1gpu.metrics')), smi_out([], $status))->state)->toBe($state);
})->with([
	'timeout(1) stopped it'  => [124, ExecutionState::TimedOut],
	'timeout(1) killed it'   => [137, ExecutionState::TimedOut],
	'no permission'          => [4, ExecutionState::PermissionDenied],
	'driver not loaded'      => [9, ExecutionState::DriverNotLoaded],
	'not found'              => [127, ExecutionState::ToolMissing],
	'GPU fell off the bus'   => [15, ExecutionState::ToolError],
]);

it('treats output cut at snmpd\'s limits as truncated', function () {
	$lines = smi_lines('r580-8gpu.metrics');

	expect(smi_error(smi_out($lines, 0, count($lines) + 3))->state)->toBe(ExecutionState::Truncated)
		->and(smi_error(smi_out(array_fill(0, ExtendOutput::MAX_LINES, $lines[0])))->state)->toBe(ExecutionState::Truncated)
		->and(smi_error(smi_out([str_repeat('x', ExtendOutput::MAX_BYTES)]))->state)->toBe(ExecutionState::Truncated);
});

it('rejects a sample time that is missing, malformed, or impossible', function (string $time) {
	$line = str_replace(SMI_NOW, $time, smi_lines('r595-1gpu.metrics')[0]);

	expect(smi_error(smi_out([$line]))->getMessage())->toBe('GPU metrics line 1 has no valid sample time');
})->with(['[N/A]', '2027-01-15 08:00:00', '2027/02/30 08:00:00.000', '']);

it('rejects identity it cannot trust, refusing the whole collection', function (string $from, string $to, string $message) {
	$lines = smi_lines('r580-8gpu.metrics');
	$lines[1] = str_replace($from, $to, $lines[1]);

	expect(smi_error(smi_out($lines))->getMessage())->toBe($message);
})->with([
	'MIG UUID'       => ['GPU-00000000-0000-4000-8000-000000000002', 'MIG-00000000-0000-4000-8000-000000000002', 'GPU metrics line 2: GPU UUID is a MIG instance UUID, which is not an identity key'],
	'duplicate UUID' => ['GPU-00000000-0000-4000-8000-000000000002', 'GPU-00000000-0000-4000-8000-000000000001', 'Two GPUs in one collection report the same UUID'],
	'bad bus ID'     => ['00000000:28:00.0', 'slot 2', 'GPU metrics line 2: PCI bus ID is not domain:bus:device.function'],
	'negative index' => [', 1, 00000000:28', ', -1, 00000000:28', 'GPU metrics line 2: GPU index is negative'],
]);

it('reports an empty but successful collection as empty', function () {
	$collection = (new NvidiaSmiParser())->parse(smi_out([]), smi_out([]));

	expect($collection->state())->toBe(ExecutionState::Empty)
		->and($collection->snapshot->reports)->toBe([])
		->and($collection->newestSample())->toBe(0);
});

it('documents the exact extend lines it parses, within extend\'s command limit', function () {
	$doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/node-setup.md');

	preg_match('/<!-- extend-lines:start -->\n```\n(.*)\n```\n<!-- extend-lines:end -->/s', $doc, $match);

	expect($match[1] ?? '')->toBe(implode("\n", SnmpdConfig::extendLines()))
		->and(SnmpdConfig::TIMEOUT + SnmpdConfig::KILL_AFTER)->toBeLessThan(SnmpdConfig::METRICS_CACHE);

	foreach (SnmpdConfig::commands() as $name => $command) {
		expect(strlen($command))->toBeLessThan(SnmpdConfig::MAX_COMMAND, $name)
			->and($command)->toStartWith('/usr/bin/env -i ')
			->and($command)->toContain(' /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=');
	}

	expect(SnmpdConfig::commands()[SnmpdConfig::METRICS])->toContain('--query-gpu=' . implode(',', MetricModel::nvidiaFields()) . ' --format=csv,noheader,nounits');
});

it('encodes extend entry names as NET-SNMP-EXTEND-MIB indexes', function () {
	expect(SnmpExtendReader::index('cacti_gpu'))->toBe('9.99.97.99.116.105.95.103.112.117');
});

function smi_device(): \Somethingwithproof\Gpu\NodeDevice {
	return \Somethingwithproof\Gpu\NodeDevice::fromRow(['id' => 4, 'hostname' => 'node', 'snmp_version' => 3]);
}

it('reads an extend entry\'s status, line count, and lines in line order', function () {
	$index = SnmpExtendReader::index('cacti_gpu');

	$GLOBALS['__gpu_snmp'] = [
		"1.3.6.1.4.1.8072.1.3.2.3.1.4.$index" => '0',
		"1.3.6.1.4.1.8072.1.3.2.3.1.3.$index" => '2',
		// Walk order is not trusted; the line number is the last component.
		"1.3.6.1.4.1.8072.1.3.2.4.1.2.$index.2" => 'second',
		"1.3.6.1.4.1.8072.1.3.2.4.1.2.$index.1" => 'first',
		// Another entry whose name starts the same way is not mixed in.
		'1.3.6.1.4.1.8072.1.3.2.4.1.2.' . SnmpExtendReader::index('cacti_gpu_names') . '.1' => 'other',
	];

	$output = (new SnmpExtendReader(0))->read(smi_device(), 'cacti_gpu');

	$GLOBALS['__gpu_snmp'] = [];

	expect($output->result)->toBe(0)
		->and($output->declaredLines)->toBe(2)
		->and($output->lines)->toBe(['first', 'second'])
		->and($output->truncated())->toBeFalse();
});

it('gives the first read of an entry time for snmpd to run the command, whatever the device timeout', function () {
	$index = SnmpExtendReader::index('cacti_gpu');

	$GLOBALS['__gpu_snmp']      = ["1.3.6.1.4.1.8072.1.3.2.3.1.4.$index" => '0', "1.3.6.1.4.1.8072.1.3.2.3.1.3.$index" => '0'];
	$GLOBALS['__gpu_snmp_gets'] = [];

	(new SnmpExtendReader(0))->read(smi_device(), 'cacti_gpu');

	$gets = $GLOBALS['__gpu_snmp_gets'];
	$GLOBALS['__gpu_snmp'] = [];

	expect($gets[0])->toBe(["1.3.6.1.4.1.8072.1.3.2.3.1.4.$index", 15000])
		->and(SnmpExtendReader::EXTEND_TIMEOUT_MS)->toBeGreaterThan((\Somethingwithproof\Gpu\SnmpdConfig::TIMEOUT + \Somethingwithproof\Gpu\SnmpdConfig::KILL_AFTER) * 1000);
});

it('rejects output that snmpd replaced with another run while it was read', function () {
	$index   = SnmpExtendReader::index('cacti_gpu');
	$results = ['0', '124'];

	// The cache expired after the first result: the next run timed out.
	$GLOBALS['__gpu_snmp'] = [
		"1.3.6.1.4.1.8072.1.3.2.3.1.4.$index" => function () use (&$results) {
			return array_shift($results) ?? '124';
		},
		"1.3.6.1.4.1.8072.1.3.2.3.1.3.$index" => '1',
		"1.3.6.1.4.1.8072.1.3.2.4.1.2.$index.1" => 'partial',
	];

	try {
		(new SnmpExtendReader(0))->read(smi_device(), 'cacti_gpu');
	} catch (CollectionError $e) {
		$changed = $e;
	}

	$GLOBALS['__gpu_snmp'] = [];

	expect($changed->state)->toBe(ExecutionState::InvalidOutput)
		->and($changed->getMessage())->toContain('changed while it was read');
});

it('tells a missing extend entry from an agent that does not answer', function () {
	$GLOBALS['__gpu_snmp'] = ['1.3.6.1.2.1.1.3.0' => '123456'];

	try {
		(new SnmpExtendReader(0))->read(smi_device(), 'cacti_gpu');
	} catch (CollectionError $e) {
		$missing = $e;
	}

	$GLOBALS['__gpu_snmp'] = [];

	try {
		(new SnmpExtendReader(0))->read(smi_device(), 'cacti_gpu');
	} catch (CollectionError $e) {
		$silent = $e;
	}

	expect($missing->state)->toBe(ExecutionState::NotConfigured)
		->and($missing->getMessage())->toBe('The SNMP agent has no extend entry named cacti_gpu')
		->and($silent->state)->toBe(ExecutionState::Unreachable);
});
