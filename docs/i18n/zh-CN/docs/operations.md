<!-- Source: docs/operations.md; SHA256: b68dea054747892f72251ecd7e6a2aa6b57e82a56e8c7a1d8eba3f7c4468f15e -->

<a id="operator-guide"></a>
# 操作员指南

本指南介绍如何安装、运行、升级和移除插件，各种不可用值的含义，以及出现异常时应检查的内容。GPU 节点设置见 [node-setup.md](node-setup.md)，告警见 [alerting.md](alerting.md)，备份见 [backup.md](backup.md)，已测规模见 [performance.md](performance.md)。

这是预发布软件。阶段 1 仅使用真实 `snmpd` 与输出合成数据的模拟 `nvidia-smi` 运行过，从未在 GPU 硬件上运行；试点跟踪于 #54。

View、Operator 和 Admin 权限及个人分诊行为见[访问角色](../README.md#access-roles)。

<a id="supported-matrix"></a>
## 支持矩阵

| 组件 | 支持情况 | 依据 |
|---|---|---|
| Cacti | 1.2.31 和 1.2.x 分支 | 两者均在以下所有 PHP 版本上运行 CI |
| PHP | 8.2、8.3、8.4、8.5，带 `snmp` 扩展 | CI 矩阵；低于 8.2 拒绝安装 |
| Thold、Maint | 未修改的 Thold 1.8.2、Maint 1.3；两者均可选 | CI 中的端到端告警测试（[alerting.md](alerting.md)） |
| 节点代理 | net-snmp `snmpd`，配置文档中的两条 `extend` 行 | 使用发行版 net-snmp 软件包的端到端测试 |
| NVIDIA 驱动 | 建议：R580、R595 和 R620 数据中心系列（[research/compatibility-matrix.md](../../../research/compatibility-matrix.md)，#26） | 未在硬件上运行；由所有者决定列表 |
| AMD、DCGM、MIG、远程采集器 | 不在阶段 1 内 | 阶段 2 和 3 |

**间隔。** 采集器在每个 Cacti 轮询器周期的轮询结束后读取各节点一次。计划使用 Cacti 默认的 300 秒间隔；测试直接启动采集，并非按计划运行。snmpd 将指标条目缓存 30 秒，因此短于约一分钟的间隔可能两次读到相同样本。支持的最小间隔尚待硬件上的工具开销测量确定（#25）。

<a id="least-privilege"></a>
## 最小权限

| 位置 | 运行身份 | 所需权限 |
|---|---|---|
| Cacti Web 服务器 | Web 服务器用户 | 安装和升级期间可写 `<cacti>/resource/script_server/`，用于数据查询 XML。无需其他文件访问权限。 |
| 采集器 | 由 `poller_bottom` 钩子启动的 Cacti 轮询器用户 | 与 Cacti 轮询器相同的 Cacti 数据库权限，以及访问每个节点 SNMP 端口的网络权限。不启动其他程序，不在节点上运行任何内容。 |
| GPU 节点 | 运行 `snmpd` 的用户 | 运行 `nvidia-smi --query-gpu` 的权限，无需 root（[research/privileges.md](../../../research/privileges.md)）。按 [node-setup.md](node-setup.md) 所示，以非特权账户（`agentuser`）运行 snmpd。 |
| SNMP | 只读 SNMPv3 用户 | 仅系统组和 extend 表，启用身份验证与加密 |

插件不存储自己的凭据。节点的 SNMP 设置保留在其 Cacti 设备上。

<a id="install"></a>
## 安装

1. 将仓库复制到 Cacti 目录下的 `plugins/gpu`。目录必须命名为 `gpu`。
2. 安装期间，使 Web 服务器用户可以写入 `<cacti>/resource/script_server/`。
3. 在 **配置 > 插件** 中安装并启用 **GPU**。PHP 低于 8.2 时会拒绝安装并显示消息，Cacti 其余功能继续正常工作。
4. 在 **配置 > 用户** 中授予用户权限域。*GPU View* 可打开 **GPU 清单** 和 **GPU 健康**；*GPU Admin* 可打开 **GPU 节点**。安装用户获得两者。每个页面只显示用户在 Cacti 中有权查看设备上的节点。
5. 设置每个节点的代理（[node-setup.md](node-setup.md)），并在 **管理 > GPU 节点** 中添加。
6. 如需告警，导入 Thold 模板（[alerting.md](alerting.md)）。

设置位于 **配置 > 设置 > GPU**：

| 设置 | 默认值 | 含义 |
|---|---|---|
| 标记缺席前的次数 | 3 | 连续成功采集中未出现 GPU 达到此次数后，才标记缺席；范围 1 至 100。失败或空采集从不计数。 |
| 过期时间 | 600 秒 | 样本多旧后值显示为 `U`；范围 60 至 86400。`age` 持续计时。 |
| 卸载时保留插件表 | 开 | 除非取消此项，否则卸载保留节点和 GPU 记录。 |
| 卸载时移除插件的 Cacti 对象 | 关 | 卸载同时移除插件未使用的模板及未修改的数据查询 XML。绝不删除 RRD 文件、设备、图表或数据源。 |

<a id="upgrade"></a>
## 升级

1. 首先备份（[backup.md](backup.md)）。
2. 替换 `plugins/gpu` 下的文件。
3. 打开 **配置 > 插件** 或任意 GPU 页面。插件按顺序迁移数据库结构，按哈希重新导入模板（更新而非重复），仅在所有步骤成功后记录新版本。中断的升级会在下一次尝试时完成。
4. **当发行说明指出字段列表变更时，更新每个节点的指标 `extend` 行。** 插件要求模型中的精确字段，因此旧行返回的字段数错误，采集会以 *输出被拒绝* 失败，直到更新为止。从 [node-setup.md](node-setup.md) 复制该行并重启 snmpd。
5. 若使用随附阈值，重新导入 `templates/gpu_thold_templates.xml`；Thold 按哈希匹配模板。

数据库结构版本记录在 `gpu_schema_version` 设置中：

| 版本 | 变更 |
|---|---|
| 1 | 节点和 GPU 记录 |
| 2 | 采集、观测、重新索引和退役表 |
| 3 | 观测列 `ecc_corr_new`、`ecc_uncorr_new`、`retired_sbe`、`retired_dbe`、`ecc_mode`。指标 `extend` 行增加 `ecc.mode.current`、`retired_pages.sbe` 和 `retired_pages.dbe`。 |

<a id="uninstall"></a>
## 卸载

1. 若需要记录，在 **GPU 节点** 中导出。
2. 仅在应删除记录时取消 *卸载时保留插件表*。
3. 仅在还应移除插件模板和数据查询 XML 时勾选 *卸载时移除插件的 Cacti 对象*。
4. 在 **配置 > 插件** 中卸载 **GPU**。
5. 从每个节点的 `snmpd.conf` 中移除插件的 `extend` 行（`cacti_gpu`、`cacti_gpu_names`，以及已添加时的 `cacti_gpu_legacy`），然后重启 snmpd。

无论设置如何，卸载绝不删除 RRD 文件、设备、图表或数据源。

勾选 *卸载时移除插件的 Cacti 对象*（默认关闭）时，还会移除：

- 插件按哈希找到的各个导入模板：*GPU Node* 设备模板、*GPU - Devices* 数据查询、*GPU - Metrics* 数据模板及 GPU 图表模板，仅在插件外没有对象使用时移除。使用 *GPU Node* 模板的设备、由 GPU 模板创建的数据源或图表、关联 GPU 图表模板的其他设备模板，以及使用 GPU 数据模板的 Thold 阈值模板，都会使其使用的模板保留。如果包含图表模板或数据模板的数据查询或设备模板被保留，该模板也会保留；
- `resource/script_server/plugin_gpu.xml`，仅当该文件由插件写入、未被修改，且数据查询已移除时。

Cacti 日志列出已移除和保留的内容。关闭该设置时，所有内容保留，直到您自行删除。由 GPU 模板创建的 Thold 阈值属于 Thold，始终保留。PHP 低于 8.2、插件无法加载时，卸载不移除任何 Cacti 对象。

<a id="unavailable-values"></a>
## 不可用值

插件无法确认可靠的值存储并显示为 `U`，绝不为 0，因此图表显示缺口，也从不触发阈值。

| 原因 | 受影响值 | 表现 |
|---|---|---|
| GPU 或驱动不报告字段（`[N/A]`、`[Not Supported]`） | 该字段 | 对应图线出现缺口 |
| ECC 已关闭或不支持 | 所有 ECC 和退役页面值 | 缺口；GPU 健康显示 *此 GPU 上的 ECC 已禁用* 或 *此 GPU 不报告 ECC* |
| 最近一次采集失败 | 节点各 GPU 上除 `age` 外的所有值 | 缺口；GPU 健康说明失败原因；`age` 继续增长 |
| 样本年龄超过 *过期时间* | 除 `age` 外的所有值 | 缺口；GPU 健康显示 *过期* |
| GPU 缺席 | 除 `age` 外的所有值 | 返回前持续缺口；`age` 继续增长；GPU 健康和清单显示 *缺席* |
| 首个样本、漏读或重新加载驱动 | `ecc_corr_new`、`ecc_uncorr_new` | 一个样本的缺口 |
| 节点时钟比 Cacti 快 120 秒以上 | 除 `age` 外的所有值 | 拒绝采集，视为采集失败；GPU 健康指出时钟问题 |
| 从未采样的 GPU | 所有值，包括 `age` | 缺口；GPU 健康显示 *从未观测* |

只有从未采样的 GPU 的 `age` 才未知，这正是针对它的过期阈值有效的原因。

<a id="troubleshooting"></a>
## 故障排除

从 **管理 > GPU 健康** 开始。它只读取存储的记录，因此打开页面绝不联系节点。每个节点行显示新鲜度、最近一次采集结果、是否受 Maint 维护控制，以及后端、工具和驱动版本；每个 GPU 行显示在场状态、样本年龄、ECC 状态和原始时钟事件掩码。Cacti 日志中的插件行带 `GPU` 标签。

| 最近一次采集 | 可能原因 | 处理方法 |
|---|---|---|
| 工具错误：无法访问 SNMP 代理 | 代理停止、地址或端口错误、防火墙或 SNMPv3 凭据错误 | 检查设备 SNMP 设置；Cacti 自身的设备页面会测试这些设置 |
| 工具错误：缺少 Extend 条目 | `snmpd.conf` 未包含 `extend` 行，或名称不同 | 按 [node-setup.md](node-setup.md) 添加 |
| 超时 | `nvidia-smi` 超过 `timeout(1)` 允许的 10 秒 | 检查节点驱动；持久模式可缩短驱动加载时间 |
| 权限被拒绝 | 工具拒绝运行 snmpd 的账户，或 GPU 数超过支持的 128 时，snmpd 对超出 100 KiB 的输出返回此状态 | 参阅 [research/privileges.md](../../../research/privileges.md)；检查 GPU 数量 |
| 工具错误：GPU 指标命令以状态 2 退出 | 时钟事件原因改名前的 `nvidia-smi` 不接受 `clocks_event_reasons.active` | 添加 [node-setup.md](node-setup.md#optional-an-nvidia-smi-older-than-the-clock-event-reasons-rename) 中可选的 `cacti_gpu_legacy` 行 |
| 工具错误：驱动未加载 | NVIDIA 驱动未加载或与库不匹配 | 修复节点驱动 |
| 工具错误：找不到工具 | 缺少 `/usr/bin/nvidia-smi` | 安装驱动工具或修正 `extend` 行中的路径 |
| 工具错误：输出被拒绝 | 字段数不对，通常是升级后仍使用旧 `extend` 行，或输出混入错误消息 | 从 [node-setup.md](node-setup.md) 复制当前行 |
| 工具错误：输出被截断 | 输出超过 snmpd 保留上限（100 KiB 或 1024 行） | 在每节点最多 128 GPU 的支持范围内不应发生（[node-setup.md](node-setup.md)）；请报告 |
| 工具错误：结果未存储 | 数据库拒绝写入 | 检查数据库和 Cacti 日志 |
| 工具错误：输出被拒绝：样本时间比 Cacti 服务器时钟快 N 秒 | 节点时钟快于 Cacti | 修复节点或 Cacti 服务器的时间同步；下一次采集会再次存储样本 |
| …；另一运行占用节点期间跳过了之后 N 次运行 | 下一次运行开始时，前一次仍在采集节点 | 通常为节点或代理缓慢；见 [performance.md](performance.md) |

其他症状：

- **最近一次采集已完成，但新鲜度为延迟或过期**：采集器没有每周期运行。检查 Cacti 轮询器是否运行，以及日志中是否有 GPU 错误。
- **Cacti 1.2.x 中使用非 161 SNMP 端口的节点在 Cacti 显示 Down**：见 [node-setup.md](node-setup.md#an-snmp-port-other-than-161-on-cacti-12x)。
- **GPU 健康显示采集完成，但图表停止或有缺口**：Cacti 无法写入 RRD 文件，例如磁盘已满或 `rra/` 所有权错误。插件存储样本后由 Cacti 自己写 RRD，因此阶段 1 的 GPU 健康不显示此错误；仅 Cacti 日志会显示轮询器的 `RRD` 或 `ERROR` 行。修复磁盘或权限；下一次轮询会重新写入。
- **新 GPU 没有图表**：插件仅在 Cacti 认为设备 Up 时重新索引；设备再次 Up 后会重试。
- **RRD 文件被重命名为包含 `.gpu-orphan-` 的名称**：新 GPU 数据源在路径上发现已有 RRD，通常是从比 RRD 目录更旧的备份恢复了数据库。该文件被移开，让新 GPU 开始自己的历史（[backup.md](backup.md)）。若重命名失败，数据源保持禁用，插件每周期重试；在启用前修正目录权限或移开旧文件。
