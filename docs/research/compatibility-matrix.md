# Hardware, driver, and virtualization matrix

Research for [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26), 2026-11-15. Question: which GPU generations, driver lines, ROCm releases, operating systems, and virtualization modes will the plugin support, and which fields does each lack (power, ECC, retired pages)?

**Result.** Vendor lifecycle pages give dated support windows for NVIDIA driver lines and ROCm; the plugin's own support list is a decision for the owner, so the table below proposes lines and marks them as proposals. NVIDIA currently lists three supported data center driver lines: R580 (long term support, end of life June 2028), R595 (production, March 2027), and R620 (long term support, September 2029). Volta, Pascal, and Maxwell have R580 as their last driver; Kepler's is R470. DCGM supports virtualization only as "full passthrough". AMD's current ROCm is 10.1.0 (2026-08-26), whose compatibility page lists Instinct MI100 to MI355X. Which fields each GPU class lacks is not documented in one place; the sources show ECC and power as conditional, and the per-class table is a live check. No pilot hardware exists in this repository's resources, so fixture capture and pilots are listed as targets, not done.

## Method

Read NVIDIA's Data Center Drivers lifecycle, supported-driver, and architecture pages (page footers dated "Last updated on Nov 04, 2026"), the DCGM getting-started page, the NVIDIA Linux driver README (FAQ), the NVML header notes, and the ROCm 10.1.0 compatibility page (dated 2026-08-26). All fetched 2026-11-15. Nothing was run.

## NVIDIA driver lines

| Line | Branch type | End of life | Status | Source |
|---|---|---|---|---|
| R580 | Long Term Support Branch | June 2028 | confirmed | [Supported Drivers and CUDA Toolkit Versions](https://docs.nvidia.com/datacenter/tesla/drivers/supported-drivers-and-cuda-toolkit-versions.html), Table 2 |
| R595 | Production Branch | March 2027 | confirmed | same |
| R620 | Long Term Support Branch | September 2029 | confirmed | same |
| Branch policy | Production branch: bug fixes and security updates up to 1 year, two branches per year. LTSB: 3 years. New Feature Branches are short-lived with no end-of-life date. | confirmed | [Driver Lifecycle](https://docs.nvidia.com/datacenter/tesla/drivers/driver-lifecycle.html) |
| Latest Linux x86_64 driver on the download server | 620.70.06 | confirmed | `download.nvidia.com/XFree86/Linux-x86_64/latest.txt`, read 2026-11-15 |

| Architecture | Last driver support | Source |
|---|---|---|
| Blackwell, Hopper, Ada, Ampere, Turing | Ongoing | [CUDA Toolkit, Driver, and Architecture Matrix](https://docs.nvidia.com/datacenter/tesla/drivers/cuda-toolkit-driver-and-architecture-matrix.html), Table 3 |
| Volta, Pascal, Maxwell | R580 | same |
| Kepler | R470 | same |

## NVIDIA tooling

| Item | Fact | Source |
|---|---|---|
| DCGM platform support | "All NVIDIA Kepler (K80) and newer datacenter GPUs", Maxwell and newer non-datacenter GPUs (limited functionality), "CUDA 7.5+ and NVIDIA Driver R450+", "Bare metal and virtualized (full passthrough only)". | [DCGM getting started](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/getting-started.html) |
| DCGM host requirements | Host RAM at least 16 GB; CPU cores at least the number of GPUs. | same |
| DCGM with R580 and Maxwell, Volta, or Pascal | Install the packages for CUDA user-mode driver major version 12 (`datacenter-gpu-manager-4-cuda12`), not 13. | same |
| DCGM distributions (x86_64 and aarch64) | Amazon Linux 2023, Azure Linux 3.0, Debian 12 and 13, Fedora 43, RHEL and Rocky 8, 9, 10, SLES and openSUSE 15 and 16, Ubuntu 22.04, 24.04, 26.04. | same |
| DCGM versions compared for fields | v3.3.9 and v4.7.0 (IDs identical; names and scale differ) | [dcgm.md](dcgm.md) |
| dcgm-exporter | 4.9.0 default counters need DCGM 4.7.0 field names (for example `DCGM_FI_DEV_FB_USED_BYTES` first appears at v4.7.0); older exporters use legacy names | [dcgm.md](dcgm.md), [dcgm-exporter.md](dcgm-exporter.md) |
| Device files | Default mode 0666 root:root; sites may restrict with a group | [privileges.md](privileges.md) |
| nvidia-smi output | "not guaranteed to be backwards compatible"; use NVML for stable interfaces | [nvidia-smi-fields.md](nvidia-smi-fields.md) |

## AMD

| Item | Fact | Source |
|---|---|---|
| Current ROCm | 10.1.0, matrix dated 2026-08-26; AMD SMI 27.1.0 | [ROCm compatibility matrix](https://rocm.docs.amd.com/en/latest/compatibility/compatibility-matrix.html) |
| Instinct GPUs listed | MI355X, MI350X, MI350P (gfx950), MI325X, MI300X, MI300A (gfx942), MI250X, MI250, MI210 (gfx90a), MI100 (gfx908) | same |
| Linux for Instinct | Ubuntu 22.04.5, 24.04.5, 26.04.1; RHEL 8.10, 9.4, 9.6, 9.8, 10.0, 10.2; Debian 12 and 13; Oracle Linux 8 to 10; Rocky Linux 9; SLES 15.7 and 16.0 (varies by GPU series) | same |
| `amd-smi metric --violation` | MI300 or newer only | [amd-smi.md](amd-smi.md) |
| rocm-smi | Not listed in ROCm 10.1.0; critical fixes only since ROCm 7.0 | [amd-smi.md](amd-smi.md) |
| Radeon and Ryzen entries | Present in the matrix; the plan targets data center GPUs, and the matrix does not say what `amd-smi` reports for them | same |

## Virtualization and containers

| Mode | What the sources say | Status |
|---|---|---|
| Bare metal | Supported by every tool | confirmed |
| PCIe passthrough | DCGM: "full passthrough only" | confirmed (DCGM); nvidia-smi and amd-smi unverified |
| vGPU | DCGM documentation lists a vGPU section; the plan marks vGPU as research (#67) | out of scope |
| MPS | nvidia-smi can show a process-local MPS view in which UUIDs belong to that namespace and `pci.*` fields are `N/A` | confirmed; the plugin must not run inside an MPS view |
| Containers | The DCGM page says requirements apply equally to containers; nothing read says how `nvidia-smi` behaves in a container beyond that | unverified |

## Fields that may be missing

| Field | Sources | Status |
|---|---|---|
| `power.draw`, `power.limit` | "Only available if power management is supported"; `power.management` can read `[Not Supported]` | confirmed (snapshot) |
| ECC counts | NVML: "Only applicable to devices with ECC"; ECC mode must be enabled | confirmed (header) |
| `retired_pages.pending` | The field exists in the help snapshot; DCGM also has a row-remap failure field (395). Which GPU generations support which was not found in anything read. | unverified per class |
| Throttle reasons `Display Clock Setting` | Volta only | confirmed |
| `utilization.gpu` on MIG-enabled GPUs | Not supported on the parent | confirmed (header); see [mig.md](mig.md) |
| `amd-smi` throttle accumulators | MI300 or newer | confirmed |
| `power.draw` accuracy | "within +/- 5 watts" | confirmed |

## Proposed support set (for the owner to confirm)

This is a proposal derived from the vendor lifecycle pages, not a vendor statement.

| Area | Proposal | Reason |
|---|---|---|
| NVIDIA drivers | R580, R595, R620 (whatever NVIDIA lists as supported at each release); older lines best effort | Matches vendor-supported lines |
| GPU generations | Volta to Blackwell on data center products; consumer GPUs best effort | DCGM supports "limited functionality" on non-datacenter GPUs |
| DCGM | 4.x for the DCGM backend; compare any line the pilot site runs | Two tags compared; fields identical by ID |
| dcgm-exporter | 4.9.0 and 4.8.4 parsed; older generations best effort | Names changed at 4.9.0 |
| AMD | `amd-smi` on ROCm 7.x and 10.x with Instinct MI210 and newer; no `rocm-smi` | See [amd-smi.md](amd-smi.md); MI100 is the oldest Instinct listed and was not checked |
| OS | Distributions in the DCGM and ROCm lists that also run Cacti 1.2 | Both lists read; Cacti's own support list not part of this issue |
| Virtualization | Bare metal and passthrough; no vGPU; no MPS view | Sources above |

## Fixture and pilot targets

| Target | Kind | Needed hardware | Status |
|---|---|---|---|
| nvidia-smi on R580, R595, R620 (one per line), 1 and 8 GPUs | fixtures | NVIDIA data center GPU nodes | not captured |
| nvidia-smi on a GPU without ECC and one without power reporting | fixtures | Consumer or older GPU | not captured |
| DCGM 3.3.x and 4.7.x `dcgmi` and exporter output | fixtures | Node with DCGM | not captured |
| amd-smi on MI210 or MI250 and on MI300 | fixtures | AMD Instinct nodes | not captured |
| MIG-enabled A100 or H100 | fixtures | MIG GPU | not captured |
| A site with 8 or more GPUs for the phase 1 pilot | pilot | Real production-like node | not arranged |

## Limits

- Cacti's own supported PHP, database, and distribution lists were not part of this research.
- ROCm releases before 7.0 were not read; ROCm 7.x tags were read for the CLI only.
- Nothing was run; no fixture exists.
- NVIDIA's lifecycle pages say dates and cadence are "general guidance only".

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #26 | Capture the plan's query output on each proposed driver line and each GPU class in the fixture table | Per-class missing fields |
| #26 | Same under passthrough to a VM and in a container with the NVIDIA runtime | Virtualization and container behavior |
| #26 | Same on the pilot node | Whether the proposed support set holds in practice |
