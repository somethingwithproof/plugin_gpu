<!-- Source: docs/alerting.md; SHA256: a4efb73e2a0fe3cd52f61484aaafc468e8dbf216094686e7951c1ab6a4e051c6 -->

<a id="alerting-with-thold-and-maint"></a>
# 使用 Thold 和 Maint 告警

本插件没有告警引擎，也不自行发送通知（REQ-ALERT-005，[ADR 0002](../../../decisions/0002-alert-through-cacti-data-sources.md)）。每个 GPU 值都是 GPU 数据源的一项，因此 Thold 可以像处理其他 Cacti 数据源一样对其告警，而 Maint 可以为节点设备抑制这些告警。两个插件均无需修改。

已在 Cacti 1.2.31 和当前 1.2.x 上，使用未经修改安装的 Thold v1.8.2（`1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a`）和 Maint v1.3（`cd62de9cc323a4f5a8ac80a8d9dda3c7fa16c470`）进行测试（`tests/bin/alert-flow.php`，在 CI 中运行）。

<a id="set-up-the-shipped-thresholds"></a>
## 设置随附的阈值

1. 安装并启用 Thold；若使用维护窗口，也安装并启用 Maint。
2. 在 **管理 > 阈值模板** 中选择 **导入**，导入 `plugins/gpu/templates/gpu_thold_templates.xml`。
3. 在 **模板 > 设备模板 > GPU Node** 中，将每个 *GPU - ...* 阈值模板添加到 *关联的阈值模板*。
4. 开启 Thold 的 *自动创建阈值* 设置，这样插件创建每个 GPU 的图表时，Thold 就会创建阈值。端到端测试采用此流程。
5. 在步骤 3 之前已经使用 GPU Node 模板的设备尚未关联：在各设备页面的 *关联的阈值模板* 下添加模板，然后在 **管理 > 设备** 中选择设备并执行 **应用阈值** 操作。

Thold 为每个 GPU 数据源的每个模板创建一个阈值。通知列表、电子邮件和重复通知设置由 Thold 管理；请在模板或阈值上设置。

<a id="shipped-templates"></a>
## 随附的模板

| 模板 | 数据项 | 超限条件 | 持续 |
|---|---|---|---|
| GPU - Temperature High | `temp_gpu` | 超过 85 °C | 2 次轮询 |
| GPU - Power Above Limit | `power` | 超过 `power_limit` 的 100% | 2 次轮询 |
| GPU - Software Thermal Slowdown | `thr_sw_thermal` | 1 | 1 次轮询 |
| GPU - Hardware Thermal Slowdown | `thr_hw_thermal` | 1 | 1 次轮询 |
| GPU - Hardware Power Brake | `thr_hw_power_brake` | 1 | 1 次轮询 |
| GPU - Hardware Slowdown | `thr_hw_slowdown` | 1 | 1 次轮询 |
| GPU - New Uncorrected ECC Errors | `ecc_uncorr_new` | 自上一个样本以来出现任何新错误 | 1 次轮询 |
| GPU - Page Retirement Pending | `retired_pending` | 1 | 1 次轮询 |
| GPU - Sample Too Old | `age` | 超过 900 秒 | 1 次轮询 |

这些值是初始参考值，并非厂商限制；请按 GPU 型号调整。有意不提供以下模板：

- **软件功率上限**（`thr_sw_power_cap`）：满载 GPU 通常会受此限制。
- 没有独立指示项的 **Idle 和其他掩码位**：Idle 属于正常状态，nvidia-smi 文档已将它和 Application Clocks Setting 标为弃用（REQ-THR-002）。
- **易失性 ECC 总数**（`ecc_corr`、`ecc_uncorr`）：驱动加载时它们会降为 0，所以针对总数的阈值可能在重启后清除，而原因尚未解决。请改用 `ecc_uncorr_new` 告警。
- **新增已纠正 ECC 错误**：偶发的已纠正错误属于正常现象，且没有已知的默认速率；如果您的设备群有适用值，可添加阈值。

模板列表从 `lib/TholdTemplateXml.php` 生成；若随附文件与之不同，单元测试会失败。

<a id="rules-for-gpu-thresholds"></a>
## GPU 阈值规则

- **持续时间。** 每个故障触发时长应至少为一个采集间隔（REQ-ALERT-004）。触发器计数的是 Cacti 轮询，插件每个轮询器周期采集节点一次，因此一次轮询就是一个间隔。Cacti 轮询和采集并不同步：一个值可能被读两次或跳过，因此单次轮询触发器可能因一个样本触发，而短于间隔的故障无法被看到。
- **未知值。** `U` 从不触发阈值（REQ-ALERT-002）：不支持 ECC 的 GPU、失败的采集或过期样本均返回 `U`，Thold 不会为其告警。它也不会保留现有告警：Thold 1.8.2 将非数字值视为未超限，所以处于告警的阈值在值变为 `U` 时会恢复并发送恢复通知。因此，GPU 值未知期间的恢复表示插件丢失了该值，而非故障消失；`age` 可区分这两种情况，因为只要存在过样本，它就保持数值并持续增长，*GPU - Sample Too Old* 阈值会为此告警。
- **仅使用 GAUGE。** 每个数据源项都是 GAUGE。Thold 1.8.2 在有阈值的 DERIVE 或 COUNTER 数据源读到 `U` 时抛出 PHP `TypeError`，随后 Cacti 会为所有用户禁用 Thold。因此插件从同一 GPU 的两个连续样本自行计算新增 ECC 错误，并将结果存为 gauge：首个样本、漏读后或重置后为未知。不要将 GPU 数据源项改为 COUNTER 或 DERIVE。
- **维护。** 节点设备上的 Maint 计划会抑制其 GPU 阈值（REQ-ALERT-003）：设备处于维护期时，Thold 不计数也不通知超限，RRD 文件仍记录这些值。GPU 健康页面显示每个节点是否受 Maint 维护控制。

<a id="what-the-end-to-end-test-proves"></a>
## 端到端测试证明了什么

`tests/bin/alert-flow.php` 使用 Thold 自身的导入功能导入随附模板，将其关联到 GPU Node 模板，并在插件创建图表时让 Thold 创建阈值。随后，它改变真实 `snmpd` 后面的模拟 `nvidia-smi` 输出，通过 SNMPv3 采集，运行 Cacti 轮询器和 Thold 检查，并读取 Thold 日志：

- 高温、硬件降频、有新增未纠正错误且待退役页面的 GPU 会触发各阈值；温度只在第二次轮询触发，各值恢复时相应阈值也恢复；
- 驱动重新加载使 ECC 总数降为 0：新增错误值为 `U`，不触发任何阈值，Thold 仍保持启用；重置后的两个新错误再次触发阈值，随后恢复；
- 工具失败使其他所有值变为 `U`，不触发任何阈值，并使原本告警的硬件降速阈值恢复；与此同时 `age` 触发告警，并在样本恢复后恢复；
- 在 Maint 计划期间，超限既不计数也不通知，但 RRD 文件仍记录；计划结束后重新计数和通知。
