# Security self-review, phase 1

A review of the phase 1 trust boundaries by the plugin's author, at milestone 4. It is not an independent review: an external reviewer should still check the items at the end before a release. Vulnerabilities are reported privately ([SECURITY.md](../SECURITY.md)).

## Scope

The code on `main` plus milestone 4: the GPU Nodes, GPU Inventory, and GPU Health pages; the collector started from `poller_bottom`; the SNMP extend reader and the `nvidia-smi` parser; the script server input; the schema and template installer; and the shipped Thold templates. DCGM, AMD, MIG, and remote collectors are later phases and need their own review (PLAN, Security).

## Boundaries and controls

| Boundary | What crosses it | Controls in place | Where it is tested |
|---|---|---|---|
| Browser to Cacti | Page requests and form posts | Cacti's session and realms; every page file is in exactly one realm; every change is a POST that csrf-magic checks; each node, GPU, export row, and health row is shown only for a device the user may see (`is_device_allowed()`, evaluated per request); request values only through Cacti's accessors; output escaped where printed | `tests/bin/page-permissions.php` (CI): four users, a scoped GPU Admin, revocation on the next request, GET and token-less POST refused, escaping of hostile names; source rules |
| Device creation | A GPU Admin creating a device for a node | Needs Cacti's device management realm; a device its creator cannot see is removed again through `api_device_remove()` in the same request | Permission suite |
| Cacti to a GPU node | SNMP read requests only | No command runs on the node or the Cacti server, except the plugin's own collector; the `extend` commands are fixed by the node's administrator, with no shell, `env -i`, and `timeout(1)`; SNMPv3 with privacy is the documented default; no state-changing vendor command appears anywhere in the source | Source rules (no `exec` family call except the collector start; no reset, clock, power, ECC clear, or drain command); e2e |
| GPU node to Cacti | Tool output through the agent | Treated as untrusted: read by position, rejected as a whole on a wrong field count, a nonzero exit status, a cut or overlong output, a non-UUID identity, a bad bus ID, or duplicates; numbers parsed strictly, anything else is unknown; tool output is never quoted in a log or a stored error | Parser unit tests with hostile names; failure flow (cut output, agent limit) |
| Collector process | `exec_background()` from `poller_bottom` | One fixed script path, escaped with `cacti_escapeshellarg()`; a per-node lock stops two runs collecting the same node; a time budget | Source rule pins the one call |
| Script server input | Arguments from Cacti's poller | Host id must be a positive integer; the field must be a model field; the index is looked up with a prepared query; no node is contacted | Unit tests |
| Database | Every query | Prepared statements only, issued only from repository and schema classes; column names come from code constants, never from input | Source rule |
| Files | Data query XML, RRD files | The XML is generated from code and written to a fixed path; an RRD file found at a new data source's path is renamed with a fixed suffix, never deleted; no path comes from a request | Provisioner unit tests; restore flow |
| Logs and errors | Messages to Cacti's log and to pages | Plugin log lines name nodes and data sources by id and carry no hostname, UUID, tool output, or secret; the one path logged is Cacti's own script server directory when the install cannot write it; refusals shown to a user without access name nothing about the hidden node | Permission suite (REQ-PERM-005); unit tests |
| Thold and Maint | Shipped threshold templates; Maint's check | Static XML of Thold's own columns; the plugin sends no mail, webhook, or other notification; Maint is called only through its own function after checking it is enabled | Source rules; alert flow |
| Supply chain | Code that runs in production or CI | No Composer runtime dependencies; CI actions pinned by commit; Thold and Maint checked out by commit in CI | Source rule; workflow lint |

## Findings

No confirmed vulnerability was found in this review. Two behaviors were changed during milestone 4 as owner decisions rather than as security fixes: a GPU Admin may now delete a node whose device was deleted (it is still left out of the export), and a device its creator cannot see is removed in the same request.

Accepted risks, stated in the documentation:

- SNMP v1 and v2c work and send the community and every value in plaintext; the docs recommend SNMPv3 and a restricted view.
- On Cacti 1.2.x a node on a port other than 161 needs a non-SNMP availability method, a workaround for a core bracketing bug.
- The collector runs as Cacti's poller user with Cacti's database access; it is no more privileged than Cacti's own poller.

## For an external reviewer

1. Try to see or change a node, GPU, export row, or health row of a device the account may not see, through every page and action, including the move confirmation, retirement, and create-device paths.
2. Feed the parser hostile tool output through a real agent: control characters, very long fields, Unicode, many lines, duplicate UUIDs, a UUID of another node, and output that changes between the two extend reads.
3. Check the `extend` lines and the documented `snmpd.conf` view and user for anything that lets an SNMP reader run or change a command on the node.
4. Confirm that no page request, including GPU Health, sends an SNMP packet or runs a program, by watching the agent while loading every page.
5. Check the RRD rename for a path race or a symlink an unprivileged local user could plant in the RRD directory.
6. Check the log lines at every verbosity for hostnames, paths, UUIDs, or SNMP secrets.
7. Review the CI workflow's permissions and its pinned third-party actions and checkouts.
