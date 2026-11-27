<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Performance Profile and Algorithmic Audit

This document records the algorithmic audit and PHP runtime profiling results for `plugin_gpu` at fleet scale (10,000 to 50,000 DCGM Prometheus metric lines and 8,192 GPUs across 1,024 8-GPU nodes).

## Methodology

- Runtime: PHP 8.4.25 CLI (`-d memory_limit=1G`), measured locally on macOS arm64.
- Timing: monotonic wall-clock latency via `hrtime(true)` (one warmup call followed by 3 to 5 measured iterations; median and minimum reported in milliseconds).
- Memory: `memory_get_peak_usage(true)` with `memory_reset_peak_usage()` between benchmarks.
- Workload shapes:
  - `DcgmExporterParser` single scrape at 9,996 lines (833 GPUs x 12 series) and 49,992 lines (4,166 GPUs x 12 series, immediately under `MAX_LINES = 50000`).
  - Fleet scrape at 1,024 8-GPU nodes (8,192 GPUs total) across `DcgmExporterParser`, `AmdSmiParser`, `NvidiaSmiParser`, `ObservationRepository::store()`, `HealthPage::render()`, and `GpuClusterUtilizationAnalyzer::analyze()`.
  - `IdentityReconciler::reconcile()` replacement wave of 512 newly reported GPUs replacing 512 known records on the same fabric domain.
- Database writes and reads in repository/page benchmarks use the unit test in-memory database stub so measurements isolate PHP CPU, allocation, and SQL statement count rather than MariaDB network round-trip variance. Real SQL query counts are reported explicitly where N+1 behavior was removed.

## Algorithmic Issues Identified and Fixed

| Component | Root Cause (Before) | Complexity Change | Fix Applied |
|---|---|---|---|
| `lib/DcgmExporterParser.php` | `explode("\n", str_replace("\r\n", "\n", $body))` copied the full body and materialized up to 50,000 line strings; `parseSampleLine()` ran a backtracking regex per line; `parseLabels()` ran `preg_match()` per label and concatenated values byte by byte in PHP across every metric line even when 12+ series for one GPU shared identical labels. | O(L * C) regex + byte-loop -> O(L + U * C) streaming scan with label-block memoization (U = unique label sets) | Stream lines via `strpos($body, "\n", $offset)`, parse sample lines and labels with `strspn()`/`strcspn()`/`substr()` fast paths, and cache parsed label sets and derived entity metadata per unique raw label block while preserving duplicate-series and syntax checks. |
| `lib/GpuSnapshot.php` and `lib/IdentityReconciler.php` | `GpuSnapshot::has()` linearly scanned `$this->reports` in O(R) despite `create()` already computing `$keys`; `IdentityReconciler::replaced()` linearly scanned `$known` for each new report while calling `$snapshot->has()`. | O(R^2 * K) cubic on replacement waves -> O(R + K) linear | Retain `private readonly array $keys` on `GpuSnapshot` for O(1) `has()`; pre-index unreported replacement candidates per `(vendor, pciBusId)` once in `IdentityReconciler::reconcile()`. |
| `lib/HealthPage.php` | `render()` called `$this->collections->find($node->id)` inside the per-node loop, causing 1 query per node (N+1). | O(N) SQL queries -> O(1) batched query | Load all collection statuses once via `$this->collections->all()` and index by `$node->id` (1,028 queries -> 5 queries for 1,024 nodes). |
| `lib/ReindexRepository.php` and `lib/Poller.php` | `Poller::run()` called `$this->reindex->pending()[$node->id]` inside the per-node loop, fetching the entire `plugin_gpu_reindex` table on every polled node. | O(N^2) rows transferred -> O(N) primary-key lookups | Added `ReindexRepository::find(int $nodeId)` using `WHERE node_id = ?` primary-key lookup and wired `Poller::run()` to use it. |
| `lib/Provisioner.php` | `createGraphs()` called `$this->cacti->queryGraphs($query)` inside the per-GPU loop. | O(G) redundant queries per node -> O(1) per node | Hoisted `$this->cacti->queryGraphs($query)` once outside the GPU iteration loop. |
| `lib/MetricModel.php` and `lib/NvidiaSmiParser.php` | `MetricModel::all()` allocated 23 `Metric` objects on every call; `NvidiaSmiParser::parse()` called `MetricModel::all()` and `new DateTimeZone('UTC')` inside the per-GPU loop. | 24 object allocations per GPU -> 0 per-GPU allocations after warmup | Cached immutable `MetricModel::all()` statically, hoisted column-to-metric lookup metadata outside the line loop, and reused a static UTC `DateTimeZone` instance. |
| `lib/ObservationRepository.php` | `store()` rebuilt the column diff, placeholder list, and full `INSERT ... ON DUPLICATE KEY UPDATE` SQL string on every GPU observation write. | Repeated string/array rebuilds per GPU -> O(1) static template reuse | Cached the static column list, INSERT prefix, delta format templates, and UPDATE suffix across calls while producing byte-identical SQL. |
| `lib/AmdSmiParser.php` | `decodeList()` and `subArray()` cloned every JSON object array key by key per GPU; `unwrapNumber()` executed a regex on every string metric. | Redundant array copies + regex -> direct array access + `is_numeric()` fast path | Removed unnecessary element-by-element array copies in `decodeList()` and `subArray()` and switched `unwrapNumber()` to `is_numeric()` while adding 10 new extended telemetry fields (HBM/hotspot temp, XGMI, PCIe, fan speed). |

## Measured Before and After Benchmarks (PHP 8.4.25)

| Benchmark Workload | Before Median (ms) | After Median (ms) | Speedup | SQL Queries (Before -> After) | Notes |
|---|---:|---:|---:|---|---|
| `DcgmExporterParser::parse` (9,996 lines / 833 GPUs) | 83.58 | 18.53 | 4.51x | n/a | 10.00 MiB peak |
| `DcgmExporterParser::parse` (49,992 lines / 4,166 GPUs) | 417.32 | 101.92 | 4.09x | n/a | Near `MAX_LINES = 50000` ceiling |
| `DcgmExporterParser::parse` (1,024 x 8-GPU nodes = 8,192 GPUs) | 832.99 | 180.94 | 4.60x | n/a | Includes extended telemetry support |
| `AmdSmiParser::parseJson` (1,024 x 8-GPU MI300X nodes = 8,192 GPUs) | 131.68 | 123.16 | 1.07x | n/a | Faster even with 10 added extended metrics |
| `NvidiaSmiParser::parse` (1,024 x 8-GPU nodes = 8,192 GPUs) | 123.40 | 70.25 | 1.76x | n/a | Static `MetricModel::all()` and UTC TZ reuse |
| `IdentityReconciler::reconcile` (512 new GPUs replacing 512 known) | 38.93 | 0.81 | 48.06x | n/a | Eliminated O(R^2 * K) cubic scan |
| `ObservationRepository::store` (8,192 GPUs) | 36.20 | 10.40 | 3.48x | 8,192 -> 8,192 | Eliminated per-row SQL string compilation |
| `HealthPage::render` (1,024 nodes / 8,192 GPUs) | 54.79 | 54.01 | 1.01x CPU | 1,028 -> 5 (205.6x fewer) | Eliminated per-node N+1 DB queries |
| `GpuClusterUtilizationAnalyzer::analyze` (1,024 nodes / 8,192 GPUs) | n/a (new) | 23.16 | O(G + N) linear | 0 | 20.00 MiB peak; Philly fragmentation + idle + fault correlation |

## Operational Implications

- At 8,192 GPUs across 1,024 8-GPU nodes, total PHP parsing time for DCGM Exporter payloads drops from 832.99 ms to 180.94 ms per cycle, leaving ample headroom inside a 60-second or 300-second Cacti poller interval.
- Eliminating the N+1 query in `HealthPage::render()` keeps page database round trips constant at 5 queries regardless of fleet size (10 nodes or 1,024 nodes), preventing page-load latency growth on large multi-tenant clusters.
- Eliminating the O(N^2) `ReindexRepository::pending()` scan inside `Poller::run()` prevents poller degradation when many nodes queue simultaneous reindexes after cluster maintenance or driver upgrades.
- `GpuClusterUtilizationAnalyzer::analyze()` computes locality fragmentation, allocated-but-idle ratios, MIG profile distributions, and gang-scheduled fault correlations across 8,192 GPUs in 23.16 ms with zero database I/O.

## Limitations

- Database round-trip latency for `ObservationRepository::store()` and `HealthPage::render()` was measured against the unit-test stub rather than a networked MariaDB instance; the SQL query count reduction on `HealthPage::render()` (1,028 to 5) has an even larger wall-clock benefit over real TCP/MariaDB latency.
- `DcgmExporterParser` enforces `MAX_LINES = 50000` per scrape response to bound untrusted input memory usage; nodes exposing more than 50,000 series per `/metrics` response are intentionally refused as `ExecutionState::Truncated`.
