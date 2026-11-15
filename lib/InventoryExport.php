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
 * The export an administrator takes before an uninstall that drops the
 * plugin tables (REQ-LIFE-004): every node and GPU record, as JSON. It holds
 * UUIDs and device descriptions, so only GPU Admin can download it.
 */
final class InventoryExport {
	/**
	 * @param list<Node>      $nodes
	 * @param list<GpuRecord> $gpus
	 */
	public static function json(array $nodes, array $gpus, string $pluginVersion, int $now): string {
		$document = [
			'plugin'      => 'gpu',
			'version'     => $pluginVersion,
			'exported_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
			'nodes'       => array_map(static fn (Node $node): array => [
				'id'          => $node->id,
				'host_id'     => $node->hostId,
				'description' => $node->description,
				'admin_state' => $node->adminState->value,
				'created_at'  => $node->createdAt,
				'updated_at'  => $node->updatedAt,
			], $nodes),
			'gpus'        => array_map(static fn (GpuRecord $gpu): array => ['id' => $gpu->id] + $gpu->toRow(), $gpus),
		];

		return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
	}
}
