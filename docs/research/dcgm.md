# DCGM field IDs, dcgmi, and dcgm-exporter behavior

Research for [#15](https://github.com/somethingwithproof/plugin_gpu/issues/15), 2026-11-15. Question: do the DCGM field IDs and names the plan lists hold on every DCGM line the plugin will support, and what do `dcgmi` and `dcgm-exporter` print for them?

**Result.** The numeric IDs the plan uses are identical at `v3.3.9` and `v4.7.0`. What changes is the names, the scale, and the exporter's output: names were renamed (old names kept as aliases), two utilization and memory fields have new ratio and byte versions that appear only from `v4.6.0` and `v4.7.0`, and `dcgm-exporter` 4.9.0 switched its default Prometheus metric names and units to the new fields, so a scraper that matches names must handle both generations. `dcgmi discovery` and `dcgmi dmon` have no JSON or CSV switch in the source read, so a `dcgmi` backend parses a table. Output samples and `dmon` timing are live checks.

## Method

Compared `dcgmlib/dcgm_fields.h` at two tags and scanned the intervening tags for the first appearance of each new name; read `dcgmi/CommandLineParser.cpp` at `v4.7.0`; read the `dcgm-exporter` default counter files at four tags and its Go source at 4.9.0; read the DCGM feature overview and exporter reference pages (fetched 2026-11-15). Nothing was run.

## Pins

| Item | Ref | Commit |
|---|---|---|
| DCGM | `v3.3.9` | `b0ec3c624ea21e688b0d93cf9b214ae0eeb6fe52` |
| DCGM | `v4.7.0` (also `master` head on 2026-10-21) | `9fae2867e6e5264fa0fca22a07dc898d591baa21` |
| dcgm-exporter | `4.9.0` | `4e663a526c4624ad0e18ad816f09b7be2c5348a7` |
| dcgm-exporter | `4.8.4` | `fafd151148052628061a80450b4ee037a5fa0c3c` |
| dcgm-exporter | `4.4.2-4.7.1` | `b921c57eb70015d779d1e8237d609925614d7965` |
| dcgm-exporter | `3.3.9-3.6.1` | `b97b763` (abbreviated; full SHA not recorded) |

## Field table

IDs read from `dcgm_fields.h` at both DCGM tags ([v3.3.9](https://github.com/NVIDIA/DCGM/blob/b0ec3c624ea21e688b0d93cf9b214ae0eeb6fe52/dcgmlib/dcgm_fields.h), [v4.7.0](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/dcgm_fields.h)).

| Metric | ID | Name at v3.3.9 | Name at v4.7.0 | First tag with the new name | Unit or scale | Status |
|---|---|---|---|---|---|---|
| GPU UUID | 54 | `DCGM_FI_DEV_UUID` | `DCGM_FI_DEV_GPU_UUID` (old name aliased) | not scanned | string | confirmed |
| PCI bus ID | 57 | `DCGM_FI_DEV_PCI_BUSID` | `DCGM_FI_DEV_PCI_BUS_ID` (aliased) | not scanned | string | confirmed |
| MIG mode | 67 | `DCGM_FI_DEV_MIG_MODE` | same | n/a | enum | confirmed |
| SM clock | 100 | `DCGM_FI_DEV_SM_CLOCK` | same; also `..._SM_CLOCK_HERTZ` 1603 | not scanned | 100 is deprecated in favor of 1603 and its unit is not stated in the header; 1603 is "in hertz" | confirmed IDs; the MHz unit for 100 is unverified |
| Memory clock | 101 | `DCGM_FI_DEV_MEM_CLOCK` | same; also `..._MEMORY_CLOCK_HERTZ` 1604 | not scanned | 101 deprecated, unit not stated; 1604 hertz | confirmed IDs; MHz for 101 unverified |
| Clock event reasons | 112 | `DCGM_FI_DEV_CLOCK_THROTTLE_REASONS` | `DCGM_FI_DEV_CLOCKS_EVENT_REASONS` (old aliased) | v4.0.0 | bitmask | confirmed; see [clock-event-reasons.md](clock-event-reasons.md) |
| GPU temperature | 150 | `DCGM_FI_DEV_GPU_TEMP` | `DCGM_FI_DEV_GPU_TEMP_CELSIUS` (old aliased) | v4.6.0 | degrees C | confirmed |
| Power usage | 155 | `DCGM_FI_DEV_POWER_USAGE` | `DCGM_FI_DEV_BOARD_POWER_WATTS` (aliased) | v4.6.0 | watts | confirmed |
| Power limit (requested) | 160 | `DCGM_FI_DEV_POWER_MGMT_LIMIT` | `DCGM_FI_DEV_BOARD_POWER_LIMIT_REQUESTED_WATTS` (aliased) | not scanned | watts | confirmed |
| Power limit (enforced) | 164 | `DCGM_FI_DEV_ENFORCED_POWER_LIMIT` | `DCGM_FI_DEV_BOARD_POWER_LIMIT_ENFORCED_WATTS` (aliased) | not scanned | watts | confirmed |
| GPU utilization | 203 | `DCGM_FI_DEV_GPU_UTIL` | same, "Deprecated: Use DCGM_FI_DEV_GPU_UTIL_RATIO instead" | n/a | percent 0-100 | confirmed |
| GPU utilization ratio | 1613 | absent | `DCGM_FI_DEV_GPU_UTIL_RATIO`, "GPU Utilization (0.0-1.0)" | **v4.6.0** | ratio 0.0 to 1.0 | confirmed |
| Memory copy utilization | 204 | `DCGM_FI_DEV_MEM_COPY_UTIL` | same, deprecated; ratio field 1614 `DCGM_FI_DEV_MEMORY_UTIL_RATIO` | not scanned | 204: unit not stated in the header | confirmed IDs |
| XID | 230 | `DCGM_FI_DEV_XID_ERRORS` | `DCGM_FI_DEV_XID_ERROR` (old aliased) | v4.6.0 | the XID number | confirmed; see [xid.md](xid.md) |
| FB total, free, used | 250, 251, 252 | `DCGM_FI_DEV_FB_*` | same; 252 marked "Used Frame Buffer in MiB. Deprecated" | n/a | MiB | confirmed |
| FB used bytes | 1627 | absent | `DCGM_FI_DEV_FB_USED_BYTES` | **v4.7.0** | bytes | confirmed |
| FB capacity and free bytes | 1625, 1626 | absent | `DCGM_FI_DEV_FB_CAPACITY_BYTES`, `DCGM_FI_DEV_FB_FREE_BYTES` | not scanned | bytes | confirmed at v4.7.0 ([dcgm_fields.h:3688-3698](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/dcgm_fields.h#L3688-L3698)) |
| ECC SBE, DBE volatile totals | 310, 311 | `DCGM_FI_DEV_ECC_{SBE,DBE}_VOL_TOTAL` | same | n/a | count since driver load | confirmed IDs; the "since driver load" wording is from the nvidia-smi help text for the equivalent field |
| ECC SBE, DBE aggregate totals | 312, 313 | `DCGM_FI_DEV_ECC_{SBE,DBE}_AGG_TOTAL` | same | n/a | persistent count | confirmed IDs |
| Pending page retirement | 392 | `DCGM_FI_DEV_RETIRED_PENDING` | `DCGM_FI_DEV_PAGE_RETIRED_PENDING` (aliased) | not scanned | 0 or 1 | confirmed |
| Row remap failure | 395 | `DCGM_FI_DEV_ROW_REMAP_FAILURE` | `DCGM_FI_DEV_ROW_REMAP_FAILED` (aliased) | not scanned | 0 or 1 | confirmed |

"Not scanned" means the first-appearance tag scan covered only the names that matter for scale or semantics (1613, 1627, the clock event, XID, temperature, and power names). The scan ran over tags `v3.3.1` and later and `v4.*`; DCGM `v3.1.x` and `v3.2.x` tags exist and were not read, so IDs on those lines are unverified. DCGM is Apache 2.0 ([LICENSE at v4.7.0](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/LICENSE)).

## dcgmi

| Fact | Status | Source |
|---|---|---|
| `dcgmi dmon` takes `-e` field ids, `-i` entity ids, `-g` group, `-f` field group, `-d` delay, `-c` count, `-l` list; its option block has no `--json` or `--csv`. Several other subcommands (for example `policy`, `group`, `fieldgroup`) declare `-j/--json`. | confirmed by reading the option declarations; the `discovery` option block was read and no `json` switch was found in it | [`dcgmi/CommandLineParser.cpp` at v4.7.0](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmi/CommandLineParser.cpp) (dmon at `ProcessDmonCommandLine`, line 3191) |
| `dcgmi discovery -l` lists GPU ID, name, PCI bus ID, device UUID in a table. By default only active GPUs; `-a` adds detached, lost, or inaccessible ones. | confirmed | [DCGM feature overview](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/feature-overview.html), read 2026-11-15 |
| DCGM GPU IDs are DCGM's own: the documented example shows GPU IDs 0 and 2 for two GPUs. | confirmed (documentation example) | Same page |
| `dcgmi` is "a network-capable interface into the NVIDIA host engine". | confirmed | [Getting started](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/getting-started.html) |
| The DCGM documentation does not describe a stable machine-readable output for `dmon`. | confirmed (absence) | Same pages |

Consequence: a `dcgmi` backend parses fixed-width tables whose layout is not documented, which the plan's rule "parse by field position, reject a short row" fits poorly. Using the exporter text format or the DCGM API would be better parsed; the plan's path C and an API binding are the alternatives (see [collection-path.md](collection-path.md)).

## dcgm-exporter

| Fact | Status | Source |
|---|---|---|
| Default listener `:9400`, collect interval 30000 ms, path `/metrics`, `/health` also served. | confirmed | [`defaults.go` at 4.9.0](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/appconfig/defaults.go); [`server.go`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/server/server.go) line 164-168 |
| Without `--remote-hostengine-info` it starts an embedded host engine; the remote default is `localhost:5555`. | confirmed | [command reference](https://docs.nvidia.com/datacenter/dcgm/latest/reference/command-line-reference/dcgm-exporter.html) |
| Metric names are the DCGM field names. At 3.3.9-3.6.1, 4.4.2-4.7.1, and 4.8.4 the default files use the legacy names (`DCGM_FI_DEV_GPU_TEMP`, `DCGM_FI_DEV_POWER_USAGE`, `DCGM_FI_DEV_GPU_UTIL`, `DCGM_FI_DEV_FB_USED`, `DCGM_FI_DEV_XID_ERRORS`). At 4.9.0 they use the new ones (`..._GPU_TEMP_CELSIUS`, `..._BOARD_POWER_WATTS`, `..._GPU_UTIL_RATIO`, `..._FB_USED_BYTES`, `..._XID_ERROR`). | confirmed | `etc/default-counters.csv` at each tag |
| 4.9.0 defaults include `DCGM_FI_PROF_*` fields. DCGM documents profiling metrics as needing "nv-hostengine ... started with superuser privileges". | confirmed (both statements); behavior of the exporter for those fields without privilege unverified | `etc/default-counters.csv` at 4.9.0; feature overview |
| 4.9.0's default file does not enable ECC, throttle or retired-page fields (ECC, throttle, `DCGM_EXP_XID_ERRORS_*` are commented out). An operator edits a counters file (`--collectors`) to enable them. | confirmed | `etc/default-counters.csv` at 4.9.0 |
| Labels in the renderer's built-in set for a GPU: `GPU_CI_ID`, `GPU_I_ID`, `GPU_I_PROFILE`, `device`, `gpu`, `hostname`, `modelName`, `pci_bus_id`; the UUID label is `UUID` (`uuid` with `--use-old-namespace`). | confirmed from source; actual rendering unverified | [`metriclabels/labels.go`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/metriclabels/labels.go), [`gpu_collector.go` around line 924](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/collector/gpu_collector.go#L924) |
| `DCGM_FI_DEV_GPU_UTIL_RATIO` is a ratio, so the plugin multiplies by 100 for the percent field; the legacy name is already percent. | confirmed | header comment; default file help text |

The exporter's behavior as a transport is evaluated in [dcgm-exporter.md](dcgm-exporter.md).

## Limits

- No DCGM, `dcgmi`, or exporter binary was run; table layouts and label values are source and documentation readings.
- DCGM `v3.1.x`, `v3.2.x`, and `v4.0` to `v4.5` were not compared field by field.
- The exporter's GPU UUID label value format (for example the `GPU-` prefix) was not read from a rendered sample.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #15 | `dcgmi discovery -l`, `dcgmi discovery -l -a`, and `dcgmi dmon -e <ids> -c 3` saved as fixtures, with and without a running `nv-hostengine` | Table layout, with and without a host engine, sample timing |
| #15 | `/metrics` from exporter 4.9.0 and 4.8.4 on the same node | Metric names, units, and labels per generation |
| #15 | `dcgmi dmon` for field 230 and 112 on a GPU with no events | The value printed for "no event" (blank, 0, or N/A) |
