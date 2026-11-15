# SNMP extend as a transport

Research for [#22](https://github.com/somethingwithproof/plugin_gpu/issues/22), 2026-11-15. Question: can `snmpd` `extend` carry per-GPU metrics to Cacti with a stable GPU key, bounded cost, and acceptable security? The manual describes `extend [MIBOID] NAME PROG ARGS`, a first-line and full-output table, and per-entry caching, but not the user the command runs as.

**Result.** The net-snmp source answers the run-as and limits questions the manual leaves open. An `extend` command runs as the user `snmpd` runs as: `execute.c` forks and `execv`s with no `setuid`, and `snmpd` changes user only when `agentuser` or `-u` is set, so on a default install the command runs as root and with `agentuser` as that user. Output is capped at 100 KiB, command plus arguments at 512 bytes, results are cached for 5 seconds by default (per entry with `-cacheTime`), the child's stderr is merged into stdout, and the load runs inside the agent's request path, so a slow `nvidia-smi` delays other SNMP answers (inference from code structure; not measured). The per-line table is indexed by the command's name and a line number, not by the GPU UUID, so a UUID key cannot come from the SNMP index; it must be read from the line's value. Whether Cacti's SNMP data query machinery can use a value as the index was only partly resolved (see below) and is unverified. Net effect on #7: option B works as a transport only with a plugin parser, and it makes `snmpd`, a root process by default, the command runner; the measurement part of #22 needs a node. **No design or decision change is applied here; the evidence is summarized in [collection-path.md](collection-path.md).**

## Method

Read net-snmp `v5.9.5.2` (`agent/mibgroup/agent/extend.c`, `utilities/execute.c`, `agent/snmpd.c`, `agent/helpers/cache_handler.c`, `mibs/NET-SNMP-EXTEND-MIB.txt`) and compared `extend.c` with `master`; read Cacti's SNMP data query code at `release/1.2.31`. Nothing was run; no snmpd was started.

## Pins

| Item | Ref | Commit |
|---|---|---|
| net-snmp | tag `v5.9.5.2` | `319bbd0bb36547992c0e1302fef278c6f49d0c80` |
| net-snmp | `master` head, 2026-11-13 (`extend.c` differs; `execute.c` differs by 2 changed lines) | `33d75f8ead9e87f7f73f71a2753126819c31355b` |

## Findings

| Fact | Status | Source |
|---|---|---|
| Directive grammar: `extend [-cacheTime SECS] [-execType exec|sh] [MIBOID] NAME PROG ARGS`; `extend-sh` and `extendfix` also exist. | confirmed | [`extend.c` lines 294-296, 525-605](https://github.com/net-snmp/net-snmp/blob/319bbd0bb36547992c0e1302fef278c6f49d0c80/agent/mibgroup/agent/extend.c#L525-L605) |
| Without `-execType sh` the command is run by `run_exec_command()`, which `fork()`s and calls `execv()` on the tokenized command, with no shell. With `sh` or `extend-sh` a shell is used. | confirmed | `execute.c` lines 192-265 (`execv(argv[0], argv)`); `extend.c` lines 358-361 |
| No `setuid` or `setgid` appears in `execute.c`; `snmpd.c` calls `setgid` and `setuid` only for configured `agentgroup` and `agentuser` (user id > 0). So the child has `snmpd`'s identity at the time the cache loads. | confirmed (source) | `grep -c 'setuid\|setgid' execute.c` returns 0; [`snmpd.c` lines 975-1020](https://github.com/net-snmp/net-snmp/blob/319bbd0bb36547992c0e1302fef278c6f49d0c80/agent/snmpd.c#L975-L1020); `agent_read_config.c:278-280` registers `agentuser` and `agentgroup` |
| Distribution packaging decides whether `agentuser` or `-u` is set (for example a dedicated `snmp` account). | unverified | none read |
| The child's stdout and stderr both go to the output pipe (`dup2(STDOUT_FILENO, STDERR_FILENO)`), so a warning on stderr becomes part of the first line or an output line. | confirmed | `execute.c` lines 245-255 |
| Output buffer is `1024*100` bytes; command and arguments are formatted into `255*2 + 2` bytes; the first line, full output, number of lines, and exit result are stored. The line pointer array in 5.9.5.2 has 1024 entries and is filled without a bound check; `master` adds a bound at the array size. | confirmed (source); the 1024-line limit is a practical cap to respect | `extend.c` lines 336-392; `git diff v5.9.5.2 master -- extend.c` |
| Cache: every extend entry has its own cache created with timeout 0, which takes the application default `NETSNMP_DS_AGENT_CACHE_TIMEOUT` (5 s, set in `snmpd.c:470`); `-cacheTime` overrides per entry. | confirmed | `extend.c` lines 473, 541-543, 603-604; `cache_handler.c:199-201`; `snmpd.c:470` |
| The command runs when the cache expires and a request touches the table, within the agent process; the parent waits for the child in a `select` loop with 1 s timeouts up to `NETSNMP_MAXREADCOUNT` (100) iterations. If the child has not been reaped when the loop ends (loop exhausted or output buffer full), the parent calls `waitpid(pid, &result, 0)` with no timeout (`execute.c` lines 413-418), so a command that never exits can block the agent indefinitely; a bounded wait is a requirement before option B is adopted. | confirmed (source); effect on other SNMP requests not measured | `execute.c` lines 273-330; `configure.d/config_net_snmp_config_h:316` |
| Tables: `nsExtendOutput1Table` has one row per command, indexed by the command name (`nsExtendToken`), with `nsExtendOutput1Line` (first line), `nsExtendOutputFull`, `nsExtendOutNumLines`, `nsExtendResult`. `nsExtendOutput2Table` is indexed by `{ nsExtendToken, nsExtendLineIndex }` with `nsExtendOutLine`. | confirmed | [`NET-SNMP-EXTEND-MIB.txt`](https://github.com/net-snmp/net-snmp/blob/319bbd0bb36547992c0e1302fef278c6f49d0c80/mibs/NET-SNMP-EXTEND-MIB.txt) lines 63, 197-218, 260-279 |
| Write support for creating entries over SNMP is a compile-time switch (`ENABLE_EXTEND_WRITE_ACCESS`, 0 by default) with the comment "potentially dangerous ... allows arbitrary commands to be set by anyone with SNMP WRITE access". | confirmed | `extend.c` lines 19-23 at v5.9.5.2 (shown in the diff to master) |
| The line index is the position of a line in the output, so it carries no GPU identity; a GPU leaving the list shifts later lines. | inference from the MIB definition and the line-splitting code | MIB; `extend.c` lines 380-392 |

## Can Cacti key a data query on the UUID?

- Cacti's SNMP data query walks `oid_index` and uses the walked suffix as the index, optionally narrowed with `oid_index_parse` ([`lib/data_query.php:873-989`](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L873-L989)). For `nsExtendOutput2Table` the suffix is `NAME.<line>`, so the index is a line number.
- Core has a `rewrite_index` field attribute that rewrites the index through a value lookup (`data_query.php:1000-1074`), but it is intended for mapping one OID column to another, and nothing read shows it producing a non-numeric string index from a value inside a single CSV line.
- The plugin design already reads a cache through a script server data query; with option B the collector would read the extend output over SNMP, parse the CSV line, and write the same cache. That keeps the UUID index and puts the SNMP table behind a parser. This is inference, not a test.

Status: UUID as an SNMP-native Cacti index is **unverified and unlikely**; a plugin parser is the working design.

## Consequences

- Option B needs `snmpd` on each GPU node configured by the node's administrator with one extend line, for example `extend gpu /usr/local/bin/gpu-csv` (illustrative, not a recommendation), which becomes the "fixed command" of the plan's security row. The command is the node administrator's script; the plugin sends no input.
- The run-as user is whatever `snmpd` is. Many sites run `snmpd` as root; there, the extend script runs as root. The script needs no privilege for the plan's queries (see [privileges.md](privileges.md)), so the security posture depends on the administrator dropping to `agentuser` or running the script through a wrapper that drops privilege. That is a documentation requirement for the plugin, not something the plugin can enforce.
- Stderr merging means the script must discard stderr or the parser must reject lines that do not match the field count (the plan's validation rule already does this).
- The 5 s default cache means Cacti polls inside one cache window see identical values. The plugin's `age` field cannot be derived from SNMP; the script must print its own timestamp as a field.
- Output limits to respect: under 100 KiB and under 1024 lines per command (on v5.9.5.2 exceeding 1024 lines writes past a fixed array, so the script must enforce the cap itself). One line per GPU on a single node is far below both limits for any node size in the plan.

## Limits

- Nothing was started or measured. The cost of a slow command on `snmpd` responsiveness is an inference from the code.
- The `agentuser` default per distribution is unverified.
- The effect of SNMPv3 views and `extend` access control (`rocommunity` views) was not read.
- `master` was not read beyond the lines compared.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #22 | Configure a fixed `extend` script on a lab node; read `nsExtendOutput2Table` with `snmpwalk` from the poller; record `ps` user of the script on each supported distribution | Run-as user per distribution and the effect of `agentuser` |
| #22 | Make the script sleep 2, 5, and 20 s; time simultaneous `snmpget` of `sysUpTime` | Whether a slow command delays other SNMP answers |
| #22 | Remove a GPU from the script's output; compare the line numbers | Index drift |
| #22 | Run a Cacti data query XML against the table with `oid_index` set to `nsExtendOutLine` | Whether a UUID (or any value) can be the Cacti index through core mechanisms |
| #22 | Print more than 1024 lines from a test script on the oldest and newest supported net-snmp, using an isolated test `snmpd` only: on v5.9.5.2 the line pointer array has 1024 entries and no bound check, so this can crash or corrupt the agent | Behavior at the line cap |
