# Backup and restore

GPU history lives in two places that must be backed up together: the records that say which GPU a data source belongs to, in the database, and the history itself, in the RRD files (REQ-BACKUP-001).

## What to back up

| What | Where | Why |
|---|---|---|
| The Cacti database, whole | Including every `plugin_gpu_*` table and the `settings` rows named `gpu_*` | Nodes, GPU records keyed by UUID, the latest samples, and the data sources, graphs, and data query cache that tie each GPU to its RRD file |
| The RRD directory | `<cacti>/rra` by default | The history |
| The plugin settings | The `gpu_*` rows of Cacti's `settings` table, inside the database dump | *Mark Absent After*, *Stale After*, *Keep Plugin Tables on Uninstall*, and the schema version |
| Each node's `snmpd.conf` extend lines | On the node | Not Cacti's to restore; copy them from [node-setup.md](node-setup.md) |

Take the database dump and the RRD copy at the same time, with Cacti's poller stopped or between cycles, so neither is newer than the other. A consistent dump of a running database:

```
mysqldump --single-transaction --routines cacti > cacti.sql
```

## Restore

1. Stop Cacti's poller (its cron entry or service).
2. Restore the database: `mysql cacti < cacti.sql`.
3. Restore the RRD directory from the same backup.
4. Start the poller. The plugin's next collection checks every GPU against its records by UUID, so a GPU keeps its data source and RRD file and its values continue in them.

## A database older than the RRD directory

If only the database is restored, or the dump is older than the RRD files, Cacti hands out the data source ids it had at the time of the dump again, and Cacti names RRD files after the data source id. A GPU that first appears after the restore could then get a data source whose file name belongs to a file already on disk, with another GPU's history in it.

The plugin prevents that (REQ-BACKUP-003): when it creates a data source for a GPU and an RRD file already exists at that data source's path, it renames the file with the suffix `.gpu-orphan-<time>` before Cacti writes to it, and logs a warning naming the data source. The new GPU starts its own history. The renamed file is kept; if it holds history you need, attach it by hand to the right data source after checking which GPU it belonged to.

## Tested

`tests/bin/restore-flow.php` runs both cases end to end in CI, with `mysqldump` and `mysql`, against a real `snmpd`:

- restoring a dump over a database that had moved on (a GPU removed) brings back the backed-up inventory, and every GPU keeps its data source and RRD file, with its own values continuing in it (REQ-BACKUP-002);
- restoring a dump taken before four GPUs existed, then letting four different GPUs arrive, gives them data sources whose RRD file names are taken; each existing file is moved aside before Cacti writes, and each new file holds only its new GPU's values.
