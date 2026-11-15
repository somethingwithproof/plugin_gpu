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

use Somethingwithproof\Gpu\GpuReport;
use Somethingwithproof\Gpu\GpuSnapshot;
use Somethingwithproof\Gpu\Vendor;

const GPU_A = 'GPU-00000000-0000-4000-8000-0000000000a1';
const GPU_B = 'GPU-00000000-0000-4000-8000-0000000000b2';

function gpu_report(string $uuid = GPU_A, ?int $index = 0, string $bus = '00000000:3B:00.0', string $name = 'NVIDIA Test GPU', string $driver = '580.95.05'): GpuReport {
	return GpuReport::create(Vendor::Nvidia, $uuid, $index, $bus, $name, $driver);
}

it('keys a GPU by vendor and lower-case UUID', function () {
	$report = gpu_report(' GPU-00000000-0000-4000-8000-0000000000A1 ');

	expect($report->uuid)->toBe(GPU_A)
		->and($report->key())->toBe('nvidia/' . GPU_A);
});

it('refuses MIG instance UUIDs as identity keys, in both spellings', function (string $uuid) {
	expect(fn () => gpu_report($uuid))->toThrow(InvalidArgumentException::class, 'MIG instance UUID');
})->with([
	'MIG-00000000-0000-4000-8000-0000000000c3',
	'MIG-GPU-00000000-0000-4000-8000-0000000000a1/1/0',
	'mig-00000000-0000-4000-8000-0000000000c3',
]);

it('refuses a UUID that is not in the vendor format', function (string $uuid) {
	expect(fn () => gpu_report($uuid))->toThrow(InvalidArgumentException::class, 'vendor format');
})->with([
	'',
	'[N/A]',
	'00000000-0000-4000-8000-0000000000a1',
	'gpu-00000000-0000-4000-8000-0000000000a1',
	'GPU-00000000-0000-4000-8000-0000000000a1; rm -rf /',
	'GPU-00000000-0000-4000-8000-0000000000a',
]);

it('normalizes the PCI bus ID to an eight-digit upper-case domain', function (string $bus) {
	expect(gpu_report(bus: $bus)->pciBusId)->toBe('00000000:3B:00.0');
})->with(['00000000:3B:00.0', '0000:3b:00.0', ' 00000000:3b:00.0 ']);

it('refuses a malformed PCI bus ID', function (string $bus) {
	expect(fn () => gpu_report(bus: $bus))->toThrow(InvalidArgumentException::class, 'PCI bus ID');
})->with(['', '[N/A]', '3B:00.0', '00000000:3B:00.8', '00000000:3B:00']);

it('keeps an unavailable index as unknown and refuses a negative one', function () {
	expect(gpu_report(index: null)->index)->toBeNull()
		->and(fn () => gpu_report(index: -1))->toThrow(InvalidArgumentException::class, 'negative');
});

it('strips control characters from tool text and cuts it to the column width', function () {
	$report = gpu_report(name: "NVIDIA\x1b[31m Test\nGPU\x00" . str_repeat('x', 200), driver: "580.95.05\r\n");

	expect($report->name)->not->toMatch('/[\x00-\x1F\x7F]/')
		->and(mb_strlen($report->name))->toBe(128)
		->and($report->name)->toStartWith('NVIDIA [31m Test GPU')
		->and($report->driverVersion)->toBe('580.95.05');
});

it('keeps markup in names as text, for the page to escape', function () {
	expect(gpu_report(name: '<script>x</script>')->name)->toBe('<script>x</script>');
});

it('refuses a snapshot that reports one UUID or one bus ID twice', function () {
	expect(fn () => GpuSnapshot::create('nvidia-smi', '580.95.05', [gpu_report(), gpu_report(bus: '00000000:3C:00.0')]))->toThrow(InvalidArgumentException::class, 'same UUID')
		->and(fn () => GpuSnapshot::create('nvidia-smi', '580.95.05', [gpu_report(), gpu_report(GPU_B)]))->toThrow(InvalidArgumentException::class, 'same PCI bus ID');
});

it('accepts distinct GPUs and records the tool that reported them', function () {
	$snapshot = GpuSnapshot::create("nvidia-smi\n", '580.95.05', [gpu_report(), gpu_report(GPU_B, 1, '00000000:3C:00.0')]);

	expect($snapshot->tool)->toBe('nvidia-smi')
		->and($snapshot->uuids())->toBe([GPU_A, GPU_B])
		->and($snapshot->has('nvidia/' . GPU_B))->toBeTrue()
		->and($snapshot->has('nvidia/GPU-00000000-0000-4000-8000-0000000000ff'))->toBeFalse();
});
