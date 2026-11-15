# Privileges each vendor tool needs

Research for [#21](https://github.com/somethingwithproof/plugin_gpu/issues/21), 2026-11-15. Question: can `nvidia-smi`, `dcgmi`, and `amd-smi` run as an unprivileged user for the plugin's queries? What do they need: device file access, group membership, a running `nv-hostengine`, or root for some fields?

**Result.** For NVIDIA, the driver README says the device files are created `root:root` with mode 0666 by default, so a default install lets any local user open the GPU; read-only queries need no group or root unless the site sets `NVreg_DeviceFileMode` and a group. Nothing in the manuals marks the plugin's query fields as root-only, and the manual's exit code 4 ("The current user does not have permission") is the documented denial result. DCGM can run as non-root but profiling metrics need `nv-hostengine` started with superuser privileges, and the exporter's 4.9.0 default file includes profiling fields. For AMD, `amd-smi static` says "Sudo may be required for some features", while `list` and `metric` carry no such note; the group requirements were not documented in anything read. The kernel log is restricted when `kernel.dmesg_restrict` is 1 (requires `CAP_SYSLOG`), so a log reader is a privileged choice on hardened hosts. Actual behavior as the Cacti poller user, as the `snmpd` user, in containers, and under SELinux or AppArmor is not documented and is the live part of this issue.

## Method

Read the NVIDIA Linux driver README (FAQ) at 620.70.06, the nvidia-smi manual, the DCGM getting-started and feature overview pages, the `dcgm-exporter` command reference and source, the amdsmi CLI documentation at `rocm-7.2.4`, and the kernel's sysctl and `/dev/kmsg` documentation. Nothing was run.

## Privilege table (documentation and source)

| Tool and query | Documented requirement | Status | Source |
|---|---|---|---|
| NVIDIA device files | Created `UID 0, GID 0, Mode 0666` by default; sites can set `NVreg_DeviceFileUID`, `NVreg_DeviceFileGID`, `NVreg_DeviceFileMode` (the README's example is GID 44, mode 0660). Loading the module and creating files "generally require root privileges" but a setuid `nvidia-modprobe` does this for unprivileged clients. | confirmed | [NVIDIA Linux driver README 620.70.06, FAQ](https://download.nvidia.com/XFree86/Linux-x86_64/620.70.06/README/faq.html), read 2026-11-15 |
| `nvidia-smi --query-gpu` (the plan's fields) | No privilege statement for these queries. Setter options (power limit, clocks, MIG, reset) are the managed operations. The exit code 4 is "The current user does not have permission to access this device or perform this operation". | confirmed (absence of a requirement); unprivileged run unverified | [nvidia-smi manual](https://docs.nvidia.com/deploy/nvidia-smi/index.html), read 2026-11-15 |
| `nvidia-smi mig` management | "the privileged command that manages MIG" per the plan; never run | confirmed in the plan's earlier reading | nvidia-smi manual |
| `nv-hostengine` and `dcgmi` | "DCGM can run as root or non-root. Some DCGM functionality, such as configuration management, are not allowed to be run as non-root." The packaged unit runs `nv-hostengine -n --service-account nvidia-dcgm`. In both modes "the DCGM library should be run as root. Many features will not work without privileged access to the GPU". | confirmed (statements conflict mildly; the stricter reading is "root recommended") | [DCGM getting started](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/getting-started.html), read 2026-11-15 |
| DCGM profiling fields (`DCGM_FI_PROF_*`) | "Profiling of the GPU counters requires administrator privileges starting with Linux drivers 418.43 or later ... ensure that nv-hostengine is started with superuser privileges." | confirmed | [DCGM feature overview](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/feature-overview.html) |
| DCGM XID events | The host engine reads `/dev/kmsg` in addition to NVML events; access to `/dev/kmsg` is the host's. | confirmed (source); user needed unverified | [DcgmKmsgReader.cpp:165 at v4.7.0](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/src/DcgmKmsgReader.cpp#L165) |
| `dcgm-exporter` 4.9.0 default counters | Includes `DCGM_FI_PROF_GR_ENGINE_UTIL_RATIO`, `..._TENSOR_UTIL_RATIO`, `..._DRAM_UTIL_RATIO`, `..._PCIE_TX_BYTES`, `..._PCIE_RX_BYTES`. | confirmed; effect without privilege unverified | [`etc/default-counters.csv` at 4.9.0](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/etc/default-counters.csv) |
| `amd-smi list`, `metric` | No privilege statement. | confirmed (absence) | [amdsmi CLI docs at rocm-7.2.4](https://github.com/ROCm/amdsmi/blob/1e91f3c1527617066f50c22f9ec4368fe82e1a3c/docs/how-to/amdsmi-cli-tool.md) |
| `amd-smi static` | "Sudo may be required for some features". | confirmed | same, line 170 |
| `amd-smi set`, `reset` | "Requires 'sudo' privileges." | confirmed | same, lines 557 and 640 |
| Kernel log via `dmesg` | `kernel.dmesg_restrict=1` means "users must have CAP_SYSLOG to use dmesg(8)"; the compile-time default is `CONFIG_SECURITY_DMESG_RESTRICT`. The `/dev/kmsg` interface is documented separately; its permissions are the device node's. | confirmed | [kernel sysctl documentation](https://www.kernel.org/doc/html/latest/admin-guide/sysctl/kernel.html); [ABI testing dev-kmsg](https://www.kernel.org/doc/Documentation/ABI/testing/dev-kmsg) |
| Containers, SELinux, AppArmor | Not documented in anything read for these tools. | unverified | none |

## Queries the plugin must not run

From the documentation read (inference from the "managed operation" language, not a vendor list of root-only queries):

- Any `nvidia-smi` setter or `mig`, `-r`, `-pm`, `-pl`, `-lgc`, `-lmc`, `-ac` option, any `dcgmi` command that sets configuration, sets a policy, injects errors, or runs diagnostics, any `amd-smi set` or `reset`, and `sudo` in any form. ADR 0007 already covers this by allowlisting read subcommands.
- DCGM profiling fields by default, since they need a privileged host engine and the plugin must report them unknown rather than ask for privilege.

## Consequences for the plan

- ADR 0007's rule "no elevated privilege" is consistent with the documentation for `nvidia-smi --query-gpu` and `amd-smi list`/`metric`. It is a live check for each, not a documented guarantee.
- A kernel log reader's privilege (decision #8) is a host setting: on a host with `dmesg_restrict=1` it needs `CAP_SYSLOG` or a group-readable copy of the log. The plugin has no way to grant it and must not ask for it (no sudo).
- Path C (the exporter) moves the privilege question to whoever runs the exporter: the operator chooses the exporter's user and counters file. The plugin should state which default counters it needs and that profiling fields are not among them.

## Limits

- No tool was run as any user. The "unprivileged" rows are documentation absences, not tests.
- The default group of a Cacti poller process (`cacti` user, `www-data`) and of `snmpd` (see [snmp-extend.md](snmp-extend.md)) on each distribution is not documented in these sources.
- SELinux and AppArmor profiles shipped by distributions were not read.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #21 | Each plan query (`nvidia-smi --query-gpu=...`, `dcgmi discovery -l`, `dcgmi dmon -e <ids> -c 1`, `amd-smi list/metric --json`) as an unprivileged user, as the Cacti poller user, and as the `snmpd` user | The privilege table's actual values |
| #21 | Same on a host with `NVreg_DeviceFileMode=0660` and a group | Whether group membership suffices and which group |
| #21 | Same inside a container and on distributions with SELinux enforcing and AppArmor | Whether profiles block the tools |
| #21 | Read `/dev/kmsg` and run `dmesg` as those users with `dmesg_restrict` 0 and 1 | The kernel log answer for #8 |
| #21 | `dcgmi dmon` of a profiling field with a non-root host engine | What the plugin sees for fields it must not request |
