# Setting up a GPU node

The plugin reads each GPU node's `nvidia-smi` output through the node's SNMP agent, using NET-SNMP-EXTEND-MIB ([ADR 0008](decisions/0008-collect-through-snmp-extend.md)). The node's administrator adds two `extend` entries to `snmpd.conf`, and a third only for an older `nvidia-smi`; the plugin sends the agent nothing but read requests, through the SNMP settings of the node's Cacti device. Nothing is installed on the node besides `snmpd` (net-snmp).

## snmpd.conf

Every node needs these two lines, exactly as shown. Preserve the entry names, the field list, and its order: the plugin parses fields by position. A wrong field count is rejected, but reordering valid numeric fields can be misread without detection.

<!-- extend-lines:start -->
```
extend -cacheTime 30 cacti_gpu /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_event_reasons.active --format=csv,noheader,nounits
extend -cacheTime 300 cacti_gpu_names /usr/bin/env -i /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=uuid,name --format=csv,noheader
```
<!-- extend-lines:end -->

What each part does:

- `extend` without `-execType sh` runs the program with a fixed argument vector and no shell. Nothing from Cacti, the node, or a GPU reaches the command line.
- `/usr/bin/env -i` starts the tool with an empty environment. The metrics entry sets only `TZ=UTC`, because `nvidia-smi` prints its sample time without a time zone and the plugin reads it as UTC.
- `/usr/bin/timeout -k 2 10` stops the tool after 10 seconds and kills it 2 seconds later. snmpd waits for its child with no timeout of its own, so without this a hung `nvidia-smi` can block the agent.
- `-cacheTime 30` keeps one sample for 30 seconds, and the timeout is below that window, so a run always ends before the next can start. Every Cacti poll inside the window sees the same sample, and the plugin reports the sample's own age.
- The GPU names come from a separate entry because a product name is free text that can contain the CSV separator. Names change rarely, so that entry is cached for 5 minutes.
- Every command is under extend's 510-byte limit for a command and its arguments.
- At most 128 GPUs per node are supported, so the metrics output stays well under snmpd's 100 KiB limit. A metrics line measured 194 to 197 bytes in the captured fixtures, and 370 bytes if every metric printed `[Not Supported]`: 128 GPUs is at most about 46 KiB. The names entry's own output is 64 bytes per GPU in the fixtures. Above the limit, snmpd in the end-to-end test returned exit status 4 with no output, which the plugin then shows as *Permission denied*; the cause is not understood, so stay within the limit.

The plugin treats each of these as a failed collection with unknown values, not as data: a nonzero exit status (including the timeout), output cut at snmpd's 100 KiB or 1024-line limit, a line with more or fewer fields than requested, and any other text in the output, such as an error message that snmpd merged in from stderr.

### Optional: an nvidia-smi older than the clock event reasons rename

The metrics entry asks for the clock event reasons as `clocks_event_reasons.active` (REQ-NVSMI-005). nvidia-smi's changelog renamed them from `clocks_throttle_reasons.*` in v530 and added the old query names back in v535 ([research](research/clock-event-reasons.md)), so an older `nvidia-smi` can know only the old name; which driver releases those versions map to is not confirmed. Such a tool refuses the metrics entry with status 2, nvidia-smi's status for an invalid argument, and the node's collection fails until you add this line as well:

<!-- extend-legacy:start -->
```
extend -cacheTime 30 cacti_gpu_legacy /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_throttle_reasons.active --format=csv,noheader,nounits
```
<!-- extend-legacy:end -->

`cacti_gpu_legacy` is the metrics query with the old name, `clocks_throttle_reasons.active`, in the same position. The plugin reads it only when `cacti_gpu` exits with status 2, so on a current driver it never runs. Both names print the same bitmask, and the plugin decodes it the same way. A node set up from an earlier version of this page needs its primary metrics line replaced with the `cacti_gpu_legacy` query shown above, changing only its entry name to `cacti_gpu` and keeping `clocks_throttle_reasons.active` as the final field: this release adds `ecc.mode.current`, `retired_pages.sbe`, and `retired_pages.dbe` to the field list, and until the line is replaced the node's collection fails with *Output rejected*, because the line count of fields no longer matches. The status 2 fallback does not cover that case, since the old line runs successfully. Only the clock event reasons name may differ: a `cacti_gpu` line with the current field list and `clocks_throttle_reasons.active` in the last position also works, because both names print the same bitmask there.

## Run snmpd as an unprivileged user

An `extend` command runs as the user `snmpd` runs as, which is root unless `agentuser` or `-u` is set. The fields above need no privilege ([research/privileges.md](research/privileges.md)). Run the agent as an unprivileged account:

```
agentuser Debian-snmp
```

Use the account your distribution's snmpd package provides (Debian and Ubuntu ship `Debian-snmp` and start snmpd with `-u Debian-snmp`), or create one. The plugin cannot see which user ran the command, so confirm it while a collection runs, for example with `ps -o user= -C nvidia-smi`. Which user each distribution uses by default is a live check in #22.

## SNMPv3 is the default

Use SNMPv3 with authentication and privacy, and limit the user to the system group and the extend tables:

```
view   cactigpu included .1.3.6.1.2.1.1
view   cactigpu included .1.3.6.1.4.1.8072.1.3.2
rouser -s usm cactigpu priv -V cactigpu
```

Create the user with `net-snmp-create-v3-user`, or with a `createUser cactigpu SHA-256 <auth passphrase> AES <privacy passphrase>` line in snmpd's persistent configuration (`/var/lib/snmp/snmpd.conf` on Debian and Ubuntu), added while snmpd is stopped. Then set the node's Cacti device to SNMP version 3 with the same user, SHA-256 authentication, and AES privacy.

SNMP v1 and v2c also work, but they send the community and every value in plaintext. If you use them, restrict the community to the same view and to the Cacti poller's address, for example `rocommunity <community> <poller address> -V cactigpu`.

## In Cacti

1. Make sure the node is a Cacti device with working SNMP settings. If it is not, GPU Nodes can create one from the site's device defaults; set its SNMP settings on Cacti's device page afterwards.
2. In **Console > Management > GPU Nodes**, add a node for the device. Saving the node adds the GPU data query to the device.
3. The collector runs at the end of every poller cycle. Each GPU it finds becomes an index of the GPU data query, and the plugin creates the GPU graphs for it.

Nodes on remote data collectors are not collected yet; remote collectors are phase 3.

### An SNMP port other than 161 on Cacti 1.2.x

Cacti's 1.2.x branch (after 1.2.31) puts the device's address in brackets for a single SNMP get when the port is not 161, as in `[192.0.2.10]:1161`. PHP's SNMP extension reads a bracketed name as an IPv6 address, so the get fails for an IPv4 address or for a host name with no IPv6 address, with `Unknown failure while resolving '[192.0.2.10]:1161'` in Cacti's log. Cacti 1.2.31 and a node on port 161 are not affected.

The plugin reads the node through a Cacti SNMP session, as Cacti's poller does, which brackets only an address that contains a colon, so collection works on any port. Cacti's own SNMP availability check uses the single get, though, so with cmd.php as the poller a node on another port is marked down and the plugin then waits to reindex it. On Cacti 1.2.x, until that is fixed in Cacti, keep the node's agent on port 161, or set the device's Downed Device Detection to Ping or None. The end-to-end test does the latter.
