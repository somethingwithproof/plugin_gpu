# MIG instance identity and visibility

Research for [#20](https://github.com/somethingwithproof/plugin_gpu/issues/20), 2026-11-15. Question: how do nvidia-smi, DCGM, and dcgm-exporter expose MIG GPU instances and compute instances, and what identifies an instance across reboot and reconfiguration?

**Result.** Three useful facts come from the headers and source, and the remaining identity questions need a MIG-capable GPU. (1) NVML says a MIG device's UUID is "immutable for the lifetime of a MIG device" and that its handle may be reused after the instance is destroyed with "properties" that can change, so a UUID identifies one instance lifetime and a recreated instance with the same profile is a different instance with a new UUID (the plan's rule that a new instance never inherits history is consistent; whether the recreated instance gets a new UUID is unverified). (2) DCGM numbers instances with its own entity IDs: its documentation shows NVML GPU instances `GI 1` and `GI 2` as DCGM entity IDs 0 and 1, so DCGM entity IDs must not be used as a key. (3) On a MIG-enabled GPU the NVML header says device-level utilization rates are not supported and only a subset of ECC information is available per instance, which means the plan's per-GPU `util_gpu` on the parent can be unavailable. These findings support parent-only monitoring in phase 1 and leave decision #13 to the owner (Options).

## Method

Read the NVML 13.4.92 header (MIG functions and notes on MIG-enabled devices), DCGM's feature overview MIG section and the DCGM XID code from `v4.7.0`, the `dcgm-exporter` 4.9.0 label code, and the nvidia-smi manual. No MIG device was created.

## Findings

| Fact | Status | Source |
|---|---|---|
| NVML UUID: "When used with MIG device handles the API returns globally unique UUIDs which can be used to identify MIG devices across both GPU and MIG devices. UUIDs are immutable for the lifetime of a MIG device." | confirmed | [nvml.h around line 5667 at go-nvml 5e99270](https://github.com/NVIDIA/go-nvml/blob/5e992702f9f516e8db7a0c34d3e462a87f48339d/gen/nvml/nvml.h#L5663-L5688) |
| MIG device handle (by index under the parent): if the compute instance is destroyed, or its parent GPU instance or the GPU is destroyed, reset, or unbound, "the MIG device handle would remain invalid and must be requested again ... Handles may be reused and their properties can change in the process." | confirmed | nvml.h `nvmlDeviceGetMigDeviceHandleByIndex`, lines 14397-14404 |
| NVML index on a MIG handle: "MIG device indices are unique within a device." | confirmed | nvml.h, `nvmlDeviceGetIndex` comment |
| `nvmlDeviceGetUtilizationRates`: "On MIG-enabled GPUs, querying device utilization rates is not currently supported." Encoder and decoder utilization have the same note. Per-instance ECC: "Per instance information is currently only supported for non-DRAM uncorrectable volatile errors. Querying volatile errors using device handles is currently not supported." | confirmed (header notes on 13.4.92) | nvml.h lines 7644-7650 and 7682, 7702, 7793, 7814 |
| NVML XID events on MIG: with MIG enabled, an XID event carries a GPU instance ID and compute instance ID or `0xFFFFFFFF`; "in MIG mode, if device handle is provided, the API reports all the events for the available instances, only if the caller has appropriate privileges". | confirmed | nvml.h lines 3470-3484 and 10700-10720 |
| DCGM models GPU instances and compute instances as entities; `dcgmi discovery -c` shows the hierarchy; groups take them as `i:<id>` and `ci:<id>`. In the documented example NVML `GPU 0 GI 1` and `GI 2` appear as DCGM `GPU Instance (EntityID: 0)` and `(EntityID: 1)`. | confirmed (documentation example) | [DCGM feature overview](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/feature-overview.html), MIG example, read 2026-11-15 |
| DCGM offers "two views of the metrics for MIG: GPU device-level metrics" and "MIG device ... granularity". The example per-instance metrics are the profiling fields (GRACT, tensor). | confirmed | Same page |
| DCGM records XIDs per entity (GPU, GPU instance, or compute instance). | confirmed | [DcgmCacheManager.cpp:8891-8990, 9239-9255 at v4.7.0](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/src/DcgmCacheManager.cpp#L8891-L8990) |
| Detaching a GPU removes its MIG instances from DCGM groups, and all cached values for it. | confirmed | feature overview, "Behavior of Detached GPUs" |
| `dcgm-exporter` adds `GPU_I_ID`, `GPU_I_PROFILE`, and `GPU_CI_ID` labels for MIG entities, alongside `gpu`, `UUID`, `device`, `modelName`, `pci_bus_id`. | confirmed from source; rendered output unverified | [`metriclabels/labels.go`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/metriclabels/labels.go); [`gpu_collector.go:1097-1152`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/collector/gpu_collector.go#L1097-L1152) |
| nvidia-smi: `mig -lgi` lists GPU instances; `mig` management subcommands change state and are never run by the plugin (ADR 0001, ADR 0007). `--query-gpu=mig.mode.current` reports the mode. | confirmed | nvidia-smi manual; snapshot help text |

## What identifies an instance

| Identifier | Stable across | Evidence |
|---|---|---|
| MIG device UUID (`MIG-...`) | the instance's lifetime; documented immutable | NVML header |
| NVML MIG index | unique within one device; handles are invalid after destruction and may be reused | NVML header |
| GPU instance ID and compute instance ID (as shown in `nvidia-smi` and the event data) | not stated | NVML header gives the field, not the reuse rule |
| DCGM entity ID | DCGM process lifetime at best; differs from the NVML GPU instance ID in the documented example | feature overview |

Whether recreating an instance of the same profile reuses the same GPU instance ID and receives a new UUID is not stated and must be tested. If it does reuse the ID, an identity key built from the GPU instance ID would attach history to the wrong instance, so the key must be the MIG UUID.

## Options for #13 (owner decision)

| Option | What the evidence says |
|---|---|
| Parent GPU only | Works from the same queries as non-MIG GPUs, but device-level utilization may be unsupported on the parent when MIG is enabled, so `util_gpu` can be unknown for such GPUs. ECC fields have limited per-instance support. |
| MIG instances as extra indexes of the same data query | Needs the MIG UUID as key and a per-instance field list; per-instance metrics from the sources read are profiling fields, which need superuser on the host engine; NVML per-instance support is limited (utilization, ECC). Instances come and go, which the plan already handles as absent GPUs. |
| Separate data query for MIG | Same sources; keeps the GPU template fields free of instance fields. |

None of the three can be evaluated for field coverage without a MIG-enabled GPU. Phase 1 can ship without MIG, which is consistent with the plan (MIG is phase 3).

## Limits

- No MIG instance was created, destroyed, or queried.
- `nvidia-smi -L` and `nvidia-smi mig -lgi` output formats were not read from a sample; the manual describes the commands.
- Per-instance metrics available through `nvidia-smi --query-gpu` with a MIG UUID in `-i` were not documented in anything read.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #20 | On an A100 or H100 with MIG, save `nvidia-smi -L`, `nvidia-smi mig -lgi`, `--query-gpu=index,uuid,mig.mode.current`, and `dcgmi discovery -c` for two layouts | Instance listing formats and identifiers |
| #20 | Destroy and recreate an instance with the same profile; compare MIG UUID and GPU instance ID | Reuse rule for identity |
| #20 | Query `utilization.gpu`, `memory.used`, ECC, and power on the parent of a MIG-enabled GPU | Which plan fields are unknown under MIG |
| #20 | Scrape `dcgm-exporter` on a MIG node | The per-instance label set and values |
