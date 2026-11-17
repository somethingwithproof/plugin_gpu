# Operator guide

How to install, run, upgrade, and remove the plugin, what each unavailable value means, and what to check when something looks wrong. GPU node setup is in [node-setup.md](node-setup.md), alerting in [alerting.md](alerting.md), backup in [backup.md](backup.md), and measured scale in [performance.md](performance.md).

This is pre-release software. Phase 1 has been run only against a real `snmpd` with a fake `nvidia-smi` printing synthetic output, never on GPU hardware; pilots are #54.

See [Access roles](../README.md#access-roles) for the View, Operator and Admin
permissions and personal triage behavior.

## Supported matrix

| Component | Supported | Evidence |
|---|---|---|
| Cacti | 1.2.31, and the 1.2.x branch | CI on both, every PHP below |
| PHP | 8.2, 8.3, 8.4, 8.5, with the `snmp` extension | CI matrix; install is refused below 8.2 |
| Thold, Maint | Thold 1.8.2, Maint 1.3, unchanged; both optional | End-to-end alert test in CI ([alerting.md](alerting.md)) |
| Node agent | net-snmp `snmpd` with the two documented `extend` lines | End-to-end test with the distribution's net-snmp package |
| NVIDIA driver | Proposed: the R580, R595, and R620 data center lines ([research/compatibility-matrix.md](research/compatibility-matrix.md), #26) | Not run on hardware; the owner decides the list |
| AMD, DCGM, MIG, remote collectors | Not in phase 1 | Phases 2 and 3 |

**Interval.** The collector reads each node once per Cacti poller cycle, after the poll. The planned interval is Cacti's default of 300 seconds; the tests start collections directly rather than on a schedule. snmpd caches the metrics entry for 30 seconds, so an interval below about a minute reads the same sample twice. The supported minimum is open until tool cost is measured on hardware (#25).

## Least privilege

| Where | Runs as | Needs |
|---|---|---|
| Cacti web server | The web server user | Write access to `<cacti>/resource/script_server/` during install and upgrade, for the data query XML. No other file access. |
| Collector | Cacti's poller user, started by the `poller_bottom` hook | The Cacti database, as Cacti's poller has it, and network access to each node's SNMP port. It starts no other program and runs nothing on the node. |
| GPU node | The user `snmpd` runs as | Permission to run `nvidia-smi --query-gpu`, which needs no root ([research/privileges.md](research/privileges.md)). Run snmpd as an unprivileged account (`agentuser`), as [node-setup.md](node-setup.md) shows. |
| SNMP | A read-only SNMPv3 user | The system group and the extend tables only, with authentication and privacy |

The plugin stores no credential of its own. A node's SNMP settings stay on its Cacti device.

## Install

1. Copy the repository to `plugins/gpu` under the Cacti directory. The directory must be named `gpu`.
2. Make `<cacti>/resource/script_server/` writable by the web server user for the install.
3. In **Configuration > Plugins**, install and enable **GPU**. On PHP below 8.2 the install is refused with a message, and the rest of Cacti keeps working.
4. Give users the realms under **Configuration > Users**. *GPU View* opens **GPU Inventory** and **GPU Health**; *GPU Admin* opens **GPU Nodes**. The installing user gets both. Every page shows only nodes on devices the user may see in Cacti.
5. Set up each node's agent ([node-setup.md](node-setup.md)) and add it under **Management > GPU Nodes**.
6. For alerts, import the Thold templates ([alerting.md](alerting.md)).

Settings are under **Configuration > Settings > GPU**:

| Setting | Default | Meaning |
|---|---|---|
| Mark Absent After | 3 | Successful collections that must miss a GPU before it is marked absent, 1 to 100. A failed or empty collection never counts. |
| Stale After | 600 seconds | How old a sample may be before its values print `U`, 60 to 86400. `age` keeps counting. |
| Keep Plugin Tables on Uninstall | on | Uninstall keeps node and GPU records unless this is cleared. |
| Remove the plugin's Cacti objects on uninstall | off | Uninstall also removes the plugin's unused templates and its unchanged data query XML. It never deletes RRD files, devices, graphs, or data sources. |

## Upgrade

1. Back up first ([backup.md](backup.md)).
2. Replace the files under `plugins/gpu`.
3. Open **Configuration > Plugins** or any GPU page. The plugin migrates its schema in order, re-imports its templates by hash (so they are updated, not duplicated), and records the new version only after every step succeeded. An interrupted upgrade finishes on the next attempt.
4. **Update each node's metrics `extend` line** whenever the release notes say the field list changed. The plugin asks for exactly the fields of its model, so a node with the old line returns the wrong number of fields and its collection fails with *Output rejected* until the line is updated. Copy the line from [node-setup.md](node-setup.md) and restart snmpd.
5. Re-import `templates/gpu_thold_templates.xml` if you use the shipped thresholds; Thold matches the templates by hash.

Schema versions, recorded in the `gpu_schema_version` setting:

| Version | Change |
|---|---|
| 1 | Node and GPU records |
| 2 | Collection, observation, reindex, and retirement tables |
| 3 | Observation columns `ecc_corr_new`, `ecc_uncorr_new`, `retired_sbe`, `retired_dbe`, `ecc_mode`. The metrics `extend` line gains `ecc.mode.current`, `retired_pages.sbe`, and `retired_pages.dbe`. |

## Uninstall

1. Export the records under **GPU Nodes** if you want them.
2. Clear *Keep Plugin Tables on Uninstall* only if the records should be dropped.
3. Check *Remove the plugin's Cacti objects on uninstall* only if the plugin's templates and data query XML should go too.
4. Uninstall **GPU** in **Configuration > Plugins**.
5. Remove the plugin's `extend` lines (`cacti_gpu`, `cacti_gpu_names`, and `cacti_gpu_legacy` if you added it) from each node's `snmpd.conf` and restart snmpd.

Uninstall never deletes RRD files, devices, graphs, or data sources, whatever the settings.

With *Remove the plugin's Cacti objects on uninstall* checked (default off), uninstall also removes:

- each template the plugin imported, found by its hash: the *GPU Node* device template, the *GPU - Devices* data query, the *GPU - Metrics* data template, and the GPU graph templates, each only where nothing outside the plugin uses it. A device on the *GPU Node* template, a data source or graph made from a GPU template, another device template that links a GPU graph template, or a Thold threshold template on the GPU data template keeps the template it uses. A graph or data template is kept whenever the data query or device template that contains it is kept;
- `resource/script_server/plugin_gpu.xml`, only if the plugin wrote it, the file is unchanged, and the data query went.

Cacti's log lists what was removed and what was kept. With the setting off, everything stays until you delete it. Thold thresholds made from the GPU templates are Thold's and always stay. On PHP below 8.2, where the plugin cannot load, uninstall removes no Cacti object.

## Unavailable values

A value the plugin cannot vouch for is stored and printed as `U`, never as 0, so it shows as a gap in a graph and never breaches a threshold.

| Cause | Which values | How it appears |
|---|---|---|
| The GPU or driver does not report a field (`[N/A]`, `[Not Supported]`) | That field | Gap on that graph line |
| ECC is off or not supported | Every ECC and retired-page value | Gap; GPU Health shows *ECC is disabled on this GPU* or *This GPU does not report ECC* |
| The latest collection failed | Every value of the node's GPUs except `age` | Gaps; GPU Health names the failure; `age` keeps growing |
| The sample is older than *Stale After* | Every value except `age` | Gaps; GPU Health shows *Stale* |
| The GPU is absent | Every value except `age` | Gaps until it returns; `age` keeps growing; GPU Health and Inventory show *Absent* |
| First sample, a missed read, or a driver reload | `ecc_corr_new`, `ecc_uncorr_new` | Gap for one sample |
| The node's clock is more than 120 seconds ahead of Cacti's | Every value except `age` | The collection is refused, so this is a failed collection; GPU Health names the clock |
| A GPU never sampled | Every value, including `age` | Gaps; GPU Health shows *Never observed* |

`age` is unknown only for a GPU never sampled, which is why a staleness threshold on it works.

## Troubleshooting

Start at **Management > GPU Health**. It reads stored records only, so opening it never contacts a node. Each node row shows its freshness, how its latest collection ended, whether Maint holds it, and its backend, tool, and driver versions; each GPU row shows its presence, sample age, ECC state, and raw clock event mask. Plugin log lines carry the `GPU` tag in Cacti's log.

| Last collection | Likely cause | What to do |
|---|---|---|
| Tool error: SNMP agent unreachable | Agent down, wrong address or port, a firewall, or wrong SNMPv3 credentials | Check the device's SNMP settings; Cacti's own device page tests them |
| Tool error: Extend entry missing | The `extend` lines are not in `snmpd.conf`, or have other names | Add them as [node-setup.md](node-setup.md) shows |
| Timed out | `nvidia-smi` ran longer than `timeout(1)` allows (10 seconds) | Check the driver on the node; persistence mode shortens driver load |
| Permission denied | The tool refused the account snmpd runs as, or, on a node with more than the supported 128 GPUs, snmpd's own status for output above its 100 KiB limit | See [research/privileges.md](research/privileges.md); check the node's GPU count |
| Tool error: The GPU metrics command exited with status 2 | An `nvidia-smi` older than the clock event reasons rename refuses `clocks_event_reasons.active` | Add the optional `cacti_gpu_legacy` line from [node-setup.md](node-setup.md#optional-an-nvidia-smi-older-than-the-clock-event-reasons-rename) |
| Tool error: Driver not loaded | The NVIDIA driver is not loaded or does not match the library | Fix the driver on the node |
| Tool error: Tool not found | `/usr/bin/nvidia-smi` is missing | Install the driver utilities, or fix the path in the `extend` line |
| Tool error: Output rejected | A line has the wrong number of fields, usually an old `extend` line after an upgrade, or an error message mixed into the output | Copy the current line from [node-setup.md](node-setup.md) |
| Tool error: Output truncated | More output than snmpd keeps (100 KiB or 1024 lines) | Not expected at or below the supported 128 GPUs per node ([node-setup.md](node-setup.md)); report it |
| Tool error: Result not stored | The database refused the write | Check the database and Cacti's log |
| Tool error: Output rejected: The sample time is N seconds ahead of the Cacti server's clock | The node's clock is ahead of Cacti's | Fix time synchronization on the node or the Cacti server; the next collection stores a sample again |
| ...; N later runs skipped while another run held the node | A run was still collecting the node when the next started | Usually a slow node or agent; see [performance.md](performance.md) |

Other symptoms:

- **Freshness Delayed or Stale while the last collection completed**: the collector is not running every cycle. Check that Cacti's poller runs and that its log shows no GPU errors.
- **A node on Cacti 1.2.x with an SNMP port other than 161 is Down in Cacti**: see [node-setup.md](node-setup.md#an-snmp-port-other-than-161-on-cacti-12x).
- **Graphs stop or show gaps while GPU Health shows the collection completed**: Cacti could not write the RRD files, for example a full disk or wrong ownership of `rra/`. Cacti writes the RRD files itself, after the plugin has stored the sample, so in phase 1 GPU Health does not show this error; only Cacti's log does, as an `RRD` or `ERROR` line from the poller. Fix the disk or the permissions; the next poll writes again.
- **No graphs for a new GPU**: the plugin reindexes only while Cacti has the device Up; it retries once the device is Up again.
- **An RRD file renamed with `.gpu-orphan-` in its name**: a new GPU's data source found an RRD file already at its path, usually after a database restore older than the RRD directory. The file was set aside so the new GPU starts its own history ([backup.md](backup.md)). If the rename fails, the data source stays disabled and the plugin retries each cycle; correct the directory permissions or move the old file aside before enabling it.
