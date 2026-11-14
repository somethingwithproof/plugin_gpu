# 0007. Run vendor tools with fixed argument lists, no shell, and no elevated privilege

Status: Accepted (2026-11-14)

## Context

Collection executes `nvidia-smi`, `dcgmi`, or `amd-smi` on or near a GPU node. Node names, device names, and GPU names are data that an operator, or a GPU's firmware, can influence. Command output is also data from a boundary the plugin does not control. A plugin that builds a command line from that data, or runs with root to get more fields, makes the monitoring system a path to the GPU node.

## Decision

- The plugin builds each command as a fixed argument vector with no shell. No operator, node, or GPU value is placed in an argument.
- Tool paths come from an administrator-set allowlist, not from a form that accepts a command.
- The plugin never uses `sudo`, setuid helpers, or root. A field that needs root is unavailable; the plugin reports it and stores `U`.
- Each execution has a timeout, an output size cap, and a minimal environment.
- Output is parsed by field position and validated before storage. A short row, an extra field, or a nonzero exit status is an error, not a value.
- Only read-only subcommands are used. Subcommands that change state are not called anywhere in the code.

## Consequences

- Some fields may be unavailable to an unprivileged user; #21 records which.
- Collection can only run where a tool can run as an unprivileged user, or where the operator has granted that user device access.
- Tests must include hostile names and malformed output.

## Alternatives rejected

- **Running with `sudo` for completeness.** Turns a monitoring plugin into a privilege path.
- **A shell wrapper script on the node.** Reintroduces interpolation and an unreviewed file outside the plugin.
