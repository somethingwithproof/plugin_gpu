# 0008. Collect through SNMP `extend` on the GPU node

Status: Accepted (2026-11-15). Decides #7.

## Context

GPU metrics come from `nvidia-smi` or `amd-smi` on the GPU node, and the Cacti poller is usually another host. The plan listed four paths (poller on the node, SNMP `extend`, a `dcgm-exporter` scrape, SSH) and left the choice to the milestone 1 research. That research ([collection-path.md](../research/collection-path.md), [snmp-extend.md](../research/snmp-extend.md)) excluded none of A to C and found that none dominates. It confirmed from the net-snmp 5.9.5.2 source that an `extend` command runs as the user `snmpd` runs as, root unless `agentuser` or `-u` is set. It also confirmed that stderr is merged into the output, that output is capped at 100 KiB and 1024 lines, and that `snmpd` can wait for a child that never exits with no timeout.

## Decision

- The primary path is option B. The node administrator configures one `extend` entry per vendor tool in `snmpd.conf`, and the plugin reads `NET-SNMP-EXTEND-MIB` through Cacti's SNMP polling of the node's device, with the credentials Cacti already holds for that device.
- The entry runs the vendor tool with a fixed argument vector and no shell (`extend` without `-execType sh`), under `timeout` so that the child always exits. For example, `/usr/bin/timeout` with a bound below the cache window, then the tool with its query arguments. The plugin documents the exact lines; it sends no input to the node.
- `snmpd` must run as an unprivileged user (`agentuser`, or `-u`). The plan's fields need no privilege ([privileges.md](../research/privileges.md)). The documentation requires it; the plugin cannot see which user ran the command, and the live check in #22 records each distribution's default.
- SNMPv3 with authentication and privacy is the documented default. v1 and v2c work but are documented as plaintext.
- The GPU UUID comes from the output line, never from the SNMP index (ADR 0003). Output is parsed by field position and validated, and a short row, extra field, merged stderr text, a nonzero `nsExtendResult`, or truncation at the output cap is an error, not a value (ADR 0007).
- The collector stays behind its backend interface, so a later backend (DCGM, a poller on the node) can be added without changing identity or storage.

## Consequences

- Every GPU node needs `snmpd` with the extend lines. Sites that already monitor nodes over SNMP add only those lines.
- `snmpd`'s 5 s default cache and the command's run time set the freshest possible sample; the measurement rules in the plan apply to the sample, not the poll time.
- The 100 KiB and 1024-line caps are far above one node's output (one line per GPU), so truncation is treated as an error rather than planned for.
- The live checks in snmp-extend.md (run-as user per distribution, effect of a slow command on `snmpd` responsiveness) gate the phase 1 release, not M3 development.

## Alternatives rejected

- **A. Cacti poller on the node.** No network path, but a full Cacti collector on every GPU node. Kept as a later backend for sites that already run one.
- **C. Scrape `dcgm-exporter`.** NVIDIA only, needs DCGM and exporter configuration for ECC and throttle fields, renamed metrics at 4.9.0, and makes the plugin issue outbound HTTP requests. Kept as a later backend (milestone 5).
- **D. SSH from the poller.** The poller would hold a private key for every GPU node and need a remote command path. A forced command limits what runs but not the key store. Rejected, as in the plan.
