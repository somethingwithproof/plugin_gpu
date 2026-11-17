<!-- Source: docs/backup.md; SHA256: 5a35172548eec7a8790baefe613b438587759d856d38b6b9357e7d13684e11aa -->

<a id="backup-and-restore"></a>
# 备份与恢复

GPU 历史分布在两个必须一起备份的位置：数据库中记录数据源所属 GPU 的记录，以及 RRD 文件中的历史数据本身（REQ-BACKUP-001）。

<a id="what-to-back-up"></a>
## 备份内容

| 内容 | 位置 | 原因 |
|---|---|---|
| 整个 Cacti 数据库 | 包括所有 `plugin_gpu_*` 表及名为 `gpu_*` 的 `settings` 行 | 节点、按 UUID 标识的 GPU 记录、最新样本，以及将 GPU 与 RRD 文件关联的数据源、图表和数据查询缓存 |
| RRD 目录 | 默认 `<cacti>/rra` | 历史数据 |
| 插件设置 | 数据库备份中 Cacti `settings` 表的 `gpu_*` 行 | *Mark Absent After*、*Stale After*、*Keep Plugin Tables on Uninstall* 和模式版本 |
| 每个节点的 `snmpd.conf` extend 行 | 节点上 | 不由 Cacti 恢复；请从 [node-setup.md](node-setup.md) 复制 |

在 Cacti 轮询器停止或两个周期之间，同时进行数据库转储和 RRD 复制，使两者时间一致。运行中数据库的一致性转储：

```
mysqldump --single-transaction --routines cacti > cacti.sql
```

<a id="restore"></a>
## 恢复

1. 停止 Cacti 轮询器（cron 条目或服务）。
2. 恢复数据库：`mysql cacti < cacti.sql`。
3. 从同一备份恢复 RRD 目录。
4. 启动轮询器。插件下次采集按 UUID 对照记录检查每个 GPU，使其保留原数据源和 RRD 文件，后续值继续写入其中。

<a id="a-database-older-than-the-rrd-directory"></a>
## 数据库早于 RRD 目录

如果只恢复数据库，或转储早于 RRD 文件，Cacti 会重新分配转储时已有的数据源 ID，而 RRD 文件按数据源 ID 命名。恢复后首次出现的 GPU 可能因此获得一个数据源，其文件名对应磁盘上已有的另一个 GPU 的历史文件。

插件会防止这种情况（REQ-BACKUP-003）：为 GPU 创建数据源时，若该路径已存在 RRD 文件，会在 Cacti 写入前将其重命名，加上 `.gpu-orphan-<time>` 后缀，并记录包含数据源名称的警告。新 GPU 从自己的历史开始。重命名的文件会保留；如需其中历史，请核实所属 GPU 后，手动关联到正确的数据源。

<a id="tested"></a>
## 已测试内容

`tests/bin/restore-flow.php` 在 CI 中使用 `mysqldump` 和 `mysql`，针对真实 `snmpd` 端到端执行以下两种情况：

- 在已经发生变化（移除某 GPU）的数据库上恢复转储，可恢复备份的清单，且每个 GPU 保留自身数据源和 RRD 文件，继续写入自身数据（REQ-BACKUP-002）；
- 恢复在四个 GPU 出现前创建的转储，再让四个不同 GPU 出现，会为它们分配文件名已占用的数据源。Cacti 写入之前，已有文件分别移开，新文件只包含对应新 GPU 的数据。
