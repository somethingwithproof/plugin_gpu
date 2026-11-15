# Collection path: evidence for decision #7

Research for [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7), 2026-11-15, summarizing what the documentation and source research in this directory says about each option. Question: how do GPU metrics reach the Cacti poller? The decision is the plugin maintainer's; this page does not record an outcome.

**Result.** No option is excluded by the evidence and none dominates. Option B (SNMP `extend`) is the only one that works for every vendor with no new software on the node beyond a script, but the command runs as whoever `snmpd` runs as (root unless `agentuser` or `-u` is set), stderr is merged into the output, and the UUID cannot be the SNMP index, so the plugin needs a parser. Option C (`dcgm-exporter`) gives an authenticated and encrypted transport and reuses an NVIDIA component, but it is NVIDIA-only, needs DCGM and operator edits to enable ECC and throttle fields, changed metric names at 4.9.0, and makes the plugin issue outbound HTTP requests. Option A (poller on the node) adds no network path and needs a Cacti collector per GPU node. Option D stays rejected. The measurement half of the decision (per-poll cost, behavior under a slow tool) is entirely live. One additional fact bears on the XID decision: with B the node's script, not the plugin, decides whether the kernel log is read, which moves the privilege question to the node administrator.

## Evidence by option

| Question | A. Poller on node | B. SNMP extend | C. dcgm-exporter scrape | D. SSH |
|---|---|---|---|---|
| Vendors | all | all (script runs any tool) | NVIDIA only | all |
| New software on the node | Cacti collector (PHP, web server, database) | an `snmpd` config line and a script | DCGM and the exporter | an account and key |
| Who runs the vendor tool and as which user | the Cacti poller user | `snmpd`'s user, root by default; `agentuser` or `-u` changes it ([snmp-extend.md](snmp-extend.md)) | the exporter's user (embedded host engine) | the SSH account |
| Credential on the wire | none | SNMP community or v3 credentials Cacti already holds for the device | none by default; TLS and basic auth through `--web-config-file` ([dcgm-exporter.md](dcgm-exporter.md)) | key or password store (not proposed) |
| Command on the node controlled by | plugin (fixed list) | node administrator (fixed script) | n/a; exporter's own sampling | remote command path |
| Stable GPU key | UUID from the tool output | UUID in the script's line; not the SNMP index | `UUID` label | UUID from the tool output |
| Freshness bound | collection interval | 5 s extend cache by default plus script cadence; the script runs per cache window | exporter collect interval (30 s default) | collection interval |
| Failure modes | collector down | slow script blocks the agent (inference); stderr in the output; 100 KiB and 1024-line caps | exporter down; 503 on excess scrapes; name generation mismatch | auth and host key |
| Cacti side requirement | `remote_poller:1` and `remote_collect:1` capabilities on remote collectors ([cacti-core.md](cacti-core.md)) | SNMP polling of the device | PHP stream HTTP client; no ext-curl guarantee | external process |
| Fields available without operator work | everything the tool prints | everything the script prints | temperature, power, utilization, memory, XID value; ECC, throttle, retired pages need a counters file | everything |
| Privileged fields | none needed for the plan | none needed for the plan; the script runs with `snmpd`'s privileges whether or not it needs them | profiling fields in the default file need a privileged host engine ([privileges.md](privileges.md)) | the account |
| XID | kernel log if the script reads it | script's choice | DCGM field 230; totals via optional exporter metrics ([xid.md](xid.md)) | any |

## Inference, not fact

- B plus a documented node script is the shortest path for phase 1 because phase 1 is `nvidia-smi` only and the script is the fixed argument list ADR 0007 asks for, owned by the node administrator.
- C is a natural phase 2 transport for the DCGM backend, with a named counters file the plugin documents.
- A is the right answer where a remote data collector already sits near the nodes (phase 3), not a general transport.
- Whether B's run-as-root default is acceptable is a policy question: the script needs no privilege, so documentation should tell administrators to set `agentuser` or run the script through a dropping wrapper.

## Gaps that block a measured decision

| Measurement | Needed for | Status |
|---|---|---|
| Wall and CPU cost of each tool invocation and of an exporter scrape | all options | live ([tool-cost.md](tool-cost.md)) |
| Effect of a slow extend command on SNMP responsiveness | B | live ([snmp-extend.md](snmp-extend.md)) |
| Run-as user per distribution | B | live |
| Exporter label and name output; effect of a non-root exporter | C | live ([dcgm-exporter.md](dcgm-exporter.md)) |
| Whether Cacti can key a data query on a value with core mechanisms | B | unverified (not needed if the plugin parses) |

## Limits

This page restates findings from the other research pages; each fact has its source there. It decides nothing.

## Live checks

All live checks for #7 are the rows above, tracked in the linked pages under #21, #22, #23, and #25.
