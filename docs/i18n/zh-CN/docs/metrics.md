<!-- Source: docs/metrics.md; SHA256: 1f8f5b9a81f526894e1d25d7e2f40feec798eda1a886a189f1dd8533bd6c48f1 -->

<a id="gpu-metric-model"></a>
# GPU 指标模型

每个后端填充相同的字段（[ADR 0009](../../../decisions/0009-common-metric-core-with-vendor-extensions.md)）。每个字段既是 GPU 数据模板的一个数据源项，也是 GPU 数据查询的一个输出字段，因此一套图表和 Thold 模板即可覆盖所有厂商。标记之间的表格由 `lib/MetricModel.php`（`MetricModel::table()`）生成；两者不同时，单元测试会失败。

里程碑 3 实现 nvidia-smi 列。DCGM 和 AMD 列记录后续后端将读取的数据来源；尚未确定时会列出用于确认的研究议题。

<!-- metric-table:start -->
| 字段 | 含义 | 单位 | 类型 | 范围 | nvidia-smi 来源 | DCGM 来源 | AMD 来源 |
|---|---|---|---|---|---|---|---|
| `util_gpu` | GPU 利用率 | 百分比 | GAUGE | 0 至 100 | `utilization.gpu` | 1613 (比例 0.0 至 1.0，乘以 100)，或 203 (百分比, 已弃用) | `metric --usage` `gfx_activity` (#18) |
| `util_mem` | 内存控制器利用率 | 百分比 | GAUGE | 0 至 100 | `utilization.memory` | 1614 (比例 0.0 至 1.0，乘以 100)，或 204 (未说明单位, #15) | `metric --usage` `umc_activity` (#18) |
| `mem_used` | 已用帧缓冲内存 | 字节 | GAUGE | 0 至 U | `memory.used` (MiB, 乘以 1,048,576) | 1627 (已用, 字节)，或 252 (MiB, 已弃用) | `metric --mem-usage` `used_vram` (MiB, #18) |
| `mem_total` | 帧缓冲内存总量 | 字节 | GAUGE | 0 至 U | `memory.total` (MiB, 乘以 1,048,576) | 1625 (容量, 字节)，或 250 (MiB, 已弃用); 1626 是空闲字节数，未使用 | `metric --mem-usage` `total_vram` (MiB, #18) |
| `temp_gpu` | GPU 温度 | 摄氏度 | GAUGE | U 至 U | `temperature.gpu` | 150 | `metric --temperature` `edge` (#18) |
| `power` | 板卡功耗 | 瓦 | GAUGE | 0 至 U | `power.draw` | 155 | `metric --power` `socket_power` |
| `power_limit` | 板卡功率上限 | 瓦 | GAUGE | 0 至 U | `power.limit` | 160 | #18 |
| `clock_sm` | SM 时钟 | MHz | GAUGE | 0 至 U | `clocks.sm` | 1603 (赫兹，除以 1,000,000)，或 100 (未说明单位) | `metric --clock` (#18) |
| `clock_mem` | 内存时钟 | MHz | GAUGE | 0 至 U | `clocks.mem` | 1604 (赫兹，除以 1,000,000)，或 101 (未说明单位) | `metric --clock` (#18) |
| `ecc_corr` | 驱动加载以来已纠正 ECC 错误 | 计数 | GAUGE | 0 至 U | `ecc.errors.corrected.volatile.total` | 310 | `metric --ecc` `total_correctable_count` |
| `ecc_uncorr` | 驱动加载以来未纠正 ECC 错误 | 计数 | GAUGE | 0 至 U | `ecc.errors.uncorrected.volatile.total` | 311 | `metric --ecc` `total_uncorrectable_count` |
| `ecc_corr_new` | 上一样本以来已纠正 ECC 错误 | 计数 | GAUGE | 0 至 U | 从连续 `ecc_corr` 样本计算 | 从连续 `ecc_corr` 样本计算 | 从连续 `ecc_corr` 样本计算 |
| `ecc_uncorr_new` | 上一样本以来未纠正 ECC 错误 | 计数 | GAUGE | 0 至 U | 从连续 `ecc_uncorr` 样本计算 | 从连续 `ecc_uncorr` 样本计算 | 从连续 `ecc_uncorr` 样本计算 |
| `retired_pending` | 待退役页面 | 0 或 1 | 指示项 | 0 至 1 | `retired_pages.pending` | 392 | #18 |
| `retired_sbe` | 单比特 ECC 错误后退役的页面 | 页面 | GAUGE | 0 至 U | `retired_pages.sbe` | 390 (`DCGM_FI_DEV_RETIRED_SBE`, 未确认, #15) | #18 |
| `retired_dbe` | 双比特 ECC 错误后退役的页面 | 页面 | GAUGE | 0 至 U | `retired_pages.dbe` | 391 (`DCGM_FI_DEV_RETIRED_DBE`, 未确认, #15) | #18 |
| `thr_sw_power_cap` | 受软件功率上限限制的时钟 | 0 或 1 | 指示项 | 0 至 1 | `clocks_event_reasons.active`, 位 0x4 | 字段 112, 位 0x4 | `metric --violation` (MI300 或更新型号, #18) |
| `thr_hw_slowdown` | 硬件降速 | 0 或 1 | 指示项 | 0 至 1 | `clocks_event_reasons.active`, 位 0x8 | 字段 112, 位 0x8 | 不适用 |
| `thr_sw_thermal` | 软件热降速 | 0 或 1 | 指示项 | 0 至 1 | `clocks_event_reasons.active`, 位 0x20 | 字段 112, 位 0x20 | `metric --violation` (MI300 或更新型号, #18) |
| `thr_hw_thermal` | 硬件热降速 | 0 或 1 | 指示项 | 0 至 1 | `clocks_event_reasons.active`, 位 0x40 | 字段 112, 位 0x40 | `metric --violation` (MI300 或更新型号, #18) |
| `thr_hw_power_brake` | 硬件功率制动 | 0 或 1 | 指示项 | 0 至 1 | `clocks_event_reasons.active`, 位 0x80 | 字段 112, 位 0x80 | 不适用 |
| `age` | 工具采样以来的秒数 | 秒 | GAUGE | 0 至 U | 从 `timestamp` 计算 | 从样本时间戳计算 | 从样本时间戳计算 |
<!-- metric-table:end -->

<a id="rules"></a>
## 规则

- 缺失、不支持或过期的值均存储并显示为 `U`，绝不为 0（REQ-METRIC-004）。nvidia-smi 占位符如 `N/A`、`[N/A]`、`[Not Supported]`，以及数字列中的任何其他文本，都视为未知。
- 无论源数据的刻度如何，利用率均为 0 至 100 的百分比（REQ-METRIC-002）。DCGM 字段 1613 是 0.0 至 1.0 的比例，需要乘以 100。
- 内存单位为字节（REQ-METRIC-003）。nvidia-smi 使用 `nounits` 时输出 MiB；CSV 输出的单位仍需在 #14 中实测。DCGM 字段 1627（已用）和 1625（容量）单位为字节；1626 是空闲字节数，不属于模型。
- ECC 值是从驱动加载以来报告总数的 gauge（REQ-METRIC-005）。它们是易失性计数，而非生命周期总数（REQ-ERR-003）：重新加载驱动会将其重置为 0，因此总数阈值可能在重启后清除，即使原因尚未解决。
- `ecc_corr_new` 和 `ecc_uncorr_new` 是自上一样本以来的错误数，由插件使用同一 GPU 的两个连续总数计算。GPU 首次采样、漏读或未知读取后，以及总数下降时（重新加载驱动），它们为未知。它们是 gauge，绝不是 Cacti 或 RRD counter：Thold 1.8.2 在设有阈值的 DERIVE 或 COUNTER 数据源读到 `U` 时会抛出 PHP `TypeError`，随后 Cacti 禁用 Thold。请对新增值告警，不要对总数告警。
- `retired_sbe`、`retired_dbe` 和 `retired_pending` 是 GPU 的退役页面计数及待退役标志。它们在重新加载驱动后仍然保留。
- 没有 ECC 或关闭 ECC 的 GPU 对所有 ECC 值报告 `U`，绝不报告 0（REQ-ERR-002）。插件也存储 `ecc.mode.current`，健康页面显示原因：ECC 已关闭或不支持。
- 没有 XID 字段。没有 XID 来源适用于 ADR 0008 允许的固定 `nvidia-smi` 命令：`--query-gpu` 没有 XID 字段（已对照已发布的 `--help-query-gpu` 列表检查；在当前驱动上确认属于 #14），`nvidia-smi event-log` 没有文档说明会报告 XID，而内核日志不在这些命令范围内。决策 #8 将选择来源（[research/xid.md](../../../research/xid.md)）。
- 任何错误值都不会触发重置、排空、清除 ECC 计数器或页面退役（REQ-ERR-006）。插件只读取。
- 当时钟事件原因掩码的对应位为 1 时，降频指示项为 1；清零时为 0；掩码未知时为未知。掩码按十六进制（`0x` 前缀）或十进制解析。在实测（#17）确定它是否会在没有 0x40 或 0x80 时出现之前，`thr_hw_slowdown`（0x8）保留独立指示项。原始掩码保留在插件缓存中，因此没有指示项的位，包括后续驱动新增的位，不会丢失。
- `age` 是自工具采样以来的秒数，来自样本自身的时间戳，绝非插件读取时间（REQ-METRIC-006）。只有从未采样的 GPU 才是未知，因此采集停止会表现为不断增长的年龄，可由 Thold 告警。
- 数据源名称最多 19 个字符，这是 Cacti 的限制。计划中的 `throttle_*` 改为 `thr_*`，因为 `throttle_sw_power_cap`、`throttle_hw_slowdown` 和 `throttle_hw_power_brake` 超过长度。
- 后端无法提供的字段在该后端上为未知，不作估算（REQ-METRIC-007）。

每个缓存样本还记录观测时间、摄取时间、后端和工具版本。
