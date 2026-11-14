# 0002. Alert through polled Cacti data sources, not a plugin alert engine

Status: Accepted (2026-11-14)

## Context

Operators want GPU faults to reach the same notification path as their other Cacti alerts, with Maint suppression. Thold receives values through Cacti's `poller_output` hook, so it only sees data sources that the Cacti poller polls.

## Decision

Per-GPU values, including the decoded throttle indicators, ECC and retired-page values, the last XID where available, and an `age` value, are fields of the data query, read by a script server input from the plugin's cache. Thold thresholds and Maint schedules apply to those data sources with no change to either plugin. Unavailable values print `U`; `age` is always numeric so staleness can alert.

## Consequences

- One notification path for SNMP and GPU data; no Thold or Maint change.
- Alerts see sampled values. A fault that starts and ends between two collections is not seen.
- Cacti's poll interval and the collection interval are independent, so a value can be reported twice or skipped. Thold durations should be at least one collection interval.
- Values are stored once in the plugin cache and again in Cacti's RRD files.

## Alternatives rejected

- **A plugin-owned alert engine.** Duplicates Thold and Maint and adds a third notification path.
- **Forwarding vendor alerts.** Vendor tools have no common alert interface, and the result would bypass Maint.
