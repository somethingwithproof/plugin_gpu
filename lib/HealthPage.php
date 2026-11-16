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
 * The GPU Health page (GPU View): the measurement state of each node and
 * GPU the viewer may see (PLAN, Measurement state; REQ-HEALTH-001 to
 * REQ-HEALTH-005).
 *
 * It reads stored records only. Rendering it never runs a tool, contacts a
 * node, or waits for one (REQ-HEALTH-003, REQ-PERF-002); a static rule in
 * tests/Security keeps it that way. Every stored string is escaped where it
 * is printed, and the collection detail is bounded and stripped of control
 * characters first, although it never holds tool output (CollectionError).
 */
final class HealthPage {
	public const FILE = 'gpu_health.php';

	// The longest collection detail the page prints, in characters.
	public const DETAIL_LIMIT = 200;

	/**
	 * @param \Closure(int): bool  $deviceAllowed Cacti's device permission check for the viewer
	 * @param \Closure(int): ?bool $inMaintenance whether Maint holds a device in maintenance, null without Maint
	 * @param int                  $interval      the expected collection interval in seconds (Cacti's poller interval)
	 */
	public function __construct(
		private readonly NodeRepository $nodes,
		private readonly GpuRepository $gpus,
		private readonly CollectionRepository $collections,
		private readonly ObservationRepository $observations,
		private readonly \Closure $deviceAllowed,
		private readonly \Closure $inMaintenance,
		private readonly Clock $clock,
		private readonly int $interval,
		private readonly int $staleAfter,
	) {
	}

	public function render(): void {
		$nodes = [];

		foreach ($this->nodes->all() as $node) {
			if (($this->deviceAllowed)($node->hostId)) {
				$nodes[$node->id] = $node;
			}
		}

		$retired = array_flip($this->gpus->retiredIds());
		$byNode  = [];

		foreach ($this->gpus->all() as $gpu) {
			if (isset($nodes[$gpu->nodeId]) && !isset($retired[$gpu->id])) {
				$byNode[$gpu->nodeId][] = $gpu;
			}
		}

		$samples = $nodes === [] ? [] : $this->observations->summaries();

		$headers = [__('Node', 'gpu'), __('Admin State', 'gpu'), __('Freshness', 'gpu'), __('Last Collection', 'gpu'), __('Maintenance', 'gpu'), __('Backend', 'gpu'), __('Tool', 'gpu'), __('Driver', 'gpu'), __('GPUs', 'gpu')];

		html_start_box(__('GPU Node Health', 'gpu'), '100%', '', '3', 'center', '');
		print $this->header($headers);

		foreach ($nodes as $node) {
			print $this->line($this->nodeRow($node, $this->collections->find($node->id), $byNode[$node->id] ?? [], $samples));
		}

		if ($nodes === []) {
			print $this->empty(count($headers), __('No GPU nodes you may see.', 'gpu'));
		}

		html_end_box();

		$headers = [__('Node', 'gpu'), __('Index', 'gpu'), __('Name', 'gpu'), __('UUID', 'gpu'), __('State', 'gpu'), __('Sample', 'gpu'), __('ECC', 'gpu'), __('Clock Event Reasons', 'gpu')];

		html_start_box(__('GPU Health', 'gpu'), '100%', '', '3', 'center', '');
		print $this->header($headers);

		$shown = 0;

		foreach ($byNode as $nodeId => $gpus) {
			foreach ($gpus as $gpu) {
				print $this->line($this->gpuRow($gpu, $nodes[$nodeId], $samples[$gpu->id] ?? null));
				$shown++;
			}
		}

		if ($shown === 0) {
			print $this->empty(count($headers), __('No GPUs recorded yet. GPUs appear here once a node\'s collection reports them.', 'gpu'));
		}

		html_end_box();
	}

	/**
	 * One node's cells, as plain text.
	 *
	 * @param list<GpuRecord>                    $gpus    the node's GPU records, retired ones left out
	 * @param array<int, array<string, mixed>>   $samples ObservationRepository::summaries()
	 * @return list<string>
	 */
	public function nodeRow(Node $node, ?CollectionStatus $status, array $gpus, array $samples): array {
		$present  = 0;
		$replaced = 0;
		$backends = [];
		$newest   = $status === null ? 0 : $status->observedAt;

		foreach ($gpus as $gpu) {
			$present  += $gpu->presence === Presence::Present ? 1 : 0;
			$replaced += $gpu->possibleReplacement() ? 1 : 0;
			$sample    = $samples[$gpu->id] ?? null;

			if ($sample !== null) {
				$backends[] = Row::string($sample, 'backend');
				$newest     = max($newest, Row::int($sample, 'observed_at', 0, 0));
			}
		}

		$maintenance = ($this->inMaintenance)($node->hostId);

		return [
			$node->description,
			$node->enabled() ? __('Enabled', 'gpu') : __('Disabled', 'gpu'),
			$this->freshness($newest),
			$this->collection($status),
			$maintenance === null ? __('Maint not installed', 'gpu') : ($maintenance ? __('In maintenance', 'gpu') : __('Not in maintenance', 'gpu')),
			self::distinct($backends),
			self::distinct(array_map(static fn (GpuRecord $gpu): string => trim($gpu->tool . ' ' . $gpu->toolVersion), $gpus)),
			self::distinct(array_map(static fn (GpuRecord $gpu): string => $gpu->driverVersion, $gpus)),
			__('%d present, %d absent, %d possible replacements', $present, count($gpus) - $present, $replaced, 'gpu'),
		];
	}

	/**
	 * One GPU's cells, as plain text.
	 *
	 * @param array<string, mixed>|null $sample its row of ObservationRepository::summaries()
	 * @return list<string>
	 */
	public function gpuRow(GpuRecord $gpu, Node $node, ?array $sample): array {
		$state = [$gpu->presence === Presence::Present ? __('Present', 'gpu') : __('Absent', 'gpu')];

		if ($gpu->possibleReplacement()) {
			$state[] = __('possible replacement of GPU record %d', $gpu->replacesGpuId, 'gpu');
		}

		if ($sample === null) {
			return [$node->description, self::index($gpu), $gpu->name, $gpu->uuid, implode('; ', $state), FreshnessState::Never->label(), '-', '-'];
		}

		$ecc  = EccMode::tryFrom(Row::string($sample, 'ecc_mode')) ?? EccMode::Unknown;
		$mask = $sample['thr_mask'] ?? null;

		return [
			$node->description,
			self::index($gpu),
			$gpu->name,
			$gpu->uuid,
			implode('; ', $state),
			$this->freshness(Row::int($sample, 'observed_at', 0, 0)),
			$ecc === EccMode::Enabled ? __('Enabled', 'gpu') : $ecc->reason(),
			ThrottleMask::describe($mask === null || $mask === '' ? null : Row::int($sample, 'thr_mask', 0, 0)),
		];
	}

	/**
	 * How the node's latest collection ended (REQ-HEALTH-002): one of
	 * completed, tool error, permission denied, timed out, or unknown, then
	 * the state and its bounded detail.
	 */
	public function collection(?CollectionStatus $status): string {
		if ($status === null || $status->state === null) {
			return __('Unknown: not collected yet', 'gpu');
		}

		$outcome = $status->state->outcome();
		$label   = $status->state->label();
		$text    = $outcome === $label ? $outcome : $outcome . ': ' . $label;

		if ($status->succeeded()) {
			$text .= ', ' . __('%d GPUs', $status->gpus, 'gpu');
		} elseif ($status->detail !== '') {
			$text .= ': ' . self::detail($status->detail);
		}

		if ($status->finishedAt > 0) {
			$text .= ' (' . self::ago(max(0, $this->clock->now() - $status->finishedAt)) . ')';
		}

		if (!$status->succeeded() && $status->lastOkAt > 0) {
			$text .= '; ' . __('last completed %s', self::ago(max(0, $this->clock->now() - $status->lastOkAt)), 'gpu');
		}

		// A run that finds the node held by another run skips it and records
		// nothing else, so this count is the only trace (REQ-PERF-003).
		if ($status->skipped > 0) {
			$text .= '; ' . __('%d later runs skipped while another run held the node', $status->skipped, 'gpu');
		}

		return $text;
	}

	/**
	 * Detail text made safe to print: control characters become spaces and
	 * the text is cut at DETAIL_LIMIT characters.
	 */
	public static function detail(string $text): string {
		$text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

		return mb_strlen($text) > self::DETAIL_LIMIT ? mb_substr($text, 0, self::DETAIL_LIMIT - 3) . '...' : $text;
	}

	private function freshness(int $observedAt): string {
		$state = FreshnessState::of($observedAt, $this->clock->now(), $this->interval, $this->staleAfter);

		return $state === FreshnessState::Never || $state === FreshnessState::Ahead ? $state->label() : $state->label() . ' (' . self::ago(max(0, $this->clock->now() - $observedAt)) . ')';
	}

	private static function ago(int $seconds): string {
		return match (true) {
			$seconds < 120    => __('%d seconds ago', $seconds, 'gpu'),
			$seconds < 7200   => __('%d minutes ago', intdiv($seconds, 60), 'gpu'),
			$seconds < 172800 => __('%d hours ago', intdiv($seconds, 3600), 'gpu'),
			default           => __('%d days ago', intdiv($seconds, 86400), 'gpu'),
		};
	}

	private static function index(GpuRecord $gpu): string {
		return $gpu->index === null ? '-' : (string) $gpu->index;
	}

	/**
	 * @param list<string> $values
	 */
	private static function distinct(array $values): string {
		$values = array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));

		return $values === [] ? '-' : implode(', ', $values);
	}

	/**
	 * @param list<string> $cells
	 */
	private function header(array $cells): string {
		return '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', $cells)) . '</th></tr>';
	}

	/**
	 * @param list<string> $cells
	 */
	private function line(array $cells): string {
		return '<tr class="tableRow"><td>' . implode('</td><td>', array_map('html_escape', $cells)) . '</td></tr>';
	}

	private function empty(int $columns, string $text): string {
		return '<tr><td colspan="' . $columns . '"><em>' . html_escape($text) . '</em></td></tr>';
	}
}
