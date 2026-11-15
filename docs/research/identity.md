# GPU identity across reboots, driver reinstalls, and hardware changes

Research for [#19](https://github.com/somethingwithproof/plugin_gpu/issues/19), 2026-11-15. Question: which identifier keeps a GPU's Cacti history attached to the right GPU: UUID, serial, PCI bus ID, or index? What changes on reboot, driver upgrade, card swap in the same slot, slot move, PCIe passthrough to a VM, and RMA replacement?

**Result.** The NVML header supports ADR 0003 for NVIDIA: the UUID is documented as globally unique and immutable and "the only available identifier for pre-Fermi-architecture products", the NVML index "has no guarantees of consistency between reboots", and NVIDIA itself recommends lookup "by their PCI ids or GPU UUID". Nothing read says what a UUID does after an RMA, a firmware reflash, or inside a VM; those are unverified and need hardware. DCGM adds a third identifier, its own GPU ID, which is stable only for the life of the host engine process, and the plugin must not use it. For AMD nothing read states UUID stability and `amd-smi` lists partitions as separate entries, so ADR 0003's "Accepted for NVIDIA" scope stands and AMD identity stays open until a reboot test. No ADR change is required; one clarifying consequence is added to ADR 0003.

## Method

Read the NVML 13.4.92 header (go-nvml copy), the nvidia-smi manual, the pynvml help snapshot, the DCGM feature overview's section on detached GPUs, and the AMD SMI sources used in [amd-smi.md](amd-smi.md). Nothing was run and no hardware was changed.

## NVIDIA

| Identifier | What the sources say | Status | Source |
|---|---|---|---|
| UUID | `nvmlDeviceGetUUID`: "Retrieves the globally unique immutable UUID associated with this device ... It is the only available identifier for pre-Fermi-architecture products. It does NOT correspond to any identifier printed on the board." For MIG device handles: "UUIDs are immutable for the lifetime of a MIG device." | confirmed (documentation of intent); behavior after RMA or reflash unverified | [nvml.h, `nvmlDeviceGetUUID`, lines 5663-5688 at go-nvml 5e99270](https://github.com/NVIDIA/go-nvml/blob/5e992702f9f516e8db7a0c34d3e462a87f48339d/gen/nvml/nvml.h#L5663-L5688) |
| Serial | "globally unique board serial number ... matches the serial number tag that is physically attached to the board." "For all products with an inforom." The UUID "augments the immutable, board serial identifier". | confirmed; not available on every product | nvml.h lines 5342-5363 and 5663-5688 |
| Index | "The order in which NVML enumerates devices has no guarantees of consistency between reboots. For that reason it is recommended that devices be looked up by their PCI ids or GPU UUID." The NVML index "may not correlate with other APIs, such as the CUDA device index." | confirmed | nvml.h, `nvmlDeviceGetIndex`, lines 5308-5339 |
| PCI bus ID | `domain:bus:device.function` in hex. Identifies a slot; the documentation says nothing about card replacement. | confirmed (format); replacement behavior is inference | [pynvml `help_query_gpu.txt` at 20e79a8](https://github.com/gpuopenanalytics/pynvml/blob/20e79a8b585a85ed9dda4c362c8b5c010b1a295f/help_query_gpu.txt); nvidia-smi manual |
| `nvidia-smi -i` | Accepts index, serial, UUID, or PCI bus ID. | confirmed | [nvidia-smi manual](https://docs.nvidia.com/deploy/nvidia-smi/index.html), read 2026-11-15 |
| DCGM GPU ID | DCGM's own number. A documented example lists GPU IDs 0 and 2 for two GPUs. "The DCGM GPU ID stays the same throughout the entire life cycle of the DCGM main process (nv-hostengine), even after attach and detach operations." After a detach, "All cached values and watched fields are removed from detached GPUs" and groups drop them. | confirmed | [DCGM feature overview](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/feature-overview.html), "Detached GPUs", read 2026-11-15 |
| Hot bind and unbind | DCGM 4.5 with driver 590 supports GPUs being unbound and rebound while DCGM runs; `dcgmi discovery -l -a` shows inactive ones as DETACHED, LOST, or INACCESSIBLE. | confirmed | Same page |
| MPS view | Under an MPS process-local view "the displayed handles, indices, and UUIDs belong to this process-local MPS namespace". | confirmed | nvidia-smi manual |

Scenarios and what the sources support:

| Scenario | UUID | Index | Bus ID | Serial | Status |
|---|---|---|---|---|---|
| Reboot | stays (documented immutable) | may change | stays on the same slot (inference) | stays | UUID and index statements confirmed from documentation; no observation |
| Driver upgrade or reinstall | documented immutable | may change | unaffected | unaffected | unverified |
| Card swap in the same slot | new UUID | unchanged or reordered | same | new | inference from "globally unique" and "slot" wording; ADR 0003's rule that a new UUID in a known slot is a possible replacement matches this |
| Slot move of the same card | stays | may change | changes | stays | inference |
| PCIe passthrough to a VM | unverified | unverified | VM assigns its own | unverified | live |
| vGPU | unverified (vGPU is out of scope, #67) | | | | live or deferred |
| RMA replacement | unverified; a replaced board should carry a new UUID | | | | live |
| MIG instances | see [mig.md](mig.md) | | | | |

## AMD

| Fact | Status | Source |
|---|---|---|
| `amd-smi list` shows BDF, UUID, KFD_ID, NODE_ID, and partition ID per entry; `-e` adds CARD, RENDER, HSA_ID, HIP_ID, HIP_UUID. | confirmed | [amdsmi CLI docs at rocm-7.2.4](https://github.com/ROCm/amdsmi/blob/1e91f3c1527617066f50c22f9ec4368fe82e1a3c/docs/how-to/amdsmi-cli-tool.md) |
| `amdsmi_get_gpu_device_uuid` is documented as "Returns the UUID of the device"; no stability statement was found. | confirmed (absence of a statement) | [amdsmi.h at rocm-7.2.4, line 2787](https://github.com/ROCm/amdsmi/blob/1e91f3c1527617066f50c22f9ec4368fe82e1a3c/include/amd_smi/amdsmi.h#L2787) |
| Partitioned parts appear as separate entries with different BDF function, KFD_ID, NODE_ID, and PARTITION_ID. | confirmed (changelog example; UUIDs redacted there) | [CHANGELOG.md at rocm-7.2.4, lines 1211-1277](https://github.com/ROCm/amdsmi/blob/1e91f3c1527617066f50c22f9ec4368fe82e1a3c/CHANGELOG.md#L1211-L1277) |
| KFD_ID and NODE_ID are kernel driver numbers. Their stability across reboots was not documented. | unverified | same |

## Consequences for ADR 0003 and the plan

- ADR 0003 is not contradicted. Its context quotes the 2021 help text for `uuid` and `index`; the current NVML header carries stronger wording for both, which can be cited instead (the decision does not change).
- The plan's identity rules need one addition: a plugin that reads through `nvidia-smi` must key by the UUID string it receives, not by DCGM GPU ID, NVML index, or KFD_ID.
- For AMD, a partition is the listing unit. If AMD partitions are monitored, the identity key is the partition's UUID, and a repartitioning event changes the set of keys; this is analogous to MIG (decision #13).
- The plan's statement "A UUID seen at a different node is recorded as moved only when an operator confirms" has no research support or contradiction; it is a design rule.
- Serial is not stored in phase 1 (ADR 0003); nothing read changes that.

## Limits

- No identifier was observed across a reboot, upgrade, or hardware change. The documentation-based rows describe vendor intent.
- Passthrough, vGPU, and RMA behavior were not documented in anything read.
- AMD UUID stability is unverified.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #19 | On a multi-GPU node record `index`, `uuid`, `pci.bus_id`, `serial` before and after a reboot, a driver upgrade, and a PCI rescan | UUID and bus ID stability; index drift |
| #19 | Swap two cards between slots and then replace one with a spare | The new-UUID-in-known-slot rule |
| #19 | Pass one GPU through to a VM and run `nvidia-smi --query-gpu=index,uuid,pci.bus_id,serial` inside and outside | Passthrough identity |
| #19 | `amd-smi list --json` before and after reboot, driver reload, and a partition mode change | AMD UUID stability; effect of repartitioning |
