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
	) {
	}

	/**
	 * @param ?int $index null when the tool reported the index as unavailable
	 *
	 * @throws \InvalidArgumentException naming the field, never echoing its value
	 */
	public static function create(Vendor $vendor, string $uuid, ?int $index, string $pciBusId, string $name, string $driverVersion): self {
		return new self(
			vendor: $vendor,
			uuid: self::uuid($vendor, $uuid),
			index: $index === null || $index >= 0 ? $index : throw new \InvalidArgumentException('GPU index is negative'),
			pciBusId: self::busId($pciBusId),
			name: self::clean($name, 128),
			driverVersion: self::clean($driverVersion, 64),
		);
	}

	/**
	 * The identity key (REQ-ID-001). Only a whole-GPU UUID qualifies.
	 *
	 * MIG instance UUIDs are refused: NVML documents them as immutable only
	 * for one instance lifetime, and whether a recreated instance can get a
	 * UUID back is unresolved (research/mig.md). Keying on one could attach a
	 * destroyed instance's history to a new one, so MIG waits for decision #13.
	 */
	private static function uuid(Vendor $vendor, string $uuid): string {
		$uuid = trim($uuid);

		if (stripos($uuid, 'MIG-') === 0) {
			throw new \InvalidArgumentException('GPU UUID is a MIG instance UUID, which is not an identity key');
		}

		$key = match ($vendor) {
			Vendor::Nvidia => 'GPU-' . strtolower(substr($uuid, 4)),
		};

		if (strncmp($uuid, 'GPU-', 4) !== 0 || preg_match(self::NVIDIA_UUID, $key) !== 1) {
			throw new \InvalidArgumentException('GPU UUID is not in the vendor format');
		}

		return $key;
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
