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

use Somethingwithproof\Gpu\OperatorPage;

chdir('../../');
include('./include/auth.php');
include_once('./plugins/gpu/setup.php');

if (!gpu_page_start()) {
	exit;
}
require_once('./plugins/gpu/include/operator.php');

// gpu_page_start() has checked the session and the Operator realm.
$user    = is_numeric($_SESSION['sess_user_id'] ?? null) ? (int) $_SESSION['sess_user_id'] : 0;
$action  = (string) get_nfilter_request_var('action');
$session = $_SESSION['gpu_triage'] ?? [];
$session = is_array($session) ? $session : [];

try {
	// Re-read on POST too: stale forms never retain revoked device access.
	$rows = gpu_operator_rows();
	$now  = time();

	if ($action !== '' && $action !== 'default') {
		$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '';
		$status = OperatorPage::actionStatus($user > 0, $action, $method, $method === 'POST' && csrf_check(false), ['acknowledge']);

		if ($status !== 200) {
			http_response_code($status);

			if ($status === 405) {
				header('Allow: POST');
			}
			exit;
		}
		$id  = (string) get_nfilter_request_var('id');
		$row = null;

		foreach ($rows as $candidate) {
			if ($candidate['id'] === $id) {
				$row = $candidate;

				break;
			}
		}

		if ($row === null) {
			http_response_code(403);
			exit;
		}
		$fingerprint = (string) get_nfilter_request_var('fingerprint');

		if (!hash_equals(OperatorPage::fingerprint($row), $fingerprint)) {
			http_response_code(409);
			exit;
		}

		if ($action === 'acknowledge') {
			if (!OperatorPage::acknowledge($session, $row, $fingerprint, $user, $now)) {
				http_response_code(409);
				exit;
			}
			$_SESSION['gpu_triage'] = $session;
		}
		cacti_log('Operator ' . $action . ' by user ' . $user . ' on object ' . hash('sha256', $id), false, 'GPU');
		header('Location: gpu_operator.php');
		exit;
	}
	top_header();
	print '<p><a href="gpu_inventory.php">' . html_escape(__('Monitoring view', 'gpu')) . '</a></p>';
	OperatorPage::render($rows, $session, $user, $now, []);
	bottom_footer();
} catch (Throwable $error) {
	cacti_log('Operator page failed (' . get_class($error) . ')', false, 'GPU');
	http_response_code(503);
	top_header();
	print '<p class="textError">' . html_escape(__('Observations could not be read. Ask an administrator to check the plugin log.', 'gpu')) . '</p>';
	bottom_footer();
}
