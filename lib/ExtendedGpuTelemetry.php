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
 * Strongly typed view over extended enterprise GPU telemetry carried in
 * Reading::$vendorExtensions.
 *
 * Keeping these fields out of MetricModel::all() preserves the common Cacti
 * RRD data template and docs/metrics.md contract while giving collectors,
 * analyzers, and ecosystem consumers typed access to interconnect, HBM
 * thermal, Tensor Core / SM occupancy, XID fault, and cooling signals.
 * Every null value means unknown, never zero.
 */
final readonly class ExtendedGpuTelemetry {
	public const KEY_NVLINK_TX_BYTES       = 'nvlink_tx_bytes';
	public const KEY_NVLINK_RX_BYTES       = 'nvlink_rx_bytes';
	public const KEY_NVLINK_BANDWIDTH      = 'nvlink_bandwidth_total';
	public const KEY_NVLINK_CRC_ERRORS     = 'nvlink_crc_errors';
	public const KEY_NVLINK_REPLAY_ERRORS  = 'nvlink_replay_errors';
	public const KEY_PCIE_TX_BYTES         = 'pcie_tx_bytes';
	public const KEY_PCIE_RX_BYTES         = 'pcie_rx_bytes';
	public const KEY_PCIE_REPLAY_ERRORS    = 'pcie_replay_errors';
	public const KEY_TEMP_MEMORY           = 'temp_memory';
	public const KEY_TEMP_HOTSPOT          = 'temp_hotspot';
	public const KEY_SM_ACTIVE             = 'sm_active';
	public const KEY_SM_OCCUPANCY          = 'sm_occupancy';
	public const KEY_TENSOR_ACTIVE         = 'tensor_active';
	public const KEY_LAST_XID              = 'last_xid';
	public const KEY_XID_ERROR_COUNT       = 'xid_error_count';
	public const KEY_FAN_SPEED_PCT         = 'fan_speed_pct';

	/**
	 * @param ?float $nvlinkTxBytes        NVLink or XGMI transmitted bytes
	 * @param ?float $nvlinkRxBytes        NVLink or XGMI received bytes
	 * @param ?float $nvlinkBandwidthTotal aggregate NVLink bandwidth counter
	 * @param ?float $nvlinkCrcErrors      NVLink or XGMI CRC flit/data error count
	 * @param ?float $nvlinkReplayErrors   NVLink or XGMI replay/recovery error count
	 * @param ?float $pcieTxBytes          PCIe transmitted bytes or throughput
	 * @param ?float $pcieRxBytes          PCIe received bytes or throughput
	 * @param ?float $pcieReplayErrors     PCIe replay counter
	 * @param ?float $tempMemory           HBM / memory temperature in degrees C (distinct from GPU die temp)
	 * @param ?float $tempHotspot          hotspot / junction temperature in degrees C
	 * @param ?float $smActive             SM active ratio scaled to percent (0..100)
	 * @param ?float $smOccupancy          SM occupancy ratio scaled to percent (0..100)
	 * @param ?float $tensorActive         Tensor Core pipe active ratio scaled to percent (0..100)
	 * @param ?int   $lastXid              most recent NVIDIA XID error code reported (0 means no error)
	 * @param ?int   $xidErrorCount        accumulated XID error count from exporter counters
	 * @param ?float $fanSpeedPct          fan speed percentage (0..100)
	 */
	public function __construct(
		public ?float $nvlinkTxBytes = null,
		public ?float $nvlinkRxBytes = null,
		public ?float $nvlinkBandwidthTotal = null,
		public ?float $nvlinkCrcErrors = null,
		public ?float $nvlinkReplayErrors = null,
		public ?float $pcieTxBytes = null,
		public ?float $pcieRxBytes = null,
		public ?float $pcieReplayErrors = null,
		public ?float $tempMemory = null,
		public ?float $tempHotspot = null,
		public ?float $smActive = null,
		public ?float $smOccupancy = null,
		public ?float $tensorActive = null,
		public ?int $lastXid = null,
		public ?int $xidErrorCount = null,
		public ?float $fanSpeedPct = null,
	) {
	}

	/**
	 * Hydrate typed extended telemetry from a Reading::$vendorExtensions map.
	 *
	 * @param array<string, ?float> $extensions raw extension key => numeric value map
	 *
	 * @return self
	 */
	public static function fromExtensions(array $extensions): self {
		$lastXid  = self::nonNegativeInt($extensions[self::KEY_LAST_XID] ?? null);
		$xidCount = self::nonNegativeInt($extensions[self::KEY_XID_ERROR_COUNT] ?? null);

		return new self(
			nvlinkTxBytes: self::finiteOrNull($extensions[self::KEY_NVLINK_TX_BYTES] ?? null),
			nvlinkRxBytes: self::finiteOrNull($extensions[self::KEY_NVLINK_RX_BYTES] ?? null),
			nvlinkBandwidthTotal: self::finiteOrNull($extensions[self::KEY_NVLINK_BANDWIDTH] ?? null),
			nvlinkCrcErrors: self::finiteOrNull($extensions[self::KEY_NVLINK_CRC_ERRORS] ?? null),
			nvlinkReplayErrors: self::finiteOrNull($extensions[self::KEY_NVLINK_REPLAY_ERRORS] ?? null),
			pcieTxBytes: self::finiteOrNull($extensions[self::KEY_PCIE_TX_BYTES] ?? null),
			pcieRxBytes: self::finiteOrNull($extensions[self::KEY_PCIE_RX_BYTES] ?? null),
			pcieReplayErrors: self::finiteOrNull($extensions[self::KEY_PCIE_REPLAY_ERRORS] ?? null),
			tempMemory: self::finiteOrNull($extensions[self::KEY_TEMP_MEMORY] ?? null),
			tempHotspot: self::finiteOrNull($extensions[self::KEY_TEMP_HOTSPOT] ?? null),
			smActive: self::finiteOrNull($extensions[self::KEY_SM_ACTIVE] ?? null),
			smOccupancy: self::finiteOrNull($extensions[self::KEY_SM_OCCUPANCY] ?? null),
			tensorActive: self::finiteOrNull($extensions[self::KEY_TENSOR_ACTIVE] ?? null),
			lastXid: $lastXid,
			xidErrorCount: $xidCount,
			fanSpeedPct: self::finiteOrNull($extensions[self::KEY_FAN_SPEED_PCT] ?? null),
		);
	}

	/**
	 * Serialize populated fields back into a Reading::$vendorExtensions map.
	 *
	 * @return array<string, ?float>
	 */
	public function toExtensions(): array {
		$all = [
			self::KEY_NVLINK_TX_BYTES      => $this->nvlinkTxBytes,
			self::KEY_NVLINK_RX_BYTES      => $this->nvlinkRxBytes,
			self::KEY_NVLINK_BANDWIDTH     => $this->nvlinkBandwidthTotal,
			self::KEY_NVLINK_CRC_ERRORS    => $this->nvlinkCrcErrors,
			self::KEY_NVLINK_REPLAY_ERRORS => $this->nvlinkReplayErrors,
			self::KEY_PCIE_TX_BYTES        => $this->pcieTxBytes,
			self::KEY_PCIE_RX_BYTES        => $this->pcieRxBytes,
			self::KEY_PCIE_REPLAY_ERRORS   => $this->pcieReplayErrors,
			self::KEY_TEMP_MEMORY          => $this->tempMemory,
			self::KEY_TEMP_HOTSPOT         => $this->tempHotspot,
			self::KEY_SM_ACTIVE            => $this->smActive,
			self::KEY_SM_OCCUPANCY         => $this->smOccupancy,
			self::KEY_TENSOR_ACTIVE        => $this->tensorActive,
			self::KEY_LAST_XID             => $this->lastXid !== null ? (float) $this->lastXid : null,
			self::KEY_XID_ERROR_COUNT      => $this->xidErrorCount !== null ? (float) $this->xidErrorCount : null,
			self::KEY_FAN_SPEED_PCT        => $this->fanSpeedPct,
		];

		$out = [];

		foreach ($all as $key => $value) {
			if ($value !== null) {
				$out[$key] = $value;
			}
		}

		return $out;
	}

	/**
	 * Whether any NVLink, XGMI, or PCIe link error counter is nonzero.
	 *
	 * @return bool
	 */
	public function hasInterconnectErrors(): bool {
		return ($this->nvlinkCrcErrors !== null && $this->nvlinkCrcErrors > 0.0)
			|| ($this->nvlinkReplayErrors !== null && $this->nvlinkReplayErrors > 0.0)
			|| ($this->pcieReplayErrors !== null && $this->pcieReplayErrors > 0.0);
	}

	/**
	 * Whether an NVIDIA XID error code or positive XID counter is present.
	 *
	 * @return bool
	 */
	public function hasXidFault(): bool {
		return ($this->lastXid !== null && $this->lastXid > 0)
			|| ($this->xidErrorCount !== null && $this->xidErrorCount > 0);
	}

	/**
	 * Whether HBM / memory temperature is at or above the given threshold.
	 *
	 * @param float $thresholdC memory temperature threshold in degrees C
	 *
	 * @return bool
	 */
	public function hasMemoryThermalPressure(float $thresholdC = 85.0): bool {
		return $this->tempMemory !== null && $this->tempMemory >= $thresholdC;
	}

	/**
	 * Return the value if it is a finite float, or null otherwise.
	 *
	 * @param ?float $value candidate measurement
	 *
	 * @return ?float
	 */
	private static function finiteOrNull(?float $value): ?float {
		return $value !== null && is_finite($value) ? $value : null;
	}

	/**
	 * Convert a finite non-negative float to an integer, or null otherwise.
	 *
	 * @param ?float $value candidate integer-valued counter
	 *
	 * @return ?int
	 */
	private static function nonNegativeInt(?float $value): ?int {
		if ($value === null || !is_finite($value) || $value < 0.0) {
			return null;
		}

		return (int) round($value);
	}
}
