# 0004. Represent each GPU as an index of a data query on the node's device

Status: Accepted (2026-11-14)

## Context

A GPU node is normally already a Cacti device. GPUs come and go, and a node can have 16 of them. Creating a device per GPU would split one machine across many devices and complicate permissions, Thold scoping, and Maint. Cacti's data query mechanism creates one data source and graph set per index of a table, which fits GPUs.

## Decision

Each GPU is an index of one script server data query on the node's device, keyed by the identity key in ADR 0003. The index listing includes absent GPUs, because Cacti marks a data source orphaned when its index leaves a reindex (`lib/data_query.php` at `release/1.2.31`), and an absent GPU's history should stay attached. Absent GPUs print `U`. The plugin triggers its own reindex after inventory changes and sets the data query's reindex method to None. Removing an index is an explicit operator action.

## Consequences

- GPU graphs sit next to the node's other graphs under its permissions.
- A new GPU needs no new device.
- The data query XML must live under `<path_cacti>/resource` in 1.2.31, so the plugin copies it there; this is its only write outside its own directory.
- A node that is not yet a Cacti device must be created first.

## Alternatives rejected

- **A device per GPU.** Splits a machine across devices.
- **One graph per node with all GPUs as data source items.** Cannot show a variable GPU count without editing templates.

## Revision note (2026-11-15)

Milestone 1 research ([cacti-core](../research/cacti-core.md)) confirmed the orphan behavior and the `resource/` confinement above at `release/1.2.31` and `1.2.x`. Two refinements: the reindex method is reset to the global default by `api_device_update_host_template()` when a device template carrying the data query is applied (not by `api_device_save()` itself), and a data query XML can set `index_transient` so a removed index is not marked orphan at all. M3 chooses between that and keeping absent GPUs in the listing.
