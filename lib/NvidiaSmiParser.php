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

namespace Somethingwithproof\Gpu;

/**
 * Parses the two nvidia-smi extend entries (REQ-NVSMI-001 to REQ-NVSMI-006,
 * REQ-EXEC-006, REQ-EXEC-007).
 *
 * The metrics entry prints one line per GPU with the fields of
 * MetricModel::nvidiaFields(), in that order. It holds no free text, so a
 * line with more or fewer fields than requested is rejected. The names entry
 * prints "uuid, name"; the name is everything after the first separator, so
 * a comma in a product name never shifts a field.
 *
 * Output is untrusted. Anything that is not exactly what was requested, such
 * as stderr text that snmpd merged into the output, a nonzero exit status, or
 * output cut at snmpd's limit, fails the whole collection: no value is
 * stored and no GPU is marked absent.
 */
final class NvidiaSmiParser {
	public const BACKEND = 'nvidia-smi';

	// Not a value: what nvidia-smi prints for a field it cannot report.
	private const NUMBER = '/^-?[0-9]+(\.[0-9]+)?$/';

	// nvidia-smi's timestamp, "YYYY/MM/DD HH:MM:SS.mmm". It carries no zone,
	// so the documented extend line runs the tool with TZ=UTC.
	private const TIMESTAMP = '/^[0-9]{4}\/[0-9]{2}\/[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?$/';

	/**
	 * @throws CollectionError
	 */
	public function parse(ExtendOutput $metrics, ExtendOutput $names): Collection {
		$this->check($metrics, 'GPU metrics');
		$this->check($names, 'GPU names');

		$fields   = MetricModel::nvidiaFields();
		$position = array_flip($fields);
		$labels   = $this->names($names);
		$reports  = [];
		$readings = [];
		$version  = '';

		foreach ($this->lines($metrics) as $number => $line) {
			$values = array_map('trim', explode(',', $line));

			if (count($values) !== count($fields)) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('GPU metrics line %d has %d fields where %d were requested', $number + 1, count($values), count($fields)), 1);
			}

			$observed = $this->timestamp($values[$position['timestamp']]);

			if ($observed === null) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('GPU metrics line %d has no valid sample time', $number + 1), 1);
			}

			try {
				$uuid   = $values[$position['uuid']];
				$report = GpuReport::create(
					Vendor::Nvidia,
					$uuid,
					$this->index($values[$position['index']]),
					$values[$position['pci.bus_id']],
					$labels[strtolower($uuid)] ?? '',
					$values[$position['driver_version']],
				);
			} catch (\InvalidArgumentException $e) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('GPU metrics line %d: %s', $number + 1, $e->getMessage()), 1);
			}

			$mask = ThrottleMask::parse($values[$position[MetricModel::MASK_FIELD]]);
			$read = ThrottleMask::indicators($mask);

			foreach (MetricModel::all() as $metric) {
				if ($metric->nvidiaField === null) {
					continue;
				}

				$raw = $values[$position[$metric->nvidiaField]];

				$read[$metric->name] = $metric->kind === Metric::INDICATOR
					? $this->flag($raw)
					: Metric::scale($this->number($raw), $metric->nvidiaScale);
			}

			$version    = $version === '' ? $report->driverVersion : $version;
			$reports[]  = $report;
			$readings[] = new Reading($report, $observed, $read, $mask);
		}

		try {
			$snapshot = GpuSnapshot::create(self::BACKEND, $version, $reports);
		} catch (\InvalidArgumentException $e) {
			throw new CollectionError(ExecutionState::InvalidOutput, $e->getMessage());
		}

		return new Collection($snapshot, $readings, self::BACKEND);
	}

	private function check(ExtendOutput $output, string $what): void {
		if ($output->result !== 0) {
			throw new CollectionError(ExecutionState::fromExitStatus($output->result), sprintf('The %s command exited with status %d', $what, $output->result));
		}

		if ($output->truncated()) {
			throw new CollectionError(ExecutionState::Truncated, sprintf('The %s output was cut short or reached the SNMP agent\'s limit', $what));
		}
	}

	/**
	 * @return list<string> the output lines; a trailing empty line is dropped
	 */
	private function lines(ExtendOutput $output): array {
		$lines = $output->lines;

		if ($lines !== [] && trim((string) end($lines)) === '') {
			array_pop($lines);
		}

		return $lines;
	}

	/**
	 * @return array<string, string> lower-cased UUID => name
	 */
	private function names(ExtendOutput $names): array {
		$out = [];

		foreach ($this->lines($names) as $number => $line) {
			$parts = explode(',', $line, 2);

			if (count($parts) !== 2 || preg_match('/^GPU-[0-9a-f-]{36}$/i', trim($parts[0])) !== 1) {
				throw new CollectionError(ExecutionState::InvalidOutput, sprintf('GPU names line %d is not a UUID and a name', $number + 1), 1);
			}

			$out[strtolower(trim($parts[0]))] = trim($parts[1]);
		}

		return $out;
	}

	private function timestamp(string $raw): ?int {
		if (preg_match(self::TIMESTAMP, $raw) !== 1) {
			return null;
		}

		$time   = \DateTimeImmutable::createFromFormat('!Y/m/d H:i:s', substr($raw, 0, 19), new \DateTimeZone('UTC'));
		$errors = \DateTimeImmutable::getLastErrors();

		// A date such as 2026/02/30 parses with a warning and rolls over.
		if ($time === false || ($errors !== false && $errors['warning_count'] > 0)) {
			return null;
		}

		return $time->getTimestamp();
	}

	private function index(string $raw): ?int {
		if (preg_match('/^-?[0-9]+$/', $raw) === 1) {
			return (int) $raw;
		}

		return null;
	}

	private function number(string $raw): ?float {
		return preg_match(self::NUMBER, $raw) === 1 ? (float) $raw : null;
	}

	private function flag(string $raw): ?float {
		return match (strtolower($raw)) {
			'yes', '1' => 1.0,
			'no', '0'  => 0.0,
			default    => null,
		};
	}
}
