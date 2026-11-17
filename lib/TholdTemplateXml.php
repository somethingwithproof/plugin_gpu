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
 * Default Thold threshold templates for the GPU data source (REQ-ALERT-001,
 * ADR 0002), in the XML that Thold's Threshold Templates import reads.
 *
 * The plugin never loads Thold. An operator imports templates/
 * gpu_thold_templates.xml on Thold's Threshold Templates page and links the
 * templates to the GPU Node device template; Thold then creates thresholds
 * for each GPU's data source. docs/alerting.md describes the steps.
 *
 * Each template names only columns of Thold 1.8.2's thold_template table,
 * because its import refuses a template with any column the table lacks.
 * Every threshold is a high threshold on a gauge: no template uses a
 * counter, so Thold's rate calculation never sees an unknown value.
 *
 * Not templated, on purpose: the software power cap (normal under load),
 * Idle and the other mask bits without an indicator (REQ-THR-002), the
 * volatile ECC totals (they fall at driver load, so a threshold on them
 * clears without a fix), and new corrected ECC errors (occasional ones are
 * normal, and no default rate is known).
 */
final class TholdTemplateXml {
	public const FILE = 'gpu_thold_templates.xml';

	// Thold's data_type 0 compares the stored gauge value directly.
	private const HIGH_LOW   = 0;
	private const EXACT      = 0;

	/**
	 * name => [data source item, high threshold, fail trigger in polls, ''].
	 *
	 * A fail trigger counts Cacti polls; the collector reads each node once
	 * per poller cycle, so one poll is one collection interval
	 * (REQ-ALERT-004).
	 *
	 * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
	 */
	public static function templates(): array {
		return [
			'GPU - Temperature High'           => ['temp_gpu', '85', 2, ''],
			'GPU - Power Above Limit'          => ['power_pct', '100', 2, ''],
			'GPU - Software Thermal Slowdown'  => ['thr_sw_thermal', '0.5', 1, ''],
			'GPU - Hardware Thermal Slowdown'  => ['thr_hw_thermal', '0.5', 1, ''],
			'GPU - Hardware Power Brake'       => ['thr_hw_power_brake', '0.5', 1, ''],
			'GPU - Hardware Slowdown'          => ['thr_hw_slowdown', '0.5', 1, ''],
			'GPU - New Uncorrected ECC Errors' => ['ecc_uncorr_new', '0.5', 1, ''],
			'GPU - Page Retirement Pending'    => ['retired_pending', '0.5', 1, ''],
			// Three default poller cycles: age stays numeric while a sample
			// exists, so this fires when collection stops (REQ-ALERT-002).
			'GPU - Sample Too Old'             => ['age', '900', 1, ''],
		];
	}

	/**
	 * The Thold template hash: 32 hex digits, fixed per template name.
	 */
	public static function hash(string $name): string {
		return md5('plugin_gpu:thold_template:' . $name);
	}

	/**
	 * The file Thold's import reads, in the shape Thold's own export writes.
	 */
	public static function xml(): string {
		$out      = "<templates>\n";
		$sequence = 1;

		foreach (self::templates() as $name => [$item, $high, $trigger]) {
			$metric = MetricModel::find($item);

			if ($metric === null) {
				throw new \LogicException("No model field $item");
			}

			$columns = [
				'hash'                 => self::hash($name),
				'name'                 => $name,
				'data_template_id'     => substr(TemplateXml::hash('01', TemplateXml::DATA_TEMPLATE), -32),
				'data_template_name'   => TemplateXml::DATA_TEMPLATE,
				'data_source_id'       => substr(TemplateXml::hash('08', $item), -32),
				'data_source_name'     => $item,
				'data_source_friendly' => $metric->label,
				'thold_enabled'        => 'on',
				'thold_type'           => (string) self::HIGH_LOW,
				'thold_hi'             => $high,
				'thold_low'            => '',
				'thold_fail_trigger'   => (string) $trigger,
				'data_type'            => (string) self::EXACT,
				'percent_ds'           => '',
				'expression'           => '',
				'repeat_alert'         => '0',
				'restored_alert'       => '',
			];

			$out .= "<template$sequence>\n";

			foreach ($columns as $column => $value) {
				$out .= "\t<$column>" . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</$column>\n";
			}

			$out .= "</template$sequence>\n";
			$sequence++;
		}

		return $out . "</templates>\n";
	}
}
