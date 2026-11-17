<!-- Source: docs/node-setup.md; SHA256: bd138c845671d32102a4985b87e4356d95897b131711cc0e498982e626b81a98 -->

<a id="setting-up-a-gpu-node"></a>
# 设置 GPU 节点

插件通过节点 SNMP 代理，使用 NET-SNMP-EXTEND-MIB 读取每个 GPU 节点的 `nvidia-smi` 输出（[ADR 0008](../../../decisions/0008-collect-through-snmp-extend.md)）。节点管理员在 `snmpd.conf` 中添加两个 `extend` 条目，仅在使用较旧 `nvidia-smi` 时添加第三个。插件使用该节点 Cacti 设备的 SNMP 设置，只向代理发送读取请求。节点除了 `snmpd`（net-snmp）之外不需要安装其他软件。

<a id="snmpdconf"></a>
## snmpd.conf

每个节点需要以下两行，必须保持原样。插件解析条目名称、字段列表及顺序；列表变更会作为无效输出拒绝，而不会误读。

<!-- extend-lines:start -->
```
extend -cacheTime 30 cacti_gpu /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_event_reasons.active --format=csv,noheader,nounits
extend -cacheTime 300 cacti_gpu_names /usr/bin/env -i /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=uuid,name --format=csv,noheader
```
<!-- extend-lines:end -->

各部分作用：

- 不带 `-execType sh` 的 `extend` 使用固定参数向量执行程序，不使用 shell。Cacti、节点或 GPU 提供的任何内容都不会进入命令行。
- `/usr/bin/env -i` 以空环境启动工具。指标条目仅设置 `TZ=UTC`，因为 `nvidia-smi` 输出的样本时间不含时区，插件将其按 UTC 读取。
- `/usr/bin/timeout -k 2 10` 在 10 秒后停止工具，再过 2 秒强制终止。snmpd 等待子进程时没有自身超时，因此缺少此限制时，挂起的 `nvidia-smi` 可能阻塞代理。
- `-cacheTime 30` 缓存一个样本 30 秒，超时短于该窗口，确保当前运行在下一次开始前结束。窗口内的 Cacti 轮询看到相同样本，插件报告样本自身年龄。
- GPU 名称来自单独条目，因为产品名称是可能包含 CSV 分隔符的自由文本。名称很少改变，因此该条目缓存 5 分钟。
- 所有命令及参数均低于 extend 的 510 字节限制。
- 每节点最多支持 128 个 GPU，使指标输出低于 snmpd 的 100 KiB 限制。采集测试数据中的指标行长 194 至 197 字节；若所有指标都输出 `[Not Supported]`，则为 370 字节，128 个 GPU 最多约 46 KiB。名称条目在测试数据中每 GPU 输出 64 字节。超过限制时，端到端测试中的 snmpd 返回状态 4 且无输出，插件显示为 *Permission denied*；原因尚未查明，请遵守此限制。

以下情况均视为失败采集，值未知，不作为数据：非零退出状态（包括超时）、输出在 snmpd 的 100 KiB 或 1024 行限制处截断、某行字段数与请求不符，以及输出中出现其他文本，例如 snmpd 从 stderr 合并的错误消息。

<a id="optional-an-nvidia-smi-older-than-the-clock-event-reasons-rename"></a>
### 可选：早于时钟事件原因重命名的 nvidia-smi

指标条目通过 `clocks_event_reasons.active` 请求时钟事件原因（REQ-NVSMI-005）。nvidia-smi 更新日志说明 v530 将 `clocks_throttle_reasons.*` 重命名，v535 又恢复旧查询名称（[研究](../../../research/clock-event-reasons.md)）；因此较旧 `nvidia-smi` 可能只识别旧名称。这些工具版本对应哪些驱动版本尚未确认。旧工具会以无效参数状态 2 拒绝指标条目，节点采集将失败，直到额外添加以下行：

<!-- extend-legacy:start -->
```
extend -cacheTime 30 cacti_gpu_legacy /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_throttle_reasons.active --format=csv,noheader,nounits
```
<!-- extend-legacy:end -->

`cacti_gpu_legacy` 使用相同位置上的旧名称 `clocks_throttle_reasons.active` 查询指标。插件仅在 `cacti_gpu` 以状态 2 退出时读取它，因此当前驱动不会执行该查询。两个名称输出相同位掩码，插件以相同方式解码。按本页旧版配置的节点必须将 `cacti_gpu` 行替换为上面的版本：此版本增加 `ecc.mode.current`、`retired_pages.sbe` 和 `retired_pages.dbe`，未替换时字段数量不匹配，采集显示 *Output rejected*。由于旧命令本身成功执行，状态 2 回退不能解决此情况。只有时钟事件原因名称允许不同：使用当前字段列表，最后一项为 `clocks_throttle_reasons.active` 的 `cacti_gpu` 行也可使用，因为两种名称都输出同一位掩码。

<a id="run-snmpd-as-an-unprivileged-user"></a>
## 以非特权用户运行 snmpd

`extend` 命令以运行 `snmpd` 的用户身份运行；若未设置 `agentuser` 或 `-u`，则为 root。以上字段无须特权（[research/privileges.md](../../../research/privileges.md)）。请以非特权账户运行代理：

```
agentuser Debian-snmp
```

使用发行版 snmpd 软件包提供的账户（Debian 和 Ubuntu 提供 `Debian-snmp` 并使用 `-u Debian-snmp` 启动），或自行创建。插件无法得知命令的运行用户，请在采集期间确认，例如运行 `ps -o user= -C nvidia-smi`。各发行版默认用户的真实检查记录在 #22。

<a id="snmpv3-is-the-default"></a>
## 默认使用 SNMPv3

使用提供认证和隐私保护的 SNMPv3，并将用户限制在 system 组和 extend 表：

```
view   cactigpu included .1.3.6.1.2.1.1
view   cactigpu included .1.3.6.1.4.1.8072.1.3.2
rouser -s usm cactigpu priv -V cactigpu
```

使用 `net-snmp-create-v3-user` 创建用户，或在 snmpd 停止时向持久化配置（Debian、Ubuntu 为 `/var/lib/snmp/snmpd.conf`）添加 `createUser cactigpu SHA-256 <auth passphrase> AES <privacy passphrase>`。随后将节点的 Cacti 设备设为 SNMP 版本 3，使用相同用户、SHA-256 认证及 AES 隐私保护。

SNMP v1 和 v2c 也可使用，但 community 和所有数据均以明文传输。如果使用，请将 community 限制在相同视图以及 Cacti 轮询器地址，例如 `rocommunity <community> <poller address> -V cactigpu`。

<a id="in-cacti"></a>
## 在 Cacti 中

1. 确保节点已经是 SNMP 设置可用的 Cacti 设备。如果不是，GPU Nodes 可按站点默认设置创建；之后请在 Cacti 设备页面配置 SNMP。
2. 在 **Console > Management > GPU Nodes** 为设备添加节点。保存时会添加 GPU 数据查询。
3. 采集器在每个轮询周期末尾运行。发现的每个 GPU 都成为 GPU 数据查询索引，插件为其创建图表。

暂不采集远程数据采集器上的节点；远程采集器属于阶段 3。

<a id="an-snmp-port-other-than-161-on-cacti-12x"></a>
### Cacti 1.2.x 上使用非 161 SNMP 端口

Cacti 的 1.2.x 分支（1.2.31 之后）在端口不是 161 的单个 SNMP get 中用方括号包裹设备地址，例如 `[192.0.2.10]:1161`。PHP SNMP 扩展将括号内名称视为 IPv6 地址，因此 IPv4 地址或没有 IPv6 地址的主机名会失败，Cacti 日志中出现 `Unknown failure while resolving '[192.0.2.10]:1161'`。Cacti 1.2.31 和使用端口 161 的节点不受影响。

插件与 Cacti 轮询器一样通过 Cacti SNMP 会话读取节点，仅对含冒号的地址加方括号，因此采集适用于任意端口。但 Cacti 自身 SNMP 可用性检查使用单个 get，因此使用 cmd.php 轮询器时，其他端口的节点会被标记离线，插件会等待后再重新索引。在 Cacti 修复前，1.2.x 上应让代理使用端口 161，或将设备 Downed Device Detection 设为 Ping 或 None。端到端测试采用后者。
