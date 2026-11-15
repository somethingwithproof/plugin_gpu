# How XID errors are surfaced

Research for [#16](https://github.com/somethingwithproof/plugin_gpu/issues/16) and the evidence step of decision [#8](https://github.com/somethingwithproof/plugin_gpu/issues/8), 2026-11-15. Question: is `DCGM_FI_DEV_XID_ERROR` a usable alert signal, and what are the alternatives? The header calls its value the last XID, so what does it report after repeated XIDs of the same value, after several different XIDs, and after recovery or a driver reload?

**Result.** DCGM source shows that field 230 is not a single mutable "last value": DCGM subscribes to NVML XID events and appends one sample to the field's history for every event, whether or not the value repeats, and DCGM also feeds the field from `/dev/kmsg`. A reader that asks for the latest value once per collection interval sees only the newest XID and cannot tell a repeat from the same event seen twice, so it cannot count. A reader that asks for all samples since a cursor can count; `dcgm-exporter` does this for its optional `DCGM_EXP_XID_ERRORS_TOTAL` and `_COUNT` metrics, which are disabled in the default counters. NVML offers the event itself through an event set, but a PHP plugin that shells out to `nvidia-smi` cannot use it. `nvidia-smi event-log` exists in recent versions, but the manual does not say it reports XIDs. Whether a field clears after recovery, what a driver reload does to the history, and which user may read the kernel log need hardware. **The evidence for #8 therefore points away from a plain DCGM field read in phase 1 and leaves the choice to the owner** (see Options).

## Method

Read DCGM `v4.7.0` source for the cache manager's XID handling, `dcgm-exporter` 4.9.0 source and default counters, the NVML 13.4.92 header, the nvidia-smi manual and changelog, and NVIDIA's "Working with Xid Errors" page. Nothing was run, and no XID was induced.

## Findings

| Fact | Status | Source |
|---|---|---|
| Field 230 is documented as "XID errors. The value is the specific XID error". | confirmed | [dcgm_fields.h v4.7.0:821-823](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/dcgm_fields.h#L821-L823) |
| DCGM's event thread subscribes every attached GPU to `nvmlEventTypeXidCriticalError`; a comment says "We always subscribe for XIDs so that we have SOMETHING watched. Otherwise, the event thread won't start". | confirmed | [DcgmCacheManager.cpp:3436, 3464](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/src/DcgmCacheManager.cpp#L3436) |
| For each XID event, `RecordXidForGpu()` calls `AppendEntityInt64()` with the event data and the event time. There is one call per event and no comparison with an earlier value in the function. For a MIG-enabled GPU the XID is attributed to the GPU, a GPU instance, or a compute instance entity. | confirmed by reading; behavior of the history under retention limits not observed | [DcgmCacheManager.cpp:8891-8915, 9239-9255](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmlib/src/DcgmCacheManager.cpp#L8891-L8915) |
| DCGM also reads XIDs from `/dev/kmsg` through a reader thread, maps the PCI address to a GPU, and records them through the same function. A default fatal XID set is `{119}`, overridable with an environment variable; a fatal XID logs "Hostengine restart required". Other fields can return a blank value when an XID is detected in `/dev/kmsg`. | confirmed | DcgmCacheManager.cpp:8991-8995, 9132-9175, 6623, 7936; `DcgmKmsgReader.cpp:165` (`m_kmsgFilename("/dev/kmsg")`) |
| The sample history is bounded by the watch's maximum age and sample count. | confirmed in the code that computes `expireTime`; the dcgm-exporter flags `--watch-max-keep-age` and `--watch-max-keep-samples` set it for exporter-created watches | `RecordXidForGpu`; [`app.go` at 4.9.0](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/pkg/cmd/app.go) |
| `dcgm-exporter` can accumulate XIDs. `xidTotalCollector` calls `GetValuesSince(group, fieldGroup, cursor)` and counts each valid sample per XID value; metric `DCGM_EXP_XID_ERRORS_TOTAL` carries an `xid` label, `DCGM_EXP_XID_ERRORS_COUNT` counts within `--xid-count-window-size` (default 5 minutes). Both are commented out in the 4.9.0 default counters; `DCGM_FI_DEV_XID_ERROR` ("Value of the last XID error encountered") is enabled. The counters are in-memory and reset when the exporter restarts. | confirmed (source and default file) | [`xid_total_collector.go`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/internal/pkg/collector/xid_total_collector.go); [`etc/default-counters.csv`](https://github.com/NVIDIA/dcgm-exporter/blob/4e663a526c4624ad0e18ad816f09b7be2c5348a7/etc/default-counters.csv); `pkg/cmd/AGENTS.md` ("Cumulative exporter counters are in-memory state") |
| NVML defines `nvmlEventTypeXidCriticalError` (0x8). `nvmlEventData_t.eventData` holds the Xid, set to 999 for an unknown Xid. "On Linux, every Xid error event would return the associated event data"; on Windows only the last Xid seen is returned. With MIG enabled the event carries a GPU instance and compute instance ID or `0xFFFFFFFF`, and without suitable privileges only whole-device events are reported. | confirmed | [nvml.h lines 3415-3416, 3470-3484, 10700-10720 at go-nvml 5e99270](https://github.com/NVIDIA/go-nvml/blob/5e992702f9f516e8db7a0c34d3e462a87f48339d/gen/nvml/nvml.h#L3415-L3484) |
| Recent NVML adds "GPU operational events" with a legacy-Xid context decoder (`nvmlEventSetGetGpuOperationalEventContextLegacyXid_v1`, field `xidCode`). | confirmed | nvml.h lines 3558-3598, 10920-10947 |
| `nvidia-smi event-log` "Display events that have occurred since driver load"; its output was changed to show "event groups with readable event category and context type names, GPU-prefixed UUIDs, and numeric module event codes". The manual does not say XIDs are included, and the command was added in the changelog section "v610 Update and v595", so it is new. | confirmed (existence); whether it lists XIDs is unverified | [nvidia-smi manual](https://docs.nvidia.com/deploy/nvidia-smi/index.html), read 2026-11-15 |
| On Linux the kernel log carries `NVRM: Xid (0000:03:00): 14, Channel 00000001`, preceded once by a line mapping the PCI address to the GPU UUID ("The first Xid in the log file is preceded by a line that contains the GPU GUID"). | confirmed | [Working with Xid Errors](https://docs.nvidia.com/deploy/xid-errors/working-with-xid-errors.html), read 2026-11-15 |
| The same page says nvidia-smi "can list ECC error counts (Xid 48), indicate if a power cable is unplugged (Xid 54), or provide any applicable GPU Recovery Action (Xid 154)". The manual's changelog lists a `--query-gpu` option for `gpu_recovery_action`. | confirmed; exact field name unverified | Same page; nvidia-smi manual |
| Which XID numbers map to which cause is a catalog NVIDIA maintains separately ("Xid Catalog"); this plugin does not need it to count events. | confirmed | Same page |

## What the signal can and cannot tell apart

- DCGM field 230 carries the XID number only. It does not carry the channel or the context text that the kernel log line has.
- Read as a latest value at an interval: it distinguishes "a different XID than last time" but not "the same XID again". It cannot be turned into a counter by the plugin.
- Read as a history with a cursor (what the exporter's total collector does): each event is a sample, so repeats count. Retention limits the history; events older than the maximum age are gone.
- Whether the value clears to blank or to zero, and what DCGM shows after the host engine restarts or the driver reloads, was not found in the source read (no code writing a clearing value appeared in the paths read; that is not proof of absence).

## Options for #8

| Option | What the evidence says | Gaps |
|---|---|---|
| DCGM field 230, latest value only | Detects that an XID has occurred since a previous different value; misses repeats; needs DCGM; reflects `/dev/kmsg` and NVML events | Clearing behavior; needs hardware |
| DCGM history through the exporter totals | Counts repeats; per-XID labels; needs the exporter with a customized counters file; state resets on exporter restart | Path C security and freshness (see [dcgm-exporter.md](dcgm-exporter.md)) |
| Kernel log lines | Complete, timestamped, and carries UUID mapping; DCGM itself uses the same source | Who may read it is a host setting; see [privileges.md](privileges.md). Parsing surface |
| `nvidia-smi event-log` | Present in recent nvidia-smi; no privilege statement in the manual | Whether it includes XIDs; which driver lines have it |
| ECC and recovery-action fields | nvidia-smi can report the conditions behind several XIDs (48 ECC, 154 recovery action) without any XID feed | Does not cover other XIDs |
| No XID in phase 1 | Phase 1 is `nvidia-smi` only; none of the above except `event-log` exists in that tool | None |

The owner's decision: whether phase 1 ships without an XID signal and relies on ECC, retired pages, recovery action, and throttle fields, or accepts a kernel log reader (privilege and parsing cost) for NVIDIA nodes.

## Limits

- XID events were not induced, and no value timeline was recorded; the issue's method asks for one and it needs hardware.
- NVML's behavior and DCGM's cache were read from header and source; none of it was executed. The retention behavior at default settings on a standalone `nv-hostengine` was not read.
- The `nvidia-smi event-log` command's output format and privilege needs were not documented.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #16 | On a lab GPU, replay or induce an XID and sample field 230 with `dcgmi dmon -e 230` at 1 s and at 30 s; then repeat the same XID and a different XID | Whether the latest-value read misses repeats; whether the value clears |
| #16 | Reload the driver and restart `nv-hostengine` and sample 230 | What persists |
| #16 | Same events with the exporter's `DCGM_EXP_XID_ERRORS_TOTAL` enabled | Whether totals match the kernel log count |
| #16, #21 | Read the kernel log as the Cacti poller user and as the `snmpd` user | Whether the log reader needs privilege |
| #16 | `nvidia-smi event-log` after an XID | Whether XIDs are listed |
