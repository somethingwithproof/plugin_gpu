# nvidia-smi query fields and CSV output

Research for [#14](https://github.com/somethingwithproof/plugin_gpu/issues/14), 2026-11-15. Question: which `--query-gpu` fields, units, and placeholder values does `nvidia-smi` print on each driver line the plugin will support?

**Result.** Documentation and source settle the field names, the semantics of identity fields, the exit codes, and the fact that the CLI makes no output-compatibility promise. They do not settle units in CSV, the exact placeholder strings, or which names exist on a given driver; those need a run on hardware and are listed under Live checks. The only machine-readable list of `--query-gpu` names found is a 2021 snapshot of `--help-query-gpu` that contains no `clocks_event_reasons.*` names, so it is out of date for current drivers. Every field the plan names exists in that snapshot. Two design consequences follow without hardware: the parser must treat `N/A` and bracketed strings such as `[Not Supported]` as unknown, and it must select columns by the field list it sent, never by header text.

## Method

Read the current nvidia-smi manual page and its changelog (fetched 2026-11-15), the `--help-query-gpu` snapshot in pynvml, and the NVML header that `nvidia-smi` is built on (go-nvml's copy, labelled NVML 13.4.92). Nothing was run.

## Findings

| Fact | Status | Source |
|---|---|---|
| "The output of NVSMI is not guaranteed to be backwards compatible. However, both NVML and the Python bindings are backwards compatible, and should be the first choice when writing any tools that must be maintained across NVIDIA driver releases." | confirmed | [nvidia-smi manual](https://docs.nvidia.com/deploy/nvidia-smi/index.html), read 2026-11-15 |
| `--query-gpu=` takes a comma-separated property list; `--format=csv` is mandatory; `noheader` and `nounits` are optional; `-l SEC` and `-lms` loop. | confirmed | Same page, "Selective query options" |
| Any unsupported data is indicated by `N/A` in the output (page statement for `-q`). | confirmed for `-q`; unverified for CSV | Same page |
| The snapshot's help text shows at least one bracketed placeholder: `power.management` is "Either `Supported` or `[Not Supported]`". | confirmed for that field | [pynvml `help_query_gpu.txt` at 20e79a8](https://github.com/gpuopenanalytics/pynvml/blob/20e79a8b585a85ed9dda4c362c8b5c010b1a295f/help_query_gpu.txt) (file last changed 2021-05-10; no newer revision exists in the repository at 493a974, 2025-09-05) |
| Exit codes: 0 success, 2 invalid argument, 3 not available on device, 4 no permission, 6 query for an object unsuccessful, 9 driver not loaded, 12 NVML library not found, 13 NVML lacks the function, 14 infoROM corrupted, 15 GPU fell off the bus, 255 other. | confirmed | nvidia-smi manual, "Return codes" |
| All field names the plan uses exist in the snapshot: `index`, `uuid`, `pci.bus_id`, `serial`, `name`, `utilization.gpu`, `utilization.memory`, `memory.total`, `memory.used`, `memory.free`, `temperature.gpu`, `power.draw`, `power.limit`, `clocks.sm`, `clocks.mem`, `ecc.errors.{corrected,uncorrected}.{volatile,aggregate}.total`, `retired_pages.pending`, `mig.mode.current`, `clocks_throttle_reasons.active` and `.supported`. | confirmed against the 2021 snapshot; unverified on current drivers | `help_query_gpu.txt` at 20e79a8 |
| `utilization.gpu` and `utilization.memory` are percentages over a "past sample period" of "between 1 second and 1/6 second depending on the product". | confirmed | Same file |
| `power.draw` is "the last measured power draw for the entire board, in watts ... accurate to within +/- 5 watts", and only available when power management is supported. | confirmed | Same file |
| `temperature.gpu` is "in degrees C". | confirmed | Same file |
| `memory.total`, `memory.used`, `memory.free` have no unit stated in the help text. | unverified (unit in CSV with and without `nounits`) | Same file |
| `uuid` is "globally unique immutable alphanumeric identifier ... does not correspond to any physical label". `index` can change at each boot. | confirmed; see [identity.md](identity.md) for the NVML header wording | Same file; NVML header |
| `ecc.errors.*.volatile.total` counts since the last driver load; `*.aggregate.total` persists. | confirmed in the plan's earlier reading of the snapshot | Same file |
| The `-i` option accepts a GPU's index, serial, UUID, or PCI bus ID. | confirmed | nvidia-smi manual |
| MPS device view: under a process-local MPS view `--query-gpu=pci.*` fields are `N/A` and `-l` is not supported. | confirmed | nvidia-smi manual, "MPS locality domain device enumeration" |
| Persistence mode: with it enabled "the NVIDIA driver remains loaded even when no active clients ... exist. This minimizes the driver load latency". | confirmed; cost is for #25 | nvidia-smi manual, GPU attributes |
| Known issue: on Vera Rubin systems `--query-gpu=gpu.power.current_limit` and `-q -d POWER` "may take longer than in previous releases". | confirmed (vendor known issue) | nvidia-smi manual, Known Issues |
| Field names added recently (for example `clocks_event_reasons_counters.*`, `power.draw.average`, `power.draw.instant`, `inforom.checksum_validation`) appear in the changelog; the snapshot predates them. | confirmed | nvidia-smi manual, changelog |

## What the design needs from this

- Field names: the plan's list is usable. The plan's `power.limit` is the software limit; the snapshot also lists `enforced.power.limit`, and the Known Issues section names `gpu.power.current_limit`. #14's fixture must record which one each supported driver returns.
- The plan's unknown-value rule needs a tested set of placeholders: `N/A`, `[N/A]`, `[Not Supported]`, `Not Supported`, and an empty field. The parser should reject a column count that differs from the field list and map every non-numeric value in a numeric column to unknown (`U`).
- CSV separator inside values: the GPU `name` field is free text. The plan never needs `name`; if it is shown, it is queried in its own call or last, and the parse rejects rows whose column count differs. Whether a product name can contain a comma is unverified.
- `nounits` and the memory unit: unverified, see Live checks. The plan can avoid the question by requesting `nounits` and treating memory as MiB only after a fixture shows it, which is what #14's exit criteria ask.

## Limits

- No `--help-query-gpu` output newer than 2021 was available offline. The changelog pages list additions but not a full field list.
- No sentence in the manual page or help text says which driver release introduced a given field. The changelog is organized by nvidia-smi version headings (v470 to v620), not driver release numbers.
- Behavior when a GPU is lost mid-query (exit status 15 is documented as a code; the stderr text and the stdout content are not) was not documented.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #14 | On each supported driver line, `nvidia-smi --help-query-gpu` saved to a fixture | The authoritative field list per driver, including which event reason names exist |
| #14 | `--query-gpu=<plan fields> --format=csv` with and without `noheader,nounits` on a 1-GPU and an 8-GPU node | Units, header text, placeholders, column count |
| #14 | Query on a GPU without ECC, without power reporting, and a consumer card | The placeholder per missing field |
| #14 | Run a query while a GPU is unbound or lost | Exit status, stderr, partial stdout |
| #14 | Product names containing a comma or quote, if any exist in the fleet | Whether CSV quoting occurs |
