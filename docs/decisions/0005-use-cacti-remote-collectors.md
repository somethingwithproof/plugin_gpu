# 0005. Reach remote GPU nodes through Cacti remote data collectors, not a custom agent

Status: Accepted (2026-11-14)

## Context

GPU nodes in other sites cannot always be reached from the main Cacti server. Cacti already has remote data collectors. In `release/1.2.31`, `call_remote_data_collector()` is in `lib/functions.php` and the `remote_agent` hook is dispatched from `remote_agent.php`. Which collection path the plugin uses locally is a separate, open decision (#7); this record covers only distribution across sites.

## Decision

Distributed collection uses Cacti remote data collectors. The collector runs the plugin's collection and script server input and reports through Cacti's normal poller output path. The main server asks the collector for inventory and health through `remote_agent` actions that take no command, path, or URL from the caller. The plugin ships no daemon, socket, or credential store.

## Consequences

- No new protocol or credential store to secure.
- A site that needs remote collection runs a full Cacti remote collector.
- `remote_agent` authorizes callers by address, not cryptographically, so documentation requires https and a restricted network between pollers.
- Remote CLI jobs must handle the `offline` and `recovery` connection modes.

## Alternatives rejected

- **A plugin agent on each GPU node.** A new daemon, credential store, and protocol, duplicating Cacti's collectors.
- **SSH from the main server.** Needs key management and a remote command path.
