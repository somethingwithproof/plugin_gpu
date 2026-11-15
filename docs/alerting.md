# Alerting with Thold and Maint

The plugin has no alert engine and sends no notification of its own (REQ-ALERT-005, [ADR 0002](decisions/0002-alert-through-cacti-data-sources.md)). Every GPU value is an item of the GPU data source, so Thold alerts on it like any other Cacti data source, and Maint suppresses those alerts for a node's device. Neither plugin is changed.

Tested against Thold v1.8.2 (`1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a`) and Maint v1.3 (`cd62de9cc323a4f5a8ac80a8d9dda3c7fa16c470`), installed unchanged, on Cacti 1.2.31 and current 1.2.x (`tests/bin/alert-flow.php`, run in CI).

## Set up the shipped thresholds

1. Install and enable Thold, and Maint if you use maintenance windows.
2. In **Management > Threshold Templates**, choose **Import** and import `plugins/gpu/templates/gpu_thold_templates.xml`.
3. In **Templates > Device Templates > GPU Node**, add each *GPU - ...* threshold template under *Associated Threshold Templates*.
4. Turn on Thold's *Auto Create Thresholds* setting, so Thold creates the thresholds as the plugin creates each GPU's graphs. This is the path the end-to-end test takes.
5. Devices that already had the GPU Node template before step 3 are not linked yet: add the templates under *Associated Threshold Templates* on each device's page, then select the devices under **Management > Devices** and run the **Apply Thresholds** action.

Thold creates one threshold per template for each GPU's data source. Notification lists, emails, and repeat settings are Thold's; set them on the templates or the thresholds.

## Shipped templates

| Template | Item | Breaches when | After |
|---|---|---|---|
| GPU - Temperature High | `temp_gpu` | above 85 °C | 2 polls |
| GPU - Power Above Limit | `power` | above 100 percent of `power_limit` | 2 polls |
| GPU - Software Thermal Slowdown | `thr_sw_thermal` | 1 | 1 poll |
| GPU - Hardware Thermal Slowdown | `thr_hw_thermal` | 1 | 1 poll |
| GPU - Hardware Power Brake | `thr_hw_power_brake` | 1 | 1 poll |
| GPU - Hardware Slowdown | `thr_hw_slowdown` | 1 | 1 poll |
| GPU - New Uncorrected ECC Errors | `ecc_uncorr_new` | any new error since the previous sample | 1 poll |
| GPU - Page Retirement Pending | `retired_pending` | 1 | 1 poll |
| GPU - Sample Too Old | `age` | above 900 seconds | 1 poll |

The values are starting points, not vendor limits; adjust them per GPU model. Not templated, on purpose:

- **Software power cap** (`thr_sw_power_cap`): a GPU under full load is normally held there.
- **Idle and the other mask bits** without an indicator: Idle is normal, and the nvidia-smi documentation marks it and Application Clocks Setting deprecated (REQ-THR-002).
- **The volatile ECC totals** (`ecc_corr`, `ecc_uncorr`): they fall to 0 at driver load, so a threshold on them clears after a reboot without the cause being fixed. Alert on `ecc_uncorr_new` instead.
- **New corrected ECC errors**: occasional corrected errors are normal and no default rate is known; add a threshold if your fleet has one.

The template list is generated from `lib/TholdTemplateXml.php`, and a unit test fails if the shipped file differs.

## Rules for GPU thresholds

- **Durations.** Set each fail trigger to at least one collection interval (REQ-ALERT-004). A trigger counts Cacti polls, and the plugin collects each node once per poller cycle, so one poll is one interval. Cacti's poll and the collection are not aligned: a value can be read twice or skipped, so a single-poll trigger can fire on one sample, and a fault shorter than the interval is not seen.
- **Unknown values.** `U` never breaches a threshold (REQ-ALERT-002): a GPU without ECC, a failed collection, or a stale sample reads `U` and Thold neither alerts nor restores on it. A stopped collection is caught by `age`, which stays numeric whenever a sample exists and keeps growing.
- **Gauges only.** Every data source item is a GAUGE. Thold 1.8.2 throws a PHP `TypeError` when a thresholded DERIVE or COUNTER data source reads `U`, and Cacti then disables Thold for everyone. So the plugin computes the new ECC errors itself, from two consecutive samples of the same GPU, and stores the result as a gauge: unknown for a first sample, after a missed read, and after a reset. Do not change a GPU data source item to COUNTER or DERIVE.
- **Maintenance.** A Maint schedule on the node's device suppresses its GPU thresholds (REQ-ALERT-003): Thold neither counts nor notifies a breach while the device is in maintenance, and the RRD files still record the values. The GPU Health page shows whether Maint holds each node.

## What the end-to-end test proves

`tests/bin/alert-flow.php` imports the shipped templates with Thold's own import, links them to the GPU Node template, and lets Thold create the thresholds when the plugin creates the graphs. It then changes the fake `nvidia-smi` output behind a real `snmpd`, collects over SNMPv3, runs Cacti's poller and Thold's check, and reads Thold's log:

- a hot, hardware-throttled GPU with new uncorrected errors and a pending page retirement fires each threshold, the temperature only on its second poll, and each restores when the value returns;
- a driver reload drops the ECC total to 0: the new-error value reads `U`, nothing breaches, and Thold stays enabled; two new errors after the reset fire the threshold again, and it restores;
- a failing tool makes every other value `U`, which breaches nothing, while `age` alerts and restores once samples return;
- under a Maint schedule a breach is neither counted nor notified while the RRD file records it; after the schedule ends it counts and notifies again.
