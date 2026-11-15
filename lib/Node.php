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
 * A Cacti device registered as a GPU node. The device itself stays Cacti's;
 * the plugin only refers to it by id.
 */
final readonly class Node {
	public function __construct(
		public int $id,
		public int $hostId,
		public AdminState $adminState,
		public string $description,
		// False once the Cacti device has been deleted; the node row stays
		// so its GPU records keep their owner.
		public bool $deviceExists,
		public int $createdAt,
		public int $updatedAt,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row a plugin_gpu_node row, left joined to host as
	 *                                     description and device_id
	 */
	public static function fromRow(array $row): self {
		return new self(
			id: Row::int($row, 'id'),
			hostId: Row::int($row, 'host_id'),
			adminState: AdminState::tryFrom(Row::string($row, 'admin_state')) ?? AdminState::Disabled,
			description: Row::string($row, 'description'),
			deviceExists: Row::int($row, 'device_id') > 0,
			createdAt: Row::int($row, 'created_at'),
			updatedAt: Row::int($row, 'updated_at'),
		);
	}

	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}
}
