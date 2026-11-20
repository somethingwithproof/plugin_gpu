# Changelog

All user-visible changes are listed here. Versions follow [Semantic Versioning](https://semver.org/); there is no release yet.

## Unreleased

Phase 1 (NVIDIA through SNMP extend), first version 0.1.0. Not yet run on GPU hardware; see [docs/pilot-plan.md](docs/pilot-plan.md).

### Added

- GPU Operator realm and Operations page with current observations and personal acknowledgments. Existing administrators inherit Operator access; operators inherit monitoring access without configuration rights.

- Install, upgrade, disable, and uninstall through Cacti's Plugins page, refused with a message on PHP below 8.2.
- GPU Nodes (GPU Admin), GPU Inventory and GPU Health (GPU View), each limited to devices the user may see.
- GPU identity records keyed by vendor and UUID, with replacement detection, confirmed moves between nodes, absence after a configurable number of collections, and JSON export.
- Collection of `nvidia-smi` through two fixed SNMP `extend` lines on each node ([docs/node-setup.md](docs/node-setup.md)), with strict parsing that turns any bad output into unknown values.
- The *GPU - Devices* data query, the *GPU - Metrics* data template, and eleven graphs per GPU: Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages, and Sample Age.
- Decoded clock event reasons as alertable 0 or 1 indicators, with the raw mask kept and shown on GPU Health.
- The clock event reasons are read as `clocks_event_reasons.active`, falling back to `clocks_throttle_reasons.active` through an optional third extend entry, `cacti_gpu_legacy`, when nvidia-smi refuses the new name (#36).
- At most 128 GPUs per node are supported, which keeps the metrics output well under snmpd's 100 KiB limit.
- New corrected and uncorrected ECC errors since the previous sample, computed by the plugin as gauges; retired single-bit and double-bit page counts; the ECC mode, shown on GPU Health when ECC values are unknown.
- Thold threshold templates for temperature, power percentage against its limit, the hardware and thermal throttle indicators, new uncorrected ECC errors, pending page retirement, and sample age ([docs/alerting.md](docs/alerting.md)).
- The GPU Health page: freshness, last collection outcome, Maint state, backend, tool and driver versions, GPU presence, ECC state, and the raw clock event mask. It never contacts a node.
- An RRD file found at a new GPU data source's path is renamed instead of reused, so a database restored older than the RRD directory attaches no history to another GPU ([docs/backup.md](docs/backup.md)).
- *Remove the plugin's Cacti objects on uninstall* (default off): uninstall then removes the plugin's templates that nothing outside it uses, and its data query XML if unchanged. It never deletes RRD files, devices, graphs, or data sources.
- Operator, alerting, backup, performance, and security review documentation.

### Upgrade notes

- Schema version 3 adds the observation columns `ecc_corr_new`, `ecc_uncorr_new`, `retired_sbe`, `retired_dbe`, and `ecc_mode`. The upgrade runs when a signed-in user opens the Plugins page or a GPU page.
- The metrics `extend` line now also asks for `ecc.mode.current`, `retired_pages.sbe`, and `retired_pages.dbe`. Update it on every node from [docs/node-setup.md](docs/node-setup.md); until then the node's collection fails with *Output rejected*.
- Only on a node whose `nvidia-smi` is older than the clock event reasons rename, add the optional `cacti_gpu_legacy` extend line from the same page. A metrics line with the current field list that asks for `clocks_throttle_reasons.active` in the last position also works, because both names print the same bitmask there; a line with the earlier field list does not.
- Re-import `templates/gpu_thold_templates.xml` in Thold to pick up template changes. Existing power threshold rows keep their stored settings after a template re-import; edit each existing power threshold or recreate it from the template to monitor the derived `power_pct` value.

### Not yet supported

- XID errors: no source fits the fixed `nvidia-smi` commands; decision #8.
- DCGM and AMD backends (phase 2), MIG instances and remote data collectors (phase 3).
