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
 * Ecosystem integration triad for plugin_gpu:
 * - MCP / Abilities API (abilities_register): gpu-list-nodes, gpu-list-devices,
 *   gpu-get-health (read-only, View realm gated, device permission filtered).
 * - Doctor diagnostics (doctor_register_checks): schema, collection freshness,
 *   and identity anomalies.
 * - CasC provider registration (casc_register_providers).
 * - Remote data collector read-only agent actions (remote_agent).
 */
final class EcosystemIntegration {
	private const READ_ONLY_ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * @param \Closure(int): bool      $hasViewRealm  whether a user holds GPU View realm
	 * @param \Closure(int, int): bool $deviceAllowed whether a user may view a Cacti host_id
	 */
	public function __construct(
		private readonly Schema $schema,
		private readonly SettingsRepository $settings,
		private readonly NodeRepository $nodes,
		private readonly GpuRepository $gpus,
		private readonly CollectionRepository $collections,
		private readonly ObservationRepository $observations,
		private readonly Clock $clock,
		private readonly \Closure $hasViewRealm,
		private readonly \Closure $deviceAllowed,
		private readonly int $pollerInterval = 300,
	) {
	}

	/**
	 * Build read-only MCP / Abilities definitions for abilities_register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		$permission = fn (int $userId): bool => ($this->hasViewRealm)($userId);

		return [
			'gpu-list-nodes' => [
				'label'        => 'List GPU nodes',
				'description'  => 'List registered GPU nodes visible to the caller (read-only, no credentials).',
				'category'     => 'inventory',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'adminState' => ['type' => 'string', 'enum' => ['enabled', 'disabled']],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'nodes' => [
							'type'     => 'array',
							'maxItems' => 1000,
							'items'    => [
								'type'       => 'object',
								'properties' => [
									'id'           => ['type' => 'integer'],
									'hostId'       => ['type' => 'integer'],
									'description'  => ['type' => 'string'],
									'adminState'   => ['type' => 'string'],
									'deviceExists' => ['type' => 'boolean'],
								],
								'required'             => ['id', 'hostId', 'description', 'adminState', 'deviceExists'],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => ['nodes'],
					'additionalProperties' => false,
				],
				'annotations' => self::READ_ONLY_ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => function (int $userId, \stdClass $args): array {
					$stateFilter = isset($args->adminState) && is_string($args->adminState) ? $args->adminState : null;
					$items       = [];

					foreach ($this->nodes->all() as $node) {
						if (!($this->deviceAllowed)($userId, $node->hostId)) {
							continue;
						}

						if ($stateFilter !== null && $node->adminState->value !== $stateFilter) {
							continue;
						}

						$items[] = [
							'id'           => $node->id,
							'hostId'       => $node->hostId,
							'description'  => $node->description,
							'adminState'   => $node->adminState->value,
							'deviceExists' => $node->deviceExists,
						];
					}

					return ['nodes' => $items];
				},
			],
			'gpu-list-devices' => [
				'label'        => 'List GPU devices',
				'description'  => 'List normalized GPU and MIG instance inventory records visible to the caller (read-only).',
				'category'     => 'inventory',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'vendor' => ['type' => 'string', 'enum' => ['nvidia', 'amd']],
						'nodeId' => ['type' => 'integer', 'minimum' => 1],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'devices' => [
							'type'     => 'array',
							'maxItems' => 10000,
							'items'    => [
								'type'       => 'object',
								'properties' => [
									'id'            => ['type' => 'integer'],
									'vendor'        => ['type' => 'string'],
									'uuid'          => ['type' => 'string'],
									'nodeId'        => ['type' => 'integer'],
									'gpuIndex'      => ['type' => ['integer', 'null']],
									'pciBusId'      => ['type' => 'string'],
									'name'          => ['type' => 'string'],
									'presence'      => ['type' => 'string'],
									'migMode'       => ['type' => 'string'],
									'migInstanceId' => ['type' => 'string'],
									'migProfile'    => ['type' => 'string'],
									'parentUuid'    => ['type' => 'string'],
									'partitionInfo' => ['type' => 'string'],
								],
								'required'             => ['id', 'vendor', 'uuid', 'nodeId', 'gpuIndex', 'pciBusId', 'name', 'presence', 'migMode', 'migInstanceId', 'migProfile', 'parentUuid', 'partitionInfo'],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => ['devices'],
					'additionalProperties' => false,
				],
				'annotations' => self::READ_ONLY_ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => function (int $userId, \stdClass $args): array {
					$allowedNodes = [];

					foreach ($this->nodes->all() as $node) {
						if (($this->deviceAllowed)($userId, $node->hostId)) {
							$allowedNodes[$node->id] = true;
						}
					}

					$vendorFilter = isset($args->vendor) && is_string($args->vendor) ? $args->vendor : null;
					$nodeFilter   = isset($args->nodeId) && is_int($args->nodeId) ? $args->nodeId : null;
					$items        = [];

					foreach ($this->gpus->all() as $gpu) {
						if (!isset($allowedNodes[$gpu->nodeId])) {
							continue;
						}

						if ($vendorFilter !== null && $gpu->vendor->value !== $vendorFilter) {
							continue;
						}

						if ($nodeFilter !== null && $gpu->nodeId !== $nodeFilter) {
							continue;
						}

						$items[] = [
							'id'            => $gpu->id,
							'vendor'        => $gpu->vendor->value,
							'uuid'          => $gpu->uuid,
							'nodeId'        => $gpu->nodeId,
							'gpuIndex'      => $gpu->index,
							'pciBusId'      => $gpu->pciBusId,
							'name'          => $gpu->name,
							'presence'      => $gpu->presence->value,
							'migMode'       => $gpu->migMode,
							'migInstanceId' => $gpu->migInstanceId,
							'migProfile'    => $gpu->migProfile,
							'parentUuid'    => $gpu->parentUuid,
							'partitionInfo' => $gpu->partitionInfo,
						];
					}

					return ['devices' => $items];
				},
			],
			'gpu-get-health' => [
				'label'        => 'Get GPU health summary',
				'description'  => 'Read stored GPU collection freshness, status, and anomaly counts for permitted nodes (read-only).',
				'category'     => 'system',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'status'       => ['type' => 'string'],
						'nodesTotal'   => ['type' => 'integer'],
						'nodesHealthy' => ['type' => 'integer'],
						'nodesStale'   => ['type' => 'integer'],
						'nodesFailed'  => ['type' => 'integer'],
						'gpusPresent'  => ['type' => 'integer'],
						'gpusAbsent'   => ['type' => 'integer'],
						'migInstances' => ['type' => 'integer'],
						'pendingMoves' => ['type' => 'integer'],
					],
					'required'             => ['status', 'nodesTotal', 'nodesHealthy', 'nodesStale', 'nodesFailed', 'gpusPresent', 'gpusAbsent', 'migInstances', 'pendingMoves'],
					'additionalProperties' => false,
				],
				'annotations' => self::READ_ONLY_ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => function (int $userId): array {
					$now         = $this->clock->now();
					$settings    = $this->settings->load();
					$collections = $this->collections->all();
					$samples     = $this->observations->summaries();

					$allowedNodes = [];

					foreach ($this->nodes->all() as $node) {
						if (($this->deviceAllowed)($userId, $node->hostId)) {
							$allowedNodes[$node->id] = $node;
						}
					}

					$byNode       = [];
					$gpusPresent  = 0;
					$gpusAbsent   = 0;
					$migInstances = 0;
					$pendingMoves = 0;

					foreach ($this->gpus->all() as $gpu) {
						if (!isset($allowedNodes[$gpu->nodeId])) {
							continue;
						}

						$byNode[$gpu->nodeId][] = $gpu;
						$gpusPresent           += $gpu->presence === Presence::Present ? 1 : 0;
						$gpusAbsent            += $gpu->presence === Presence::Absent ? 1 : 0;
						$migInstances          += $gpu->isMig() ? 1 : 0;
						$pendingMoves          += $gpu->movePending() ? 1 : 0;
					}

					$nodesHealthy = 0;
					$nodesStale   = 0;
					$nodesFailed  = 0;

					foreach ($allowedNodes as $node) {
						if (!$node->enabled()) {
							continue;
						}

						$status = $collections[$node->id] ?? null;
						$newest = $status === null ? 0 : $status->observedAt;

						foreach ($byNode[$node->id] ?? [] as $gpu) {
							$sample = $samples[$gpu->id] ?? null;

							if ($sample !== null) {
								$newest = max($newest, Row::int($sample, 'observed_at', 0, 0));
							}
						}

						$freshness = FreshnessState::of($newest, $now, $this->pollerInterval, $settings->staleAfter);

						if ($status !== null && $status->state !== null && !$status->succeeded()) {
							$nodesFailed++;
						} elseif ($freshness === FreshnessState::Stale || $freshness === FreshnessState::Never || $freshness === FreshnessState::Ahead) {
							$nodesStale++;
						} else {
							$nodesHealthy++;
						}
					}

					$overall = ($nodesFailed > 0 || $nodesStale > 0) ? 'degraded' : (($pendingMoves > 0 || $gpusAbsent > 0) ? 'warning' : 'ok');

					return [
						'status'       => $overall,
						'nodesTotal'   => count($allowedNodes),
						'nodesHealthy' => $nodesHealthy,
						'nodesStale'   => $nodesStale,
						'nodesFailed'  => $nodesFailed,
						'gpusPresent'  => $gpusPresent,
						'gpusAbsent'   => $gpusAbsent,
						'migInstances' => $migInstances,
						'pendingMoves' => $pendingMoves,
					];
				},
			],
		];
	}

	/**
	 * Register abilities on an AbilitiesRegistrar object or append to an array.
	 *
	 * @param mixed $registrar
	 *
	 * @return mixed
	 */
	public function registerAbilities(mixed $registrar): mixed {
		$abilities = $this->abilities();

		if (is_object($registrar) && method_exists($registrar, 'register')) {
			foreach ($abilities as $id => $definition) {
				$registrar->register($id, $definition);
			}

			return $registrar;
		}

		if (is_array($registrar)) {
			foreach ($abilities as $id => $definition) {
				$registrar[$id] = $definition;
			}

			return $registrar;
		}

		return $abilities;
	}

	/**
	 * Diagnostic checks for Cacti Doctor (doctor_register_checks).
	 *
	 * @return list<array{id: string, plugin: string, label: string, category: string, run: \Closure(): array{status: string, message: string, details: array<string, mixed>}}>
	 */
	public function doctorChecks(): array {
		return [
			[
				'id'       => 'gpu_schema',
				'plugin'   => 'gpu',
				'label'    => 'GPU plugin schema currency',
				'category' => 'database',
				'run'      => function (): array {
					$stored  = $this->schema->storedVersion();
					$current = Schema::VERSION;

					return [
						'status'  => $stored === $current ? 'ok' : 'critical',
						'message' => $stored === $current
							? sprintf('GPU schema is current at version %d', $current)
							: sprintf('GPU schema is at version %d; expected %d', $stored, $current),
						'details' => ['stored_version' => $stored, 'expected_version' => $current],
					];
				},
			],
			[
				'id'       => 'gpu_collection_freshness',
				'plugin'   => 'gpu',
				'label'    => 'GPU node collection freshness',
				'category' => 'polling',
				'run'      => function (): array {
					$health  = $this->casc()->health();
					$summary = $health['summary'];

					$status = ($summary['nodes_failed'] > 0 || $summary['nodes_stale'] > 0) ? 'warning' : 'ok';

					return [
						'status'  => $status,
						'message' => sprintf('%d healthy, %d stale, %d failed across %d enabled GPU nodes', $summary['nodes_healthy'], $summary['nodes_stale'], $summary['nodes_failed'], $summary['nodes_enabled']),
						'details' => $summary,
					];
				},
			],
			[
				'id'       => 'gpu_identity_anomalies',
				'plugin'   => 'gpu',
				'label'    => 'GPU identity anomalies and MIG reconfigurations',
				'category' => 'inventory',
				'run'      => function (): array {
					$absent  = 0;
					$pending = 0;
					$mig     = 0;

					foreach ($this->gpus->all() as $gpu) {
						$absent  += $gpu->presence === Presence::Absent ? 1 : 0;
						$pending += $gpu->movePending() ? 1 : 0;
						$mig     += $gpu->isMig() ? 1 : 0;
					}

					$status = ($pending > 0 || $absent > 0) ? 'warning' : 'ok';

					return [
						'status'  => $status,
						'message' => sprintf('%d absent GPUs, %d pending node moves, %d MIG instances tracked', $absent, $pending, $mig),
						'details' => ['absent' => $absent, 'pending_moves' => $pending, 'mig_instances' => $mig],
					];
				},
			],
		];
	}

	/**
	 * CasC provider registration descriptor (casc_register_providers).
	 *
	 * @return array<string, mixed>
	 */
	public function cascDescriptor(): array {
		$casc = $this->casc();

		return [
			'id'             => 'gpu',
			'plugin'         => 'gpu',
			'schema_version' => CascProvider::SCHEMA_VERSION,
			'formats'        => CascProvider::FORMATS,
			'export'         => static fn (string $format = 'yaml'): string => $casc->export($format),
			'validate'       => static fn (array $desired): array => $casc->validate($desired),
			'plan'           => static fn (array $desired): array => $casc->plan($desired),
			'apply'          => static fn (array $desired, string $confirmHash): array => $casc->apply($desired, $confirmHash),
		];
	}

	/**
	 * Handle read-only remote_agent requests for inventory and health
	 * (REQ-AGENT-001, REQ-AGENT-002). Rejects unknown actions and never accepts
	 * commands, paths, or URLs.
	 *
	 * @return array<string, mixed>|null null when $action is not owned by plugin_gpu
	 */
	public function handleRemoteAgent(string $action): ?array {
		return match ($action) {
			'gpu_health'    => $this->casc()->health(),
			'gpu_inventory' => [
				'plugin' => 'gpu',
				'nodes'  => array_map(static fn (Node $n): array => [
					'id'          => $n->id,
					'host_id'     => $n->hostId,
					'admin_state' => $n->adminState->value,
				], $this->nodes->all()),
				'gpus' => array_map(static fn (GpuRecord $g): array => ['id' => $g->id] + $g->toRow(), $this->gpus->all()),
			],
			default => null,
		};
	}

	private function casc(): CascProvider {
		return new CascProvider($this->settings, $this->nodes, $this->gpus, $this->collections, $this->observations, $this->clock, $this->pollerInterval);
	}
}
