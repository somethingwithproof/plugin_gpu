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

use Somethingwithproof\Gpu\Metric;
use Somethingwithproof\Gpu\MetricModel;

it('names every field within Cacti\'s data source item limit, once', function () {
	$names = MetricModel::names();

	expect($names)->toBe(array_values(array_unique($names)));

	foreach ($names as $name) {
		expect($name)->toMatch('/^[a-zA-Z0-9_]{1,19}$/', $name);
	}

	// The two the research found too long for the limit are renamed.
	expect($names)->not->toContain('throttle_hw_slowdown')
		->and($names)->not->toContain('throttle_hw_power_brake')
		->and($names)->toContain('thr_hw_slowdown')
		->and($names)->toContain('thr_hw_power_brake');
});

it('gives every field a unit, a kind, and a source for each backend', function () {
	foreach (MetricModel::all() as $metric) {
		expect($metric->unit)->not->toBe('', $metric->name)
			->and($metric->kind)->toBeIn([Metric::GAUGE, Metric::INDICATOR])
			->and($metric->dcgm)->not->toBe('')
			->and($metric->amd)->not->toBe('');

		// Read from a field, decoded from a mask bit, or computed: never two.
		expect(($metric->nvidiaField !== null ? 1 : 0) + ($metric->maskBit !== null ? 1 : 0))->toBeLessThanOrEqual(1, $metric->name);

		if ($metric->kind === Metric::INDICATOR) {
			expect([$metric->min, $metric->max])->toBe([0.0, 1.0], $metric->name);
		}
	}
});

it('stores utilization in percent and memory in bytes whatever the source scale', function () {
	$gpu = MetricModel::find('util_gpu');
	$mem = MetricModel::find('mem_used');
	$sm  = MetricModel::find('clock_sm');

	// nvidia-smi prints percent; DCGM field 1613 is a 0.0 to 1.0 ratio.
	expect(Metric::scale(37.0, $gpu->nvidiaScale))->toBe(37.0)
		->and(Metric::scale(0.37, $gpu->dcgmScale))->toEqualWithDelta(37.0, 1e-9)
		->and([$gpu->min, $gpu->max])->toBe([0.0, 100.0]);

	// nvidia-smi prints MiB with nounits; DCGM 1627 is already bytes.
	expect(Metric::scale(1024.0, $mem->nvidiaScale))->toBe(1073741824.0)
		->and(Metric::scale(1073741824.0, $mem->dcgmScale))->toBe(1073741824.0)
		->and($mem->dcgm)->toStartWith('1627 (used, bytes)')
		->and(MetricModel::find('mem_total')->dcgm)->toStartWith('1625 (capacity, bytes)');

	// DCGM 1603 is hertz; the model is MHz.
	expect(Metric::scale(1410000000.0, $sm->dcgmScale))->toEqualWithDelta(1410.0, 1e-6)
		->and(Metric::scale(null, 100.0))->toBeNull();
});

it('decodes the throttle indicators the research names, including hardware slowdown', function () {
	expect(MetricModel::BITS)->toBe([
		'thr_sw_power_cap'   => 0x4,
		'thr_hw_slowdown'    => 0x8,
		'thr_sw_thermal'     => 0x20,
		'thr_hw_thermal'     => 0x40,
		'thr_hw_power_brake' => 0x80,
	]);

	foreach (MetricModel::BITS as $name => $bit) {
		expect(MetricModel::find($name)->maskBit)->toBe($bit);
	}
});

it('queries identity and the sample time in the same nvidia-smi query as the metrics', function () {
	$fields = MetricModel::nvidiaFields();

	expect(array_slice($fields, 0, 5))->toBe(['timestamp', 'uuid', 'index', 'pci.bus_id', 'driver_version'])
		->and(end($fields))->toBe('clocks_throttle_reasons.active')
		->and($fields)->not->toContain('name')
		->and($fields)->not->toContain('serial')
		->and($fields)->toBe(array_values(array_unique($fields)))
		->and(MetricModel::namesFields())->toBe(['uuid', 'name']);
});

it('matches the generated table in docs/metrics.md', function () {
	$doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/metrics.md');

	preg_match('/<!-- metric-table:start -->\n(.*)<!-- metric-table:end -->/s', $doc, $match);

	expect($match[1] ?? '')->toBe(MetricModel::table());
});
