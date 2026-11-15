# amd-smi and rocm-smi output formats and fields

Research for [#18](https://github.com/somethingwithproof/plugin_gpu/issues/18), 2026-11-15. Question: which `amd-smi` subcommands and JSON or CSV fields supply utilization, memory, temperature, power, clocks, ECC, and throttle data, and do supported ROCm releases still ship `rocm-smi`?

**Result.** `amd-smi` has `--json` and `--csv` on every subcommand read, and the field names for usage, power, temperature, ECC, VRAM, and throttle accumulators can be read from the CLI source. Three plan statements need sharpening: JSON values carry a `{"value": ..., "unit": ...}` wrapper only when available (unavailable values stay the bare string `"N/A"`, so the parser must check the type before unwrapping), the JSON and CSV forms use different key names for the same identity fields (`bdf` and `uuid` against `gpu_bdf` and `gpu_uuid`), and in a partitioned device each partition is listed as its own entry with its own BDF function, node ID, and partition ID. On `rocm-smi`: the library's README says only critical fixes after ROCm 7.0, and the current ROCm compatibility page (ROCm 10.1.0) lists AMD SMI under control and monitoring tools and does not mention ROCm SMI, so the plan's target of `amd-smi` only is supported; ROCm releases before 7.0 were not examined. Real output, units for clocks, and how unavailable values print in JSON need hardware.

## Method

Read the `amdsmi` repository at tag `rocm-7.2.4` (the CLI documentation, `amdsmi_cli/amdsmi_commands.py`, `py-interface/amdsmi_interface.py`, `amdsmi.h`, and the changelog), the `rocm_smi_lib` README at the same tag, and the current ROCm compatibility matrix page (fetched 2026-11-15). Nothing was run.

## Pins

| Item | Ref | Commit |
|---|---|---|
| amdsmi | tag `rocm-7.2.4` | `1e91f3c1527617066f50c22f9ec4368fe82e1a3c` |
| amdsmi | `amd-mainline` head, 2026-11-11 (differs from the tag in `amdsmi_commands.py` by 242 changed lines; not read) | `99256d0b128723fdc6c3bc9fb954a0d7f6d40be4` |
| rocm_smi_lib | tag `rocm-7.2.4` | `f7db05540678babeb4e6d2d9263f71201a05904f` |

The latest ROCm release the compatibility page describes is 10.1.0 (page dated 2026-08-26, listing AMD SMI 27.1.0). The `rocm-7.2.4` tag is the last `rocm-` tag in the repository; later tags are named `therock-*`. The CLI documentation and source were read at 7.2.4, not at the ROCm 10 line, so field names there are unverified.

## Command and field table

| Need | Command and flag | JSON key (at rocm-7.2.4) | Unit and notes | Status | Source |
|---|---|---|---|---|---|
| Identity | `amd-smi list --json` | `bdf`, `uuid`, `kfd_id`, `node_id`, `partition_id` | CSV uses `gpu_bdf` and `gpu_uuid` instead ("CSV format is intentionally aligned with Host") | confirmed | [`amdsmi_commands.py:255-280`](https://github.com/ROCm/amdsmi/blob/1e91f3c1527617066f50c22f9ec4368fe82e1a3c/amdsmi_cli/amdsmi_commands.py#L255-L280); [docs](https://github.com/ROCm/amdsmi/blob/1e91f3c1527617066f50c22f9ec4368fe82e1a3c/docs/how-to/amdsmi-cli-tool.md) |
| Engine usage | `amd-smi metric --usage` | `usage` -> `gfx_activity`, `umc_activity`, `mm_activity` | percent; JSON wraps each as `{"value", "unit"}` | confirmed (keys: `py-interface/amdsmi_interface.py:2478-2480`); unit text read in CLI code | `amdsmi_commands.py:1895-1926` |
| Memory | `amd-smi metric --mem-usage` | `mem_usage` -> `total_vram`, `used_vram`, `free_vram` | converted with `// (1024*1024)`, so MiB; unit label not read | confirmed (conversion); unit string unverified | `amdsmi_commands.py:2557-2608` |
| Power | `amd-smi metric --power` | `power` -> `socket_power`, `gfx_voltage`, `soc_voltage`, `mem_voltage` | W for power, mV for voltage | confirmed | `amdsmi_commands.py:1925-1960` |
| Temperature | `amd-smi metric --temperature` | `temperature` -> `edge`, `hotspot`, `mem` | degrees C (`C` in JSON, with the degree sign in human output) | confirmed | `amdsmi_commands.py:2290-2330` |
| ECC totals | `amd-smi metric --ecc` | `ecc` -> `total_correctable_count`, `total_uncorrectable_count`, `total_deferred_count`, plus `cache_*` | counts; `N/A` when the call fails | confirmed | `amdsmi_commands.py:2350-2380` |
| Clocks | `amd-smi metric --clock` | `clock` | not read in detail | unverified | `amdsmi_commands.py:2277` |
| Throttle accumulators | `amd-smi metric --violation` | `throttle` -> `accumulation_counter`, `prochot_accumulated`, `ppt_accumulated`, `socket_thermal_accumulated`, `vr_thermal_accumulated`, `hbm_thermal_accumulated`, `low_utilization_accumulated`, plus `*_violation_status` (active or not) and `*_violation_activity` (percent) | "Only available for MI300 or newer ASICs" | confirmed | `amdsmi_commands.py:2625-2672`; docs ("-v, --violation") |
| Partitions | `amd-smi partition` | not read | shows compute and memory partitions | confirmed (docs) | docs |
| N/A values | any | `"N/A"` string | "N/A typically indicates that the data ... is either unavailable or irrelevant" (not applicable, or not retrievable by the tool or driver) | confirmed | docs, "About N/A values" |
| Privilege | `amd-smi static` | | "Sudo may be required for some features"; `set` and `reset` "require sudo". `list` and `metric` carry no such statement. | confirmed (docs); whether `list` and `metric` run unprivileged is a live check | docs lines 170, 557, 640 |
| Tool language | all | | The CLI is Python (`amdsmi_cli.py`); the plugin runs it as an external program | confirmed | repository listing |

## Identity

- `amd-smi list` prints one UUID per entry. In the changelog's example for a partitioned device, two entries on one bus show different BDF functions (`0000:0c:00.0` and `0000:0c:00.1`), different `KFD_ID` and `NODE_ID`, and `PARTITION_ID` 0 and 1; the UUID values are redacted in the example, so whether they differ is unverified. The CLI's unit of listing is the partition, so an AMD "GPU" in the plugin's index space may be a partition and not a card ([amdsmi CHANGELOG.md at rocm-7.2.4, lines 1211-1277](https://github.com/ROCm/amdsmi/blob/1e91f3c1527617066f50c22f9ec4368fe82e1a3c/CHANGELOG.md#L1211-L1277)).
- `amdsmi.h` documents `amdsmi_get_gpu_device_uuid` as "Returns the UUID of the device" with no stability statement, and the CLI uses a separate "HIP_UUID" with a `GPU-` prefix. Whether the UUID survives reboot, driver reinstall, and card swap is unverified; see [identity.md](identity.md).

## rocm-smi

| Fact | Status | Source |
|---|---|---|
| "Starting with ROCm 7.0, only critical bug fixes will be applied to ROCm-SMI", with users directed to AMD-SMI. | confirmed | [rocm_smi_lib README at rocm-7.2.4, line 3](https://github.com/ROCm/rocm_smi_lib/blob/f7db05540678babeb4e6d2d9263f71201a05904f/README.md) |
| The repository's default branch is `amd-staging_deprecated`. | confirmed | `git rev-parse --abbrev-ref HEAD` of a clone |
| The ROCm 10.1.0 compatibility page lists "AMD SMI" and "ROCm Data Center Tool (RDC)" under control and monitoring tools; a text search of the page for "smi" finds only AMD SMI entries. | confirmed | [ROCm compatibility matrix](https://rocm.docs.amd.com/en/latest/compatibility/compatibility-matrix.html), read 2026-11-15 |

Recommendation for the plan (inference): no `rocm-smi` backend. The remaining gap is ROCm 6.x and earlier, which the plan has not chosen to support; if the support matrix (see [compatibility-matrix.md](compatibility-matrix.md)) includes them, the owner must decide whether to add `rocm-smi` or exclude them.

## Limits

- No `amd-smi` output was captured. Key names are those the CLI source assigns; formatting (nesting, list-valued fields for multi-XCD parts) was not seen.
- The mainline CLI differs from the 7.2.4 tag; AMD SMI 27.1.0 (ROCm 10.1.0) was not read.
- Clock field names and units were not read.
- No source was found stating that `amd-smi list` or `metric` runs unprivileged.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #18 | `amd-smi list --json`, `metric --json`, `metric --csv`, `static --json` on one MI-series node per supported ROCm release | Real keys, nesting, units, `N/A` form in JSON |
| #18 | `amd-smi metric --violation --json` on MI250 and MI300 | The violation fields' behavior when unsupported |
| #18 | Same commands as an unprivileged user in the `render` or `video` group and with neither | The privilege table for #21 |
| #18 | Reboot, driver reinstall, and a partition-mode change; compare `amd-smi list` | AMD UUID stability for #19 |
| #18 | Check which supported distribution packages for ROCm 6.x install `rocm-smi` | Whether any `rocm-smi` support is needed |
