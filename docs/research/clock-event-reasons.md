# Clock event reason names and bitmasks

Research for [#17](https://github.com/somethingwithproof/plugin_gpu/issues/17), 2026-11-15. Question: nvidia-smi renamed Clock Throttle Reasons to Clock Event Reasons and added the old query names back. Which `--query-gpu` names exist on which driver lines, do both names return the same bitmask, and which bits are normal at idle?

**Result.** The bit values and meanings are settled from the NVML header and the DCGM headers. Two plan statements change. First, the bitmask has eleven defined bits in current NVML (0x1 to 0x400), not nine: `Board Limit` (0x200) and `Reliability` (0x400) exist in NVML 13.4.92 and are absent from DCGM `v4.7.0`, so "DCGM maps 1:1 to NVML" holds only for 0x1 to 0x100, and the plugin must keep the raw mask. Second, `HW Slowdown` (0x8) is documented as "active if either HW Thermal Slowdown or HW Power Brake are active" and marked for removal, so the plan should not give it an indicator of its own; the two reasons it summarizes are the actionable ones. Which query names exist on which driver, and whether the old and new names return the same value, cannot be settled without a driver; the manual's changelog says both exist but gives no release numbers.

## Method

Read the nvidia-smi manual (page text and changelog), NVML 13.4.92 header from go-nvml, and `dcgm_fields.h` at two DCGM tags. Nothing was run.

## Bit table

| Bit | NVML name (13.4.92) | DCGM v3.3.9 / v4.7.0 name | Manual description | Treat as | Status |
|---|---|---|---|---|---|
| 0x1 | `nvmlClocksEventReasonGpuIdle` | `..._THROTTLE_REASON_GPU_IDLE` / `..._EVENT_REASON_GPU_IDLE` | Deprecated, "will be removed in a future CUDA release"; header: "This limiter may be removed in a later release" | normal | confirmed |
| 0x2 | `nvmlClocksEventReasonApplicationsClocksSetting` | `..._CLOCKS_SETTING` | Deprecated; header: "@deprecated No longer used" | informational, never alerts | confirmed |
| 0x4 | `nvmlClocksEventReasonSwPowerCap` | `..._SW_POWER_CAP` | SW power scaling reduces clocks because the GPU draws too much power | informational (a configured limit working) | confirmed |
| 0x8 | `nvmlClocksThrottleReasonHwSlowdown` | `..._HW_SLOWDOWN` | "Will be removed a future CUDA release ... active if either HW Thermal Slowdown or HW Power Brake are active" | aggregate; no indicator of its own | confirmed |
| 0x10 | `nvmlClocksEventReasonSyncBoost` | `..._SYNC_BOOST` | GPU is in a sync boost group; limited by the slowest GPU | informational | confirmed |
| 0x20 | `nvmlClocksEventReasonSwThermalSlowdown` | `..._SW_THERMAL` | SW thermal capping because temperature is above the max operating temperature | actionable | confirmed |
| 0x40 | `nvmlClocksThrottleReasonHwThermalSlowdown` | `..._HW_THERMAL` | Clocks cut by a factor of 2 or more because of temperature | actionable | confirmed |
| 0x80 | `nvmlClocksThrottleReasonHwPowerBrakeSlowdown` | `..._HW_POWER_BRAKE` | External power brake assertion (for example from the system power supply) | actionable | confirmed |
| 0x100 | `nvmlClocksEventReasonDisplayClockSetting` | `..._DISPLAY_CLOCKS` | "This field will be removed in a future CUDA release"; Volta only | informational | confirmed |
| 0x200 | `nvmlClocksEventReasonBoardLimit` | none at v4.7.0 | Board limit (operating) policy is the limiting factor | informational until observed | confirmed in NVML; absent in DCGM headers |
| 0x400 | `nvmlClocksEventReasonReliability` | none at v4.7.0 | Reliability policy is the limiting factor | informational until observed | confirmed in NVML; absent in DCGM headers |

"Treat as" is a proposal for the plan's decoder, not a vendor statement; the manual says only that "if all event reasons are returned as 'Not Active' it means that clocks are running as high as possible." Whether a given reason is a fault depends on the site (a software power cap set on purpose is not one).

Sources: [nvml.h lines 3675-3831 at go-nvml 5e99270](https://github.com/NVIDIA/go-nvml/blob/5e992702f9f516e8db7a0c34d3e462a87f48339d/gen/nvml/nvml.h#L3675-L3831); [dcgm_fields.h v3.3.9 lines 107-154](https://github.com/NVIDIA/DCGM/blob/b0ec3c624ea21e688b0d93cf9b214ae0eeb6fe52/dcgmlib/dcgm_fields.h#L107-L154); [v4.7.0 lines 107-198](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/dcgm_fields.h#L107-L198); [nvidia-smi manual, Clocks Event Reasons](https://docs.nvidia.com/deploy/nvidia-smi/index.html), read 2026-11-15.

## Names and versions

| Fact | Status | Source |
|---|---|---|
| The manual's changelog lists "Renamed Clock Throttle Reasons to Clock Event Reasons" under the heading "Changes between nvidia-smi v525 Update and v530" and "Added back clock_throttle_reasons to --query-gpu to not break backwards compatibility" under "v535 Update and v530". The headings are nvidia-smi versions; the page does not map them to driver releases. | confirmed (wording); driver mapping unverified | nvidia-smi manual, changelog |
| The 2021 `--help-query-gpu` snapshot names `clocks_throttle_reasons.{supported,active,gpu_idle,applications_clocks_setting,sw_power_cap,hw_slowdown,hw_thermal_slowdown,hw_power_brake_slowdown,sw_thermal_slowdown,sync_boost}`. | confirmed (old names) | [pynvml `help_query_gpu.txt` at 20e79a8](https://github.com/gpuopenanalytics/pynvml/blob/20e79a8b585a85ed9dda4c362c8b5c010b1a295f/help_query_gpu.txt) |
| New-style names `clocks_event_reasons.*` are not in the snapshot; the manual names only the counters form `clocks_event_reasons_counters.{sw_power_cap,sw_thermal_slowdown,sync_boost,hw_thermal_slowdown,hw_power_brake_slowdown}` (microseconds). | confirmed for counters; `clocks_event_reasons.active` etc. unverified | nvidia-smi manual, changelog ("v575 Update and v570") |
| NVML keeps the old macro names as deprecated aliases of the new ones (for example `nvmlClocksThrottleReasonGpuIdle` = `nvmlClocksEventReasonGpuIdle`). | confirmed | nvml.h lines 3805-3831 |
| DCGM field 112 was named `DCGM_FI_DEV_CLOCK_THROTTLE_REASONS` at v3.3.9; `v4.7.0` names it `DCGM_FI_DEV_CLOCKS_EVENT_REASONS` and keeps the old name as an alias. The new name first appears at v4.0.0 among tags v3.3.x and v4.x. | confirmed | `dcgm_fields.h` at both tags; tag scan of the v3.3 and v4 tags |
| The manual adds counters in microseconds for SW power cap, sync boost, SW thermal, HW thermal, and HW power brake. | confirmed | nvidia-smi manual, "Clock Event Reasons Counters" |

## Consequences for the plan

- The throttle indicator list becomes: `sw_power_cap`, `sw_thermal`, `hw_thermal`, `hw_power_brake` as indicators; `idle`, `app_clocks`, `sync_boost`, `display`, `board_limit`, `reliability` stored in the raw mask only. `hw_slowdown` is derived (0x8 is set when 0x40 or 0x80 is set); recording it separately adds an indicator that duplicates the other two.
- Data source item names are limited to 19 characters (see [cacti-core.md](cacti-core.md)). `throttle_hw_power_brake` is 23, so use for example `thr_hw_pwrbrake`.
- The raw mask is stored as a number up to 0x7ff today; bits above that must not be dropped, since NVML has added bits before.
- The counters (microseconds) are cumulative; they are an alternative signal to the instantaneous mask because a short event between two collections still advances them. They exist only on drivers that carry the counters fields; the plan could consider them in M4. Not verified on hardware.
- Reading the mask through DCGM field 112 gives the same bit values as NVML for bits up to 0x100 (header identity); this does not prove both backends report the same instant.

## Limits

- The driver release that first provides `clocks_event_reasons.*` names, and whether `clocks_throttle_reasons.*` returns an identical value on the same driver, are not stated anywhere read.
- What each bit reports at idle on current drivers was not checked; the manual says Idle is deprecated.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #17 | `nvidia-smi --help-query-gpu` per driver line, grep `clocks_` | Which names exist per line |
| #17 | Query `clocks_throttle_reasons.active` and `clocks_event_reasons.active` in one invocation on the same GPU at idle, under a power cap, and under thermal load | Whether the values match and which bits are set at idle |
| #17 | Same on a Blackwell or Hopper board | Whether 0x200 or 0x400 appear in practice |
