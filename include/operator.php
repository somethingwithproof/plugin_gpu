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

use Somethingwithproof\Gpu\CollectionRepository;

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function gpu_operator_rows(): array {
	$rows        = [];
	$collections = new CollectionRepository(false);

	foreach (gpu_nodes()->all() as $node) {
		if (!$node->deviceExists || !is_device_allowed($node->hostId)) {
			continue;
		}
		$h      = $collections->find($node->id);
		$rows[] = [
			'id' => (string) $node->id,
			'name' => $node->description,
			'state' => $node->enabled() ? ($h?->state?->label() ?? __('Never', 'gpu')) : __('Disabled', 'gpu'),
			'observed' => $h->finishedAt ?? 0,
			'interval' => max(60, (int) read_config_option('poller_interval')),
			'detail'       => __('Compare sample age, ECC and throttle state on GPU Health with the device graphs. Escalate identity changes and node configuration to an administrator.', 'gpu')
		];
	}

	return $rows;
}
