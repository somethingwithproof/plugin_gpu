# 0001. Ship read-only collection first, in phases that each release

Status: Accepted (2026-11-14)

## Context

GPU management tools can change clocks, power limits, persistence mode, MIG layout, and reset a GPU. A monitoring plugin that can run those commands is a larger security surface than one that cannot, and a plan that includes remediation, scheduling, or fleet management would delay all value until every part was finished.

## Decision

Release in phases that each ship on their own:

1. NVIDIA collection through `nvidia-smi`, with per-GPU graphs, throttle, ECC, and XID signals, Thold alerting, Maint suppression, and a health page.
2. Additional backends: DCGM and `amd-smi`.
3. MIG instances and Cacti remote data collectors.

The plugin only reads. It never changes a GPU setting, resets a GPU, drains a node, or starts or stops a vendor service. Job attribution, per-process usage, fabric health, vGPU, and other vendors are research.

## Consequences

- Phase 1 is useful to anyone with NVIDIA nodes already in Cacti, and needs no write access to anything.
- No phase depends on a later one, so a stalled later phase does not strand earlier work.
- Operators who want automatic remediation use other tools; the plugin only reports.

## Alternatives rejected

- **A single full-scope release.** Delays all value and carries the largest maintenance load.
- **Remediation actions in the plugin.** Needs privilege that monitoring does not, and a mistaken action on a GPU node can stop work.
