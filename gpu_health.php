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
 * GPU Health (GPU View realm). Parses on PHP 8.0; gpu_page_start()
 * refuses an unsupported runtime before any plugin class loads. The page
 * reads stored records only and never contacts a node.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/gpu/setup.php');

if (!gpu_page_start()) {
	exit;
}

$gpu_page = new \Somethingwithproof\Gpu\HealthPage(
	gpu_nodes(),
	gpu_gpus(),
	new \Somethingwithproof\Gpu\CollectionRepository(false),
	new \Somethingwithproof\Gpu\ObservationRepository(false),
	function ($host_id) {
		return is_device_allowed($host_id);
	},
	Closure::fromCallable('gpu_in_maintenance'),
	new \Somethingwithproof\Gpu\SystemClock(),
	max(10, (int) read_config_option('poller_interval')),
	gpu_settings()->staleAfter
);

top_header();
$gpu_page->render();
bottom_footer();
