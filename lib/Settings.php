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
 * Plugin settings, read once per request from Cacti's settings table
 * (REQ-UI-003). Collection settings arrive with the collector.
 */
final readonly class Settings {
	public const NAMES = [
		'gpu_absent_after',
		'gpu_uninstall_keep_tables',
	];

	public const DEFAULT_ABSENT_AFTER = 3;
	public const MAX_ABSENT_AFTER     = 100;

	public function __construct(
		public int $absentAfter,
		public bool $keepTablesOnUninstall,
	) {
	}

	/**
	 * A setting that was never saved reads as its default. Keeping tables is
	 * the default, so an uninstall drops node and GPU records only after an
	 * administrator has cleared the box (REQ-LIFE-004).
	 *
	 * @param array<string, mixed> $row setting name => stored value, absent when never saved
	 */
	public static function fromRow(array $row): self {
		return new self(
			absentAfter: min(Row::int($row, 'gpu_absent_after', self::DEFAULT_ABSENT_AFTER, 1), self::MAX_ABSENT_AFTER),
			keepTablesOnUninstall: array_key_exists('gpu_uninstall_keep_tables', $row) ? Row::bool($row, 'gpu_uninstall_keep_tables') : true,
		);
	}

	/**
	 * Settings tab definition for the config_settings hook.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return [
			'gpu_header_identity' => [
				'friendly_name' => __('GPU Identity', 'gpu'),
				'method'        => 'spacer',
			],
			'gpu_absent_after' => [
				'friendly_name' => __('Mark Absent After', 'gpu'),
				'description'   => __('Consecutive successful collections that must miss a GPU before it is marked absent, from 1 to 100. A failed collection, or one that reports no GPUs, never counts. Absent GPUs keep their records and history and are never deleted automatically.', 'gpu'),
				'method'        => 'textbox',
				'default'       => (string) self::DEFAULT_ABSENT_AFTER,
				'filter'        => FILTER_VALIDATE_INT,
				'options'       => ['options' => ['min_range' => 1, 'max_range' => self::MAX_ABSENT_AFTER]],
				'max_length'    => '3',
				'size'          => '3',
			],
			'gpu_header_uninstall' => [
				'friendly_name' => __('Uninstall', 'gpu'),
				'method'        => 'spacer',
			],
			'gpu_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'gpu'),
				'description'   => __('When checked, uninstalling the plugin keeps its node and GPU records. Clear it to have the uninstall drop them; export them first from GPU Nodes. Cacti devices, data sources, graphs, and RRD files are never touched either way.', 'gpu'),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
		];
	}
}
