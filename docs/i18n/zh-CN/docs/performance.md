<!-- Source: docs/performance.md; SHA256: bde5c9d411b29e59818e12c1b4830dd2ac947cba5222b2e1fb366219a59113eb -->

<a id="performance-and-scale"></a>
# 性能与规模

针对 #49（REQ-PERF-001 至 REQ-PERF-004）的测量使用 `tests/bin/scale-flow.php`。结果来自一台开发工作站，而非生产硬件。应将其视为插件开销的上界，而非支持容量：生产 Cacti 服务器、数据库以及到真实节点的网络都不同。

<a id="machine"></a>
## 机器

| 项目 | 值 |
|---|---|
| 主机 | Apple M3 Max，14 核，36 GiB 内存 |
| 容器运行时 | OrbStack（Docker 29.8.2），虚拟机分配 14 个 CPU 和 15.7 GiB |
| Cacti | 1.2.31 和 1.2.x 分支，PHP 8.2，MariaDB 位于同主机容器 |
| 节点代理 | 容器中一个真实 net-snmp `snmpd`，通过 SNMPv3 服务所有节点；模拟 `nvidia-smi` 输出 8 GPU 测试数据 |

所有节点共享同主机上的一个代理，因此网络延迟接近零，每次读取仅计算一次代理成本。真实集群每次 SNMP 请求会增加网络往返及各节点运行 `nvidia-smi` 的成本（#25）。

<a id="method"></a>
## 方法

测试注册 `<gpus> / 8` 个 Cacti 设备，每个都是同一代理上的 GPU 节点。测试读取器包装插件的 SNMP extend 读取器，用设备 ID 改写每个 UUID，使各节点报告自身 GPU；每次读取仍是真实 SNMPv3 交换。测试测量两轮采集（首轮登记 GPU，第二轮为稳定状态）、脚本服务器输入（每节点一次 index 调用、每数据源项一次 get，与 Cacti 轮询器相同）和 GPU Health 渲染，并检查渲染不发送 SNMP 数据包。使用 `provision` 时，还通过插件配置器创建 Cacti 对象，执行一次轮询周期，并验证时间预算会提前停止采集轮次且统计跳过的节点。

<a id="results"></a>
## 结果

尚未记录。CI 在每个矩阵任务运行含 Cacti 对象的 100 GPU 场景；本机上含 Cacti 对象的 1,000 GPU 运行及 8,000 GPU 采集运行完成后，将在此记录结果和行数。在此之前，REQ-PERF-001 仍未完成，插件不声明支持的 GPU 数量。

<a id="what-this-supports"></a>
## 这些结果能支持什么

目前不能支持任何结论。1,000 个节点、每节点 8 个 GPU 的计划计算位于 [PLAN.md](../../../PLAN.md#performance)；只有在此完成测量并经生产规模硬件确认后，才声明支持数量。

<a id="not-measured"></a>
## 尚未测量

- 节点上真实 `nvidia-smi` 成本及最小间隔（#25）。
- 远程数据库、生产 Cacti 服务器和到节点的网络延迟。
- 8,000 GPU 下 Cacti 自身设备、数据源和图表页面的表现（REQ-PERF-004）：8,000 GPU 运行未配置 Cacti 对象。
