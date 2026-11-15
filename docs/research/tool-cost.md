# Cost of vendor tool invocation and the polling interval

Research for [#25](https://github.com/somethingwithproof/plugin_gpu/issues/25), 2026-11-15. Question: how long does one collection take, how often can it run, and how much load does it add to a GPU node?

**Result.** Not answerable from documentation: the issue asks for timings on hardware and none were taken. What documentation and source do settle is the cost structure, which decides what to measure and what the plan's performance section can already say. A one-shot `nvidia-smi` is a new process that loads the driver library each time, and the manual says persistence mode "minimizes the driver load latency"; the only documented per-invocation cost warning is a known issue that some power-limit queries are slower on Vera Rubin. DCGM and the exporter keep a long-running engine that samples continuously, so their per-scrape cost is a read of cached values, and the exporter's freshness is its collection interval (30 s default). `net-snmp` extend caches results for 5 s by default and runs the command in the agent. Arithmetic for invocation counts is exact and is given below. No supported minimum polling interval can be stated; the plan's rule "do not state an interval until #25 measures it" stands, and the live checks below produce the table. **Hardware timing data is entirely missing.**

## Method

Read the nvidia-smi manual, the DCGM feature overview and `dcgmi` option parsers, the `dcgm-exporter` defaults and flags, net-snmp's cache code, and the Cacti poller facts in [cacti-core.md](cacti-core.md). No tool was timed.

## Documented cost facts

| Fact | Status | Source |
|---|---|---|
| Persistence mode: "the NVIDIA driver remains loaded even when no active clients, such as X11 or nvidia-smi, exist. This minimizes the driver load latency associated with running dependent apps". Linux only. | confirmed | [nvidia-smi manual](https://docs.nvidia.com/deploy/nvidia-smi/index.html), "Persistence Mode" |
| `-l SEC` and `-lms` repeat a query in one process; "if no argument is specified ... a default interval of 5 seconds is used". The plugin could hold one looping process instead of starting one per collection, at the price of a long-lived process to supervise. | confirmed (options); the trade is a design inference | nvidia-smi manual, `-l` |
| Known issue: on Vera Rubin systems `-q -d POWER` and `--query-gpu=gpu.power.current_limit` "may take longer than in previous releases". | confirmed | nvidia-smi manual, Known Issues |
| `utilization.gpu` is a percent "over the past sample period" of "between 1 second and 1/6 second depending on the product", so a collection more often than the sample period returns the same value. | confirmed | [pynvml help text at 20e79a8](https://github.com/gpuopenanalytics/pynvml/blob/20e79a8b585a85ed9dda4c362c8b5c010b1a295f/help_query_gpu.txt) |
| `dcgmi dmon`: delay default 1000 ms, minimum 1 ms ("how often to query results from DCGM"); `-c` is the count and runs forever by default. DCGM's profiling metrics sample at 1 Hz by default with a 100 ms minimum. | confirmed | [`CommandLineParser.cpp:3220-3230` at v4.7.0](https://github.com/NVIDIA/DCGM/blob/9fae2867e6e5264fa0fca22a07dc898d591baa21/dcgmi/CommandLineParser.cpp#L3220-L3230); [feature overview](https://docs.nvidia.com/datacenter/dcgm/latest/user-guide/feature-overview.html), profiling section |
| DCGM states profiling metrics are available "at low performance overhead in a continuous manner". | confirmed (vendor claim, unmeasured) | feature overview |
| `dcgm-exporter`: collect interval 30000 ms by default; scrapes are admitted up to `--max-concurrent-scrapes`, and "excess requests receive HTTP 503". | confirmed | [dcgm-exporter.md](dcgm-exporter.md) |
| net-snmp `extend`: 5 s default cache per entry, executed within `snmpd`, waiting in a 1 s `select` loop up to 100 times. | confirmed (source) | [snmp-extend.md](snmp-extend.md) |
| Cacti issues one script server call per data source item per poll; the plan's cache design means those calls read cache only. | confirmed | [cacti-core.md](cacti-core.md) |

## Invocation arithmetic

Exact; independent of any tool timing. `N` = nodes, `G` = GPUs per node, `F` = fields per GPU, `C` = collection interval, `P` = Cacti poll interval.

| Design | Vendor tool invocations per `C` | Cacti script server calls per `P` | Notes |
|---|---|---|---|
| Run the tool on every Cacti poll (script server runs the tool) | `N * P/C` where `C = P`: `N` per poll, but one per data source item unless the script caches, so up to `N*G*F` | `N*G*F` | The plan rejects this (ADR 0007: the script server input reads a cache) |
| Collector writes a cache once per `C`; script server reads it | `N` | `N*G*F` cache reads | The plan's design. Example: 1,000 nodes of 8 GPUs and 12 fields is 1,000 invocations per `C` and 96,000 cache reads per `P` |
| Exporter scrape (path C) | 0 on the node; one HTTP request per node per `C` | same cache reads | The node runs the exporter continuously |
| SNMP extend (path B) | `N` per `C`, at most one execution per extend cache window (5 s default) per node | same | The poller's SNMP walk triggers the execution |
| Single looping process per node (`-l`) | 0 new processes per `C`; 1 long-lived process | same | Needs process supervision |

With the plan's example, the collector must finish 1,000 node collections inside `C`. If one node takes `t` seconds and `W` collections run in parallel, a round takes about `N*t/W` seconds; this is why the plan has a time budget per poller (an engineering rule, not a measurement).

## What the plan can say without measurements

- The collector is the rate limiter, not the poller: the plan's "1,000 vendor tool invocations per collection interval" line stands.
- A faster interval than the GPU's own sample period gives repeated values: for `utilization.gpu` that is 1 second at best.
- Persistence mode is a node setting the plugin must not change (ADR 0001); it can recommend it in documentation.
- Throttle events shorter than `C` are not seen (ADR 0002).

## Limits

- The issue's method (time 1, 8, and 16 GPUs, with and without persistence mode, CPU and wall time, concurrent invocations, effect on a training job) was not carried out.
- Not found in any source read: a vendor statement of `nvidia-smi` per-invocation time, of DCGM's CPU use, or of `amd-smi`'s cost (it is a Python program started per call, so startup is the interpreter's).

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #25 | Wall and CPU time of the plan's `nvidia-smi --query-gpu` command for 1, 8, and 16 GPUs, 30 runs each, with persistence mode off and on | Per-invocation cost and its variance |
| #25 | Run two and four simultaneous invocations | Interference |
| #25 | Same query as a `-l` loop | Whether one looping process is cheaper than repeated starts |
| #25 | `dcgmi dmon -c 1` against a running `nv-hostengine`, and an exporter scrape, on the same node | The cost of reading cached values |
| #25 | `amd-smi metric --json` for 1, 8 GPUs | Python start plus library cost |
| #25 | The same collection during a training job on the node | Load added to a busy node |
| #25 | Reading a 10,000-row cache from the script server function | Whether the plan's under 50 ms target holds (a Cacti-side check; needs no GPU) |
