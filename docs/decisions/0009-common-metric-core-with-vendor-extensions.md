# 0009. One common metric core, with vendor extensions in their own templates

Status: Accepted (2026-11-15). Decides #9.

## Context

NVIDIA and AMD tools report overlapping metrics under different names, units, and scales, and DCGM reports some of them as ratios where `nvidia-smi` prints percentages. A field set shared by every backend lets one data query, one set of graph templates, and one set of Thold templates cover a mixed fleet. Vendor-specific templates would keep each tool's own terms but double the templates and the thresholds, and a mixed fleet could not be graphed side by side. The plan proposed the common core; the owner accepted the proposed option on 2026-11-15.

## Decision

- The plugin defines one metric model in code (`MetricModel`). Each field has one data source name of at most 19 characters, one unit, its kind (gauge or 0/1 indicator), its range, and its source per backend. The table in [`docs/metrics.md`](../metrics.md) is generated from the model, and a test keeps the two equal.
- Utilization is percent 0 to 100, memory is bytes, temperature is degrees C, power is watts, and clocks are MHz, whatever the source scale or unit. A backend converts at parse time.
- ECC and retired-page fields are gauges of the reported total. Throttle reasons are decoded from the reported bitmask into 0/1 indicators named `thr_*`, and the raw mask is kept in the cache so that bits the decoder does not know are not lost.
- A field a backend cannot supply is unknown (`U`) for that backend and is never estimated.
- Fields only one vendor reports go into separate, vendor-named templates. The milestone 3 field set has none; every field in it has an NVIDIA source today and a proposed AMD source pending #18.

## Consequences

- One GPU data query, one data template, and one set of graph templates serve every backend. Adding the AMD backend adds parse rules, not templates.
- Field names are fixed once templates ship. Renaming one later means a template migration, so the names were checked against Cacti's 19-character data source item limit before release.
- A field that exists on one vendor only (for example a DCGM-only health signal) cannot join the core without a source for every vendor.

## Alternatives rejected

- **Fully vendor-specific templates.** Faithful to each tool, but it doubles the graph and Thold templates and splits a mixed fleet across two sets of graphs.
