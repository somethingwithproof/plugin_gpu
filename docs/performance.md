# Performance and scale

Measurements for #49 (REQ-PERF-001 to REQ-PERF-004), taken with `tests/bin/scale-flow.php`. They come from one developer workstation, not from production hardware. Read them as an upper bound on plugin overhead, not as a supported capacity: a production Cacti server, its database, and the network to real nodes all differ.

## Machine

| Item | Value |
|---|---|
| Host | Apple M3 Max, 14 cores, 36 GiB memory |
| Container runtime | OrbStack (Docker 29.8.2), 14 CPUs and 15.7 GiB for its VM |
| Cacti | 1.2.31 and the 1.2.x branch, PHP 8.2, MariaDB in a container on the same host |
| Node agent | One real net-snmp `snmpd` in a container, SNMPv3, serving every node; the fake `nvidia-smi` prints the 8-GPU fixture |

All nodes share one agent on the same host, so network latency is near zero and per-node agent cost is counted once per read. A real fleet adds a network round trip per SNMP request and the cost of `nvidia-smi` on each node (#25).

## Method

The test registers `<gpus> / 8` Cacti devices, each a GPU node on the same agent. A test reader wraps the plugin's SNMP extend reader and rewrites each UUID with the device id, so every node reports its own GPUs; every read is still a real SNMPv3 exchange. It measures two collection rounds (the first records the GPUs, the second is steady state), the script server input (one index call per node and one get per data source item, as Cacti's poller makes them), and a GPU Health render, checking that the render sends no SNMP packet. With `provision` it also creates the Cacti objects through the plugin's provisioner, runs one Cacti poller cycle, and checks that a time budget stops a collector round early and counts the nodes it skipped.

## Results

Not recorded yet. CI runs the 100 GPU case with Cacti objects on every matrix job; the 1,000 GPU run with Cacti objects and the 8,000 GPU collection run on this machine are recorded here once taken, with the row counts. Until then REQ-PERF-001 is open and the plugin states no supported GPU count.

## What this supports

Nothing yet. The plan's arithmetic for 1,000 nodes of 8 GPUs is in [PLAN.md](PLAN.md#performance); a supported count is stated only after it is measured here and confirmed on production-sized hardware.

## Not measured

- Real `nvidia-smi` cost on a node and the minimum interval (#25).
- A remote database, a production Cacti server, and network latency to nodes.
- Cacti's own device, data source, and graph pages at 8,000 GPUs (REQ-PERF-004): the 8,000 GPU run did not provision Cacti objects.
