# 0010. Attach the GPU data query to the node's existing Cacti device

Status: Accepted (2026-11-15). Decides #10.

## Context

GPU nodes are usually already Cacti devices, monitored over SNMP for CPU, memory, and interfaces. The plugin can add its data query to that device, or create a device of its own for each node. ADR 0004 already makes each GPU an index of a data query on the node's device, and ADR 0008 reads the GPU output through SNMP on that device, with the credentials Cacti holds for it. The plan proposed attaching to the existing device; the owner accepted the proposed option on 2026-11-15.

## Decision

- An operator maps a node to an existing Cacti device on the GPU Nodes page. Saving the mapping is the confirmation: the plugin never infers a mapping from host names or addresses.
- Only devices the acting user may see are offered or accepted.
- On save, the plugin adds the GPU data query to that device through Cacti's API (`api_device_dq_add()`) with reindex method None, and it repairs the attachment if a later device template change removes or resets it.
- The plugin creates a device only when an operator asks for one because none exists. It uses Cacti's `api_device_save()` with the site's device defaults and the plugin's GPU Node device template, and the operator then edits the SNMP settings on Cacti's own device page.
- Deleting a mapped device disables its node through the `device_remove` hook. The node and its GPU records stay, and so do the device's graphs, data sources, and RRD files until Cacti's own device deletion removes them.

## Consequences

- GPU graphs sit next to the node's other graphs, under the device's permissions, and Thold and Maint scope them through the device as they do for every other graph.
- The plugin must not assume the device's host fields describe anything but the SNMP agent: the GPU path is the agent's `extend` table (ADR 0008).
- All device and data query changes go through Cacti's API functions, never direct writes to Cacti's tables.

## Alternatives rejected

- **Plugin-owned devices.** Isolates plugin objects, but splits one machine across two devices and complicates Thold and Maint scoping.
