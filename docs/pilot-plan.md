# Phase 1 pilot plan

Plan for #54: run the plugin on real NVIDIA hardware before phase 1 is released. Nothing here has been run yet. The issue stays open until the pilot reports are attached to the epic (#4).

## Why pilots

Every phase 1 test runs against a real `snmpd` with a fake `nvidia-smi` printing synthetic output. The fixtures follow the documented field order, but no fixture has been captured from a real GPU (#14), and several behaviors are only documented, not observed: the throttle mask's print format and bits (#17), ECC mode and retired-page fields on each driver line, tool cost and the minimum interval (#25), the user each distribution's snmpd runs as (#22), and how index order changes after a reboot or card swap.

## Pilots

| Pilot | Hardware | Must cover |
|---|---|---|
| A | A multi-GPU node with ECC enabled, on a current data center driver (R580, R595, or R620) | REQ-PILOT-001: ECC values, ECC mode, retired pages, every throttle indicator seen in use |
| B | Any multi-GPU node where a reboot or a card swap can be scheduled | REQ-PILOT-002: an index order change, or a replacement card, keeps each history on its UUID |
| C, optional | A GPU without ECC, or with ECC disabled | Unknown ECC values and the health page reason |

Each pilot runs on a Cacti 1.2.31 or 1.2.x test instance with the plugin, Thold 1.8.2, and Maint 1.3, collecting at the default 300 second interval for at least a week.

## Before the pilot

1. Record the node's OS, kernel, GPU models, driver version, `nvidia-smi` version, net-snmp version, and the user snmpd runs as.
2. Set up the agent exactly as [node-setup.md](node-setup.md) says, and note every step that did not work as written (REQ-DOC-004).
3. Capture `nvidia-smi --query-gpu=<the documented field list> --format=csv,noheader,nounits` and the names query once, strip hostnames, serial numbers, and UUIDs, and attach them to #14 as fixtures.
4. Add the node in **GPU Nodes** and import the Thold templates.

## During the pilot

| Check | How | Pass when |
|---|---|---|
| Collection | GPU Health, daily | Completed and Current on every cycle, or a failure the page explains |
| Values | Compare the graphs with `nvidia-smi` on the node at the same minute | Within one sample of each other, in the documented units |
| Throttle mask | Load a GPU until it throttles, if safe on that hardware | The indicator and Thold alert fire, and the raw mask on GPU Health matches the tool |
| ECC | Read the totals before and after a driver reload | The totals fall to 0, the new-error values read `U` once, and nothing alerts |
| Staleness | Stop snmpd for two intervals | The *Sample Too Old* threshold fires and restores |
| Maint | Schedule a window and repeat a breach | No notification while the window is on |
| Reboot or swap (pilot B) | Reboot so the index order changes, or replace a card | Each graph keeps its card's history; a new card is flagged as a possible replacement and gets its own graphs |
| Cost | Time a collection of the node, and watch the node's load | Recorded for #25 |

## Report

For each pilot, attach to #4: the versions from step 1, the checks above with pass or fail and evidence (screenshots of GPU Health and the graphs, Thold log rows), every documentation step that failed, and the timing. Corrections update the plan and the docs before release (REQ-PILOT-003).
