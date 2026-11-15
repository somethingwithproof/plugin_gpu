# dcgm-exporter scrape as a transport

Research for [#23](https://github.com/somethingwithproof/plugin_gpu/issues/23), 2026-11-15. Question: can the plugin read `dcgm-exporter` output as its transport, and what does that cost in trust and freshness? The documented defaults are a `:9400` listener and a 30000 ms collection interval.

**Result.** The defaults hold, and the exporter documents TLS and basic authentication through the Prometheus exporter-toolkit web config file, so an authenticated and encrypted scrape is possible when the operator sets it up. The costs are in the content, not the protocol: the exporter's default metrics omit ECC, throttle, and retired-page fields (an operator must edit a counters file), its metric names and units changed between 4.8.4 and 4.9.0 (legacy names and percent and MiB, against `_CELSIUS`, `_RATIO`, and `_BYTES` names), its default file includes profiling fields that need a privileged host engine, and the age of a value is bounded only if the exposition prints the sample timestamp (the collection interval alone is not a bound). The plugin would make an outbound HTTP request to an operator-configured URL, using PHP's stream wrapper because Cacti core does not require the curl extension. No scrape was performed, so label and value samples, TLS behavior, and freshness are live checks.

## Method

Read `dcgm-exporter` 4.9.0 source (flags, defaults, server, counters, labels), the default counters at four tags, the DCGM documentation page for the exporter command line, and Cacti's HTTP client code (from the plugin_cloud research, re-checked for presence). No exporter was started and no URL scraped.

## Findings

| Fact | Status | Source |
|---|---|---|
| Listener default `:9400`; flag `--address` (`DCGM_EXPORTER_LISTEN`); IPv6 as `[::]:9400`. Metrics at `/metrics`, health at `/health`. | confirmed | [`defaults.go`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/appconfig/defaults.go), [`app.go`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/pkg/cmd/app.go) lines 160-170, [`server.go:164-168`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/server/server.go#L164-L168) |
| Collect interval default 30000 ms (`--collect-interval`, `DCGM_EXPORTER_INTERVAL`); must be greater than 0. | confirmed | `defaults.go`; `validate_test.go` expectation of the error text |
| `--web-config-file` "following the exporter-toolkit spec" enables TLS and authentication, applies to the root, health, metrics, and enabled endpoints, and replaces `--address` for the listeners it defines. | confirmed (flag and reference page); behavior unverified | `app.go:351-356`; [command reference](https://docs.nvidia.com/datacenter/dcgm/latest/reference/command-line-reference/dcgm-exporter.html), read 2026-11-15 |
| `--max-concurrent-scrapes`: "Excess requests receive HTTP 503 while admitted overlapping requests are coalesced." Read and write timeouts are flags. | confirmed | `app.go:174-192` |
| Without `--remote-hostengine-info` the exporter embeds a host engine; with it, it connects to a standalone `nv-hostengine` (default `localhost:5555`). | confirmed | command reference |
| Default counters (4.9.0): SM and memory clock in hertz, memory and GPU temperature, board power, utilization ratios, `DCGM_FI_DEV_XID_ERROR`, framebuffer capacity, free, used, and reserved in bytes, NVLink throughput, row remap fields, and `DCGM_FI_PROF_*` fields. Commented out: ECC totals, throttle seconds counters, `DCGM_EXP_XID_ERRORS_*`, `DCGM_EXP_CLOCK_EVENTS_*`, and clock event reasons (field 112). | confirmed | [`etc/default-counters.csv` at 4.9.0](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/etc/default-counters.csv) |
| Names and units changed: tags `3.3.9-3.6.1`, `4.4.2-4.7.1`, and `4.8.4` carry `DCGM_FI_DEV_GPU_TEMP`, `..._POWER_USAGE`, `..._GPU_UTIL`, `..._FB_USED`, `..._XID_ERRORS`; 4.9.0 carries `..._GPU_TEMP_CELSIUS`, `..._BOARD_POWER_WATTS`, `..._GPU_UTIL_RATIO`, `..._FB_USED_BYTES`, `..._XID_ERROR`. | confirmed | `etc/default-counters.csv` at each tag (see [dcgm.md](dcgm.md) for pins) |
| Labels for GPU entities in the built-in set: `gpu`, `UUID` (`uuid` with `--use-old-namespace`), `device`, `modelName`, `pci_bus_id`, `hostname`, `GPU_I_ID`, `GPU_I_PROFILE`, `GPU_CI_ID`. | confirmed from source; rendered text unverified | [`labels.go`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/metriclabels/labels.go); `gpu_collector.go:924` |
| Each sample carries a timestamp: the DCGM sample time when present, otherwise the read time (`metricTimestamp`). Whether it is printed in the exposition format is unverified. | confirmed (source); output unverified | [`gpu_collector.go:880-885`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/collector/gpu_collector.go#L880-L885) |
| XID totals and window counts are accumulated in the exporter's memory and reset on restart. | confirmed | [xid.md](xid.md) |
| Cacti core does not require `ext-curl` (`composer.json` has none at either ref) and `call_remote_data_collector()` uses `file_get_contents()` with a stream context with `follow_location` 0; the plugin_cloud research found PHP's https wrapper reads the `http` context key, so a timeout set under the `https` key is ignored (PHP 8.0.30). | confirmed (carried over; presence of the code re-checked in [cacti-core.md](cacti-core.md)) | plugin_cloud `docs/research/cacti-core.md`; Cacti `lib/functions.php:6406-6409` at 1.2.31 |

## Freshness

A successful scrape does not bound the age of a value. The exporter keeps DCGM's sample timestamp, and its collector tracks profiling fields whose timestamp stays unchanged for longer than a window ([`gpu_collector.go:65-114`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/collector/gpu_collector.go#L65-L114)), so a value can be older than the 30 s collection interval. The plan's `age` field must come from a source timestamp when the exposition prints it; otherwise observation age is unknown and the plugin reports `age` as unknown (`U`), not as the age of its last scrape. The Cacti poll cycle and the plugin's cache add further delay. The collect interval can be lowered with `--collect-interval`; its cost on a node was not measured (see [tool-cost.md](tool-cost.md)).

## Request controls the plugin needs

These follow the plan's security row and are limits of design, not exporter facts:

- Allowed host list (operator-configured URLs only; the scrape URL is never taken from a request).
- No redirects (`follow_location` 0), a size limit on the body, and a total time limit set through a context that PHP's HTTPS wrapper honors (the key is `http` for both schemes).
- TLS verification on by default and basic auth supported only when the operator has configured it, with no secret in logs. Cacti's `allow_unsafe_https` setting disables verification; the plugin must not read it as permission to disable.
- Parse the exposition text by label match on `UUID`, reject duplicate series, and map both name generations explicitly.

## Fit with the plan

| Item | Assessment |
|---|---|
| Coverage | NVIDIA only; needs DCGM on every GPU node plus the exporter |
| Metrics without operator work | Temperature, power, utilization, memory, XID value, clocks; ECC, throttle, and retired pages need a counters file |
| Compatibility | Two metric-name generations; DCGM versions of the exporter and of the host engine must match (the exporter 4.9.0 image installs a pinned `datacenter-gpu-manager-4` package) |
| Privilege | Exporter user decides; default counters include profiling fields that need a privileged host engine |
| Security | An outbound request to an operator URL (server-side request boundary); no credential stored by the plugin if the exporter is unauthenticated on a trusted network; TLS and basic auth exist when needed |
| Cost | One HTTP request per node per collection; no command execution on the node by the plugin |

## Limits

- No exporter was run; the exposition text, label values, timestamp rendering, and TLS behavior were not observed.
- Exporter releases between those pinned were not read.
- The exporter's behavior when a field is unsupported or unavailable (blank, absent series, or an error) was not read.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #23 | `curl` the `/metrics` of exporter 4.9.0 and 4.8.4 on one node; save text | Exact names, labels, units, timestamps |
| #23 | Add the ECC, throttle, and retired-page fields to a counters file and scrape | Whether the operator edit is enough to supply the plan's fields |
| #23 | Change `--collect-interval` to 5000 and 60000; compare sample ages | Freshness bound and the cost of a shorter interval |
| #23 | Enable `--web-config-file` with TLS and basic auth; scrape from PHP's stream wrapper with `verify_peer` on | Whether the plugin's client works against the authenticated listener |
| #23 | Unplug or unbind a GPU while scraping | Missing-series behavior |
| #23 | Run the exporter as a non-root user without a privileged host engine | Fields returned as unavailable (profiling) |
