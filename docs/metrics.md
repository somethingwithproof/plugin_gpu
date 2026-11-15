# GPU metric model

Every backend fills the same fields ([ADR 0009](decisions/0009-common-metric-core-with-vendor-extensions.md)). Each field is one data source item of the GPU data template and one output field of the GPU data query, so one set of graph and Thold templates covers every vendor. The table between the markers is generated from `lib/MetricModel.php` (`MetricModel::table()`), and a unit test fails when the two differ.

Milestone 3 implements the nvidia-smi column. The DCGM and AMD columns record the source a later backend will read, with the research issue that confirms it where it is not settled.

<!-- metric-table:start -->
| Field | Meaning | Unit | Kind | Range | nvidia-smi source | DCGM source | AMD source |
|---|---|---|---|---|---|---|---|
| `util_gpu` | GPU utilization | percent | gauge | 0 to 100 | `utilization.gpu` | 1613 (ratio 0.0 to 1.0, times 100), or 203 (percent, deprecated) | `metric --usage` `gfx_activity` (#18) |
| `util_mem` | Memory controller utilization | percent | gauge | 0 to 100 | `utilization.memory` | 1614 (ratio 0.0 to 1.0, times 100), or 204 (unit not stated, #15) | `metric --usage` `umc_activity` (#18) |
| `mem_used` | Frame buffer memory used | bytes | gauge | 0 to U | `memory.used` (MiB, times 1,048,576) | 1627 (used, bytes), or 252 (MiB, deprecated) | `metric --mem-usage` `used_vram` (MiB, #18) |
| `mem_total` | Frame buffer memory total | bytes | gauge | 0 to U | `memory.total` (MiB, times 1,048,576) | 1625 (capacity, bytes), or 250 (MiB, deprecated); 1626 is free bytes and is not used | `metric --mem-usage` `total_vram` (MiB, #18) |
| `temp_gpu` | GPU temperature | degrees C | gauge | U to U | `temperature.gpu` | 150 | `metric --temperature` `edge` (#18) |
| `power` | Board power draw | watts | gauge | 0 to U | `power.draw` | 155 | `metric --power` `socket_power` |
| `power_limit` | Board power limit | watts | gauge | 0 to U | `power.limit` | 160 | #18 |
| `clock_sm` | SM clock | MHz | gauge | 0 to U | `clocks.sm` | 1603 (hertz, divided by 1,000,000), or 100 (unit not stated) | `metric --clock` (#18) |
| `clock_mem` | Memory clock | MHz | gauge | 0 to U | `clocks.mem` | 1604 (hertz, divided by 1,000,000), or 101 (unit not stated) | `metric --clock` (#18) |
| `ecc_corr` | Corrected ECC errors since driver load | count | gauge | 0 to U | `ecc.errors.corrected.volatile.total` | 310 | `metric --ecc` `total_correctable_count` |
| `ecc_uncorr` | Uncorrected ECC errors since driver load | count | gauge | 0 to U | `ecc.errors.uncorrected.volatile.total` | 311 | `metric --ecc` `total_uncorrectable_count` |
| `ecc_corr_new` | Corrected ECC errors since the previous sample | count | gauge | 0 to U | computed from consecutive `ecc_corr` samples | computed from consecutive `ecc_corr` samples | computed from consecutive `ecc_corr` samples |
| `ecc_uncorr_new` | Uncorrected ECC errors since the previous sample | count | gauge | 0 to U | computed from consecutive `ecc_uncorr` samples | computed from consecutive `ecc_uncorr` samples | computed from consecutive `ecc_uncorr` samples |
| `retired_pending` | Page retirement pending | 0 or 1 | indicator | 0 to 1 | `retired_pages.pending` | 392 | #18 |
| `retired_sbe` | Pages retired after single-bit ECC errors | pages | gauge | 0 to U | `retired_pages.sbe` | 390 (`DCGM_FI_DEV_RETIRED_SBE`, unconfirmed, #15) | #18 |
| `retired_dbe` | Pages retired after a double-bit ECC error | pages | gauge | 0 to U | `retired_pages.dbe` | 391 (`DCGM_FI_DEV_RETIRED_DBE`, unconfirmed, #15) | #18 |
| `thr_sw_power_cap` | Clocks held by the software power cap | 0 or 1 | indicator | 0 to 1 | `clocks_throttle_reasons.active`, bit 0x4 | field 112, bit 0x4 | `metric --violation` (MI300 or newer, #18) |
| `thr_hw_slowdown` | Hardware slowdown | 0 or 1 | indicator | 0 to 1 | `clocks_throttle_reasons.active`, bit 0x8 | field 112, bit 0x8 | not applicable |
| `thr_sw_thermal` | Software thermal slowdown | 0 or 1 | indicator | 0 to 1 | `clocks_throttle_reasons.active`, bit 0x20 | field 112, bit 0x20 | `metric --violation` (MI300 or newer, #18) |
| `thr_hw_thermal` | Hardware thermal slowdown | 0 or 1 | indicator | 0 to 1 | `clocks_throttle_reasons.active`, bit 0x40 | field 112, bit 0x40 | `metric --violation` (MI300 or newer, #18) |
| `thr_hw_power_brake` | Hardware power brake | 0 or 1 | indicator | 0 to 1 | `clocks_throttle_reasons.active`, bit 0x80 | field 112, bit 0x80 | not applicable |
| `age` | Seconds since the tool took the sample | seconds | gauge | 0 to U | computed from `timestamp` | computed from the sample timestamp | computed from the sample timestamp |
<!-- metric-table:end -->

## Rules

- A missing, unsupported, or stale value is stored and printed as `U`, never 0 (REQ-METRIC-004). nvidia-smi placeholders such as `N/A`, `[N/A]`, and `[Not Supported]`, and any other text in a numeric column, are unknown.
- Utilization is percent 0 to 100 whatever the source scale (REQ-METRIC-002). DCGM field 1613 is a ratio from 0.0 to 1.0 and is multiplied by 100.
- Memory is bytes (REQ-METRIC-003). nvidia-smi prints MiB with `nounits`; the unit in CSV output is a live check in #14. DCGM fields 1627 (used) and 1625 (capacity) are bytes; 1626 is free bytes and is not part of the model.
- ECC values are gauges of the reported total since the driver was loaded (REQ-METRIC-005). They are volatile counts, not lifetime totals (REQ-ERR-003): A driver reload resets them to 0, so a threshold on the total can clear after a reboot without the cause being fixed.
- `ecc_corr_new` and `ecc_uncorr_new` are the errors counted since the previous sample, which the plugin computes from two consecutive totals of the same GPU. They are unknown for a GPU's first sample, after a missed or unknown read, and when the total went down (a driver reload). They are gauges, never Cacti or RRD counters: Thold 1.8.2 throws a PHP `TypeError` when a thresholded DERIVE or COUNTER data source reads `U`, and Cacti then disables Thold. Alert on these, not on the totals.
- `retired_sbe`, `retired_dbe`, and `retired_pending` are the GPU's retired page counts and pending flag. They persist across driver loads.
- A GPU without ECC, or with ECC turned off, reports `U` for every ECC value, never 0 (REQ-ERR-002). The plugin also stores `ecc.mode.current`, and the health page shows the reason: ECC disabled, or not supported.
- There is no XID field. No XID source fits the fixed `nvidia-smi` commands ADR 0008 allows: `--query-gpu` has no XID field (checked against a published `--help-query-gpu` list; confirming it on current drivers is part of #14), `nvidia-smi event-log` is not documented to report XIDs, and the kernel log is outside those commands. Decision #8 picks the source ([research/xid.md](research/xid.md)).
- No error value causes a reset, drain, ECC counter clear, or page retirement (REQ-ERR-006). The plugin only reads.
- Throttle indicators are 1 when their bit is set in the clock event reasons mask and 0 when it is clear, and unknown when the mask is. The mask is parsed as hexadecimal (`0x` prefix) or decimal. `thr_hw_slowdown` (0x8) keeps its own indicator until a live check (#17) shows whether it ever occurs without 0x40 or 0x80. The raw mask is kept in the plugin's cache, so bits without an indicator, including any a later driver adds, are not lost.
- `age` is the seconds since the tool took its sample, from the sample's own timestamp, never from when the plugin read it (REQ-METRIC-006). It is unknown only for a GPU that was never sampled, so a stopped collection shows as a growing age that Thold can alert on.
- Data source names are at most 19 characters, Cacti's limit. The plan's `throttle_*` names became `thr_*` because `throttle_sw_power_cap`, `throttle_hw_slowdown`, and `throttle_hw_power_brake` are longer.
- A field a backend cannot supply is unknown for that backend and is not estimated (REQ-METRIC-007).

Each cached sample also records its observation time, ingestion time, backend, and tool version.
