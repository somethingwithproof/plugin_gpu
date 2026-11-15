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
 * The GPU data query's script server input (REQ-SS-001 to REQ-SS-004):
 * index, num_indexes, query, and get, as resource/script_server/
 * plugin_gpu.xml defines them. It reads only the plugin's cache.
 *
 * Arguments come from Cacti's poller, but are still checked: the device id
 * must be a number and an index must be a GPU identity key. A bad argument
 * or a failed read prints an empty listing or U, never 0.
 */
final class ScriptServer {
	public function __construct(
		private readonly CacheRepository $cache,
		private readonly Clock $clock,
		private readonly int $staleAfter,
	) {
	}

	public function call(string $hostId, string $command, string $field = '', string $index = ''): string {
		if (preg_match('/^[1-9][0-9]{0,9}$/', $hostId) !== 1) {
			return $command === 'get' ? 'U' : '';
		}

		return match ($command) {
			'index'       => $this->index((int) $hostId),
			'num_indexes' => $this->count((int) $hostId),
			'query'       => $this->query((int) $hostId, $field),
			'get'         => $this->get((int) $hostId, $field, $index),
			default       => '',
		};
	}

	private function index(int $hostId): string {
		return implode("\n", array_map(self::key(...), $this->cache->listed($hostId) ?? []));
	}

	private function count(int $hostId): string {
		$rows = $this->cache->listed($hostId);

		return $rows === null ? '' : (string) count($rows);
	}

	private function query(int $hostId, string $field): string {
		$input = self::input($field);

		if ($input === null && MetricModel::find($field) === null) {
			return '';
		}

		$rows = $input === null ? $this->cache->samples($hostId) : $this->cache->listed($hostId);
		$now  = $this->clock->now();
		$out  = [];

		foreach ($rows ?? [] as $row) {
			$value = $input === null ? Freshness::format(Freshness::value($field, $row, $now, $this->staleAfter)) : self::text($row, $input);
			$out[] = self::key($row) . '!' . $value;
		}

		return implode("\n", $out);
	}

	private function get(int $hostId, string $field, string $index): string {
		if (MetricModel::find($field) === null) {
			$input = self::input($field);

			if ($input === null) {
				return 'U';
			}

			foreach ($this->cache->listed($hostId) ?? [] as $row) {
				if (self::key($row) === $index) {
					return self::text($row, $input);
				}
			}

			return 'U';
		}

		$parts  = explode('/', $index, 2);
		$vendor = Vendor::tryFrom($parts[0]);

		if ($vendor === null || count($parts) !== 2 || preg_match('/^[A-Za-z0-9-]{1,96}$/', $parts[1]) !== 1) {
			return 'U';
		}

		$row = $this->cache->sample($hostId, $vendor, $parts[1]);

		return $row === null ? 'U' : Freshness::format(Freshness::value($field, $row, $this->clock->now(), $this->staleAfter));
	}

	/**
	 * The record column an input field's query name reads, or null.
	 */
	private static function input(string $queryName): ?string {
		return match ($queryName) {
			'index'      => 'key',
			'gpu_index'  => 'gpu_index',
			'name'       => 'name',
			'pci_bus_id' => 'pci_bus_id',
			default      => null,
		};
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private static function key(array $row): string {
		return Row::string($row, 'vendor') . '/' . Row::string($row, 'uuid');
	}

	/**
	 * An input field's text, on one line, so it cannot add a row to the
	 * listing Cacti parses.
	 *
	 * @param array<array-key, mixed> $row
	 */
	private static function text(array $row, string $column): string {
		$text = $column === 'key' ? self::key($row) : Row::string($row, $column);

		return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
	}
}
