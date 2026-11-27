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
 * A GPU identity record. Its key is vendor and UUID and never changes; every
 * other field describes the GPU's current state (REQ-ID-002) and is replaced
 * through the transitions below, which IdentityReconciler chooses.
 */
final readonly class GpuRecord {
	public function __construct(
		// 0 until the record is first stored.
		public int $id,
		public Vendor $vendor,
		public string $uuid,
		public int $nodeId,
		public ?int $index,
		public string $pciBusId,
		public string $name,
		public string $driverVersion,
		// The tool and version that last reported this GPU (REQ-ID-006).
		public string $tool,
		public string $toolVersion,
		public Presence $presence,
		public int $missingCount,
		public int $replacesGpuId,
		public int $pendingNodeId,
		public int $pendingSince,
		public int $firstSeen,
		public int $lastSeen,
		public string $migMode = '',
		public string $migInstanceId = '',
		public string $migProfile = '',
		public string $parentUuid = '',
		public string $partitionInfo = '',
	) {
	}

	/**
	 * @param array<array-key, mixed> $row a plugin_gpu_gpu row
	 */
	public static function fromRow(array $row): ?self {
		$vendor = Vendor::tryFrom(Row::string($row, 'vendor'));

		if ($vendor === null) {
			return null;
		}

		$index = $row['gpu_index'] ?? null;

		return new self(
			id: Row::int($row, 'id'),
			vendor: $vendor,
			uuid: Row::string($row, 'uuid'),
			nodeId: Row::int($row, 'node_id'),
			index: $index === null || $index === '' ? null : Row::int($row, 'gpu_index'),
			pciBusId: Row::string($row, 'pci_bus_id'),
			name: Row::string($row, 'name'),
			driverVersion: Row::string($row, 'driver_version'),
			tool: Row::string($row, 'tool'),
			toolVersion: Row::string($row, 'tool_version'),
			presence: Presence::tryFrom(Row::string($row, 'presence')) ?? Presence::Present,
			missingCount: Row::int($row, 'missing_count', 0, 0),
			replacesGpuId: Row::int($row, 'replaces_gpu_id', 0, 0),
			pendingNodeId: Row::int($row, 'pending_node_id', 0, 0),
			pendingSince: Row::int($row, 'pending_since', 0, 0),
			firstSeen: Row::int($row, 'first_seen', 0, 0),
			lastSeen: Row::int($row, 'last_seen', 0, 0),
			migMode: Row::string($row, 'mig_mode'),
			migInstanceId: Row::string($row, 'mig_instance_id'),
			migProfile: Row::string($row, 'mig_profile'),
			parentUuid: Row::string($row, 'parent_uuid'),
			partitionInfo: Row::string($row, 'partition_info'),
		);
	}

	/**
	 * Column values for every field except the id.
	 *
	 * @return array<string, int|string|null>
	 */
	public function toRow(): array {
		return [
			'vendor'          => $this->vendor->value,
			'uuid'            => $this->uuid,
			'node_id'         => $this->nodeId,
			'gpu_index'       => $this->index,
			'pci_bus_id'      => $this->pciBusId,
			'name'            => $this->name,
			'driver_version'  => $this->driverVersion,
			'tool'            => $this->tool,
			'tool_version'    => $this->toolVersion,
			'presence'        => $this->presence->value,
			'missing_count'   => $this->missingCount,
			'replaces_gpu_id' => $this->replacesGpuId,
			'pending_node_id' => $this->pendingNodeId,
			'pending_since'   => $this->pendingSince,
			'first_seen'      => $this->firstSeen,
			'last_seen'       => $this->lastSeen,
			'mig_mode'        => $this->migMode,
			'mig_instance_id' => $this->migInstanceId,
			'mig_profile'     => $this->migProfile,
			'parent_uuid'     => $this->parentUuid,
			'partition_info'  => $this->partitionInfo,
		];
	}

	public function key(): string {
		return $this->vendor->value . '/' . $this->uuid;
	}

	public function isMig(): bool {
		return $this->parentUuid !== '';
	}

	/**
	 * A UUID no record holds: a new GPU with its own history. $replacesGpuId
	 * names the record it may have replaced, but nothing is inherited from it.
	 */
	public static function discovered(GpuReport $report, GpuSnapshot $snapshot, int $nodeId, int $replacesGpuId, int $now): self {
		return new self(
			0,
			$report->vendor,
			$report->uuid,
			$nodeId,
			$report->index,
			$report->pciBusId,
			$report->name,
			$report->driverVersion,
			$snapshot->tool,
			$snapshot->toolVersion,
			Presence::Present,
			0,
			$replacesGpuId,
			0,
			0,
			$now,
			$now,
			$report->migMode,
			$report->migInstanceId,
			$report->migProfile,
			$report->parentUuid,
			$report->partitionInfo,
		);
	}

	/**
	 * Reported at its own node: attributes take the reported values, and a
	 * pending move elsewhere is dropped because the GPU is back home.
	 */
	public function seen(GpuReport $report, GpuSnapshot $snapshot, int $now): self {
		return new self(
			$this->id,
			$this->vendor,
			$this->uuid,
			$this->nodeId,
			$report->index,
			$report->pciBusId,
			$report->name,
			$report->driverVersion,
			$snapshot->tool,
			$snapshot->toolVersion,
			Presence::Present,
			0,
			$this->replacesGpuId,
			0,
			0,
			$this->firstSeen,
			$now,
			$report->migMode !== '' ? $report->migMode : $this->migMode,
			$report->migInstanceId !== '' ? $report->migInstanceId : $this->migInstanceId,
			$report->migProfile !== '' ? $report->migProfile : $this->migProfile,
			$report->parentUuid !== '' ? $report->parentUuid : $this->parentUuid,
			$report->partitionInfo !== '' ? $report->partitionInfo : $this->partitionInfo,
		);
	}

	/**
	 * Reported at a different node. The record stays with its node until an
	 * operator confirms the move (REQ-ID-004); only the pending node changes.
	 */
	public function seenElsewhere(int $nodeId, int $now): self {
		$since = $this->pendingNodeId === $nodeId ? $this->pendingSince : $now;

		return new self(
			$this->id,
			$this->vendor,
			$this->uuid,
			$this->nodeId,
			$this->index,
			$this->pciBusId,
			$this->name,
			$this->driverVersion,
			$this->tool,
			$this->toolVersion,
			$this->presence,
			$this->missingCount,
			$this->replacesGpuId,
			$nodeId,
			$since,
			$this->firstSeen,
			$this->lastSeen,
			$this->migMode,
			$this->migInstanceId,
			$this->migProfile,
			$this->parentUuid,
			$this->partitionInfo,
		);
	}

	/**
	 * Not reported by a successful collection at its node. Absent after
	 * $absentAfter misses in a row; never deleted (REQ-ID-005).
	 */
	public function missed(int $absentAfter): self {
		$missing = $this->missingCount + 1;

		return new self(
			$this->id,
			$this->vendor,
			$this->uuid,
			$this->nodeId,
			$this->index,
			$this->pciBusId,
			$this->name,
			$this->driverVersion,
			$this->tool,
			$this->toolVersion,
			$missing >= $absentAfter ? Presence::Absent : $this->presence,
			$missing,
			$this->replacesGpuId,
			$this->pendingNodeId,
			$this->pendingSince,
			$this->firstSeen,
			$this->lastSeen,
			$this->migMode,
			$this->migInstanceId,
			$this->migProfile,
			$this->parentUuid,
			$this->partitionInfo,
		);
	}

	public function possibleReplacement(): bool {
		return $this->replacesGpuId > 0;
	}

	public function movePending(): bool {
		return $this->pendingNodeId > 0;
	}
}
