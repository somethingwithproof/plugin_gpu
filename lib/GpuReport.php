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
 * One GPU as a vendor tool reported it in one collection, validated.
 *
 * Tool output is untrusted (PLAN, Security), so every field is checked or
 * cleaned here, before it can reach the database or a page. A report that
 * fails a check is refused as a whole; the caller treats the collection as
 * failed rather than guessing.
 */
final readonly class GpuReport {
	// NVML prints "GPU-" and a hyphenated 128-bit UUID in hex.
	private const NVIDIA_UUID = '/^GPU-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	// NVML MIG instance UUIDs: either a hyphenated 128-bit UUID or
	// MIG-GPU-<parent-uuid>/<gi>/<ci>.
	private const MIG_UUID = '/^MIG-(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|GPU-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/[0-9]{1,4}\/[0-9]{1,4})$/';

	// AMD amd-smi prints a 128-bit UUID (optionally prefixed with GPU- for HIP_UUID).
	private const AMD_UUID = '/^(?:gpu-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/';

	// domain:bus:device.function, hex. nvidia-smi prints an eight-digit
	// domain, DCGM and the kernel four.
	private const PCI_BUS_ID = '/^([0-9a-f]{4,8}):([0-9a-f]{2}):([0-9a-f]{2})\.([0-7])$/';

	private function __construct(
		public Vendor $vendor,
		public string $uuid,
		public ?int $index,
		public string $pciBusId,
		public string $name,
		public string $driverVersion,
		public string $migMode = '',
		public string $migInstanceId = '',
		public string $migProfile = '',
		public string $parentUuid = '',
		public string $partitionInfo = '',
	) {
	}

	/**
	 * @param ?int $index null when the tool reported the index as unavailable
	 *
	 * @throws \InvalidArgumentException naming the field, never echoing its value
	 */
	public static function create(
		Vendor $vendor,
		string $uuid,
		?int $index,
		string $pciBusId,
		string $name,
		string $driverVersion,
		string $migMode = '',
		string $partitionInfo = '',
	): self {
		return new self(
			vendor: $vendor,
			uuid: self::uuid($vendor, $uuid),
			index: $index === null || $index >= 0 ? $index : throw new \InvalidArgumentException('GPU index is negative'),
			pciBusId: self::busId($pciBusId),
			name: self::clean($name, 128),
			driverVersion: self::clean($driverVersion, 64),
			migMode: self::cleanMigMode($migMode),
			partitionInfo: self::clean($partitionInfo, 128),
		);
	}

	/**
	 * Create a validated MIG instance report (REQ-MIG-002).
	 * Keyed by its globally unique MIG UUID, never by instance number alone.
	 *
	 * @throws \InvalidArgumentException naming the field, never echoing its value
	 */
	public static function createMig(
		string $migUuid,
		string $parentUuid,
		string $migInstanceId,
		string $migProfile,
		string $pciBusId,
		string $name,
		string $driverVersion,
		?int $index = null,
	): self {
		$normalizedMig    = self::migUuid($migUuid);
		$normalizedParent = self::uuid(Vendor::Nvidia, $parentUuid);
		$cleanInstanceId  = self::clean($migInstanceId, 32);

		if ($cleanInstanceId === '') {
			throw new \InvalidArgumentException('MIG instance ID is empty');
		}

		return new self(
			vendor: Vendor::Nvidia,
			uuid: $normalizedMig,
			index: $index === null || $index >= 0 ? $index : throw new \InvalidArgumentException('GPU index is negative'),
			pciBusId: self::busId($pciBusId),
			name: self::clean($name, 128),
			driverVersion: self::clean($driverVersion, 64),
			migMode: 'enabled',
			migInstanceId: $cleanInstanceId,
			migProfile: self::clean($migProfile, 64),
			parentUuid: $normalizedParent,
		);
	}

	public function isMig(): bool {
		return $this->parentUuid !== '';
	}

	/**
	 * The identity key (REQ-ID-001). Only a whole-GPU UUID qualifies here;
	 * MIG instances must use createMig().
	 */
	private static function uuid(Vendor $vendor, string $uuid): string {
		$uuid = trim($uuid);

		if (stripos($uuid, 'MIG-') === 0) {
			throw new \InvalidArgumentException('GPU UUID is a MIG instance UUID, which is not an identity key');
		}

		if ($vendor === Vendor::Nvidia) {
			$key = 'GPU-' . strtolower(substr($uuid, 4));

			if (strncmp($uuid, 'GPU-', 4) !== 0 || preg_match(self::NVIDIA_UUID, $key) !== 1) {
				throw new \InvalidArgumentException('GPU UUID is not in the vendor format');
			}

			return $key;
		}

		// AMD UUIDs from amd-smi list --json / --csv (normalized to lowercase).
		$lower = strtolower($uuid);

		if (preg_match(self::AMD_UUID, $lower, $m) !== 1) {
			throw new \InvalidArgumentException('GPU UUID is not in the vendor format');
		}

		return $m[1];
	}

	private static function migUuid(string $uuid): string {
		$uuid = trim($uuid);

		if (strncmp($uuid, 'MIG-GPU-', 8) === 0) {
			$key = 'MIG-GPU-' . strtolower(substr($uuid, 8));
		} elseif (strncmp($uuid, 'MIG-', 4) === 0) {
			$key = 'MIG-' . strtolower(substr($uuid, 4));
		} else {
			throw new \InvalidArgumentException('MIG UUID is not in the NVIDIA MIG format');
		}

		if (preg_match(self::MIG_UUID, $key) !== 1) {
			throw new \InvalidArgumentException('MIG UUID is not in the NVIDIA MIG format');
		}

		return $key;
	}

	private static function cleanMigMode(string $mode): string {
		return match (strtolower(trim($mode))) {
			'enabled', '1', 'on', 'true'   => 'enabled',
			'disabled', '0', 'off', 'false' => 'disabled',
			default                         => '',
		};
	}

	/**
	 * Normalized to nvidia-smi's spelling, an eight-digit domain in upper
	 * case, so the same slot compares equal whichever tool reported it.
	 */
	private static function busId(string $busId): string {
		if (preg_match(self::PCI_BUS_ID, strtolower(trim($busId)), $parts) !== 1) {
			throw new \InvalidArgumentException('PCI bus ID is not domain:bus:device.function');
		}

		return strtoupper(sprintf('%08s:%s:%s.%s', $parts[1], $parts[2], $parts[3], $parts[4]));
	}

	/**
	 * Free text from a tool: control characters removed, whitespace trimmed,
	 * and cut to the column width. Escaping happens where it is printed.
	 */
	public static function clean(string $text, int $length): string {
		$text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);

		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}

		return mb_substr(trim($text), 0, $length);
	}

	public function key(): string {
		return $this->vendor->value . '/' . $this->uuid;
	}
}
