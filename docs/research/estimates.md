# Estimates for phases 1 to 3

Research for [#27](https://github.com/somethingwithproof/plugin_gpu/issues/27), 2026-11-15. Question: how large is each remaining milestone, and what gates it?

**Result.** About 8,600 to 15,000 lines of production PHP across milestones 2 to 6, of which phase 1 (milestones 2 to 4) is about 5,900 to 10,100. Tests have run at 0.8 to 2.0 times production size in the two reference plugins. These are size ranges built from measured files in two earlier plugins by the same author; where a GPU component has no counterpart, the range says so and widens. **Elapsed time is not estimated:** the reference plugins' commit dates (a few days to weeks, with AI assistance) are not a basis for a duration, and every milestone here is gated by things outside the code, listed below. All of the evidence for M1 estimates is documentation and source; none of the hardware gates has been cleared.

## Basis

Measured 2026-11-15 with `git ls-tree` and `git show` on each `main` branch, counting lines of non-vendor PHP, shell, Perl, and Python; production excludes `tests/` and `features/`.

| Plugin | Ref | Production lines | Test lines | Test / production |
|---|---|---|---|---|
| [plugin_cloudflare](https://github.com/somethingwithproof/plugin_cloudflare) | `c08c99bbdfd0cea5fbd0da22dbde43bdb91ec4f0` | 10,378 | 20,347 | 1.96 |
| [plugin_smokeping](https://github.com/Cacti/plugin_smokeping) | `481547c54050d97f413d3f23408cf4e88e7ef39f` | 14,803 | 11,328 | 0.77 |

The plugin_cloud research measured 10,324 and 13,249 production lines for the same two plugins earlier on 2026-11-14 (`estimates.md` there); both branches have moved since. Cloudflare has no GPU-like component in some areas (accounts, a vault, a curl client); GPU has no credentials, so those areas are not anchors. The anchors below are individual files at these refs.

| Area | Anchor files (lines) |
|---|---|
| Lifecycle and bootstrap | cloudflare `setup.php` 177, `includes/bootstrap.php` 216, `includes/settings.php` 168; smokeping `setup.php` 635, `lib/Settings.php` 184 |
| Storage and migrations | cloudflare `src/Storage/Database.php` 201, `Migrator.php` 188; smokeping `lib/Schema.php` 266 |
| Repositories | cloudflare `src/Metrics/ResourceRepository.php` 154, `src/Jobs/JobRepository.php` 292; smokeping `lib/TargetRepository.php` 304, `lib/ConfigRepository.php` 264, `lib/ObjectRepository.php` 185 |
| Identity and reconciliation | cloudflare `src/Discovery/Reconciler.php` 208 |
| Pages | cloudflare `includes/accounts_page.php` 821, `includes/viewer.php` 677; smokeping `smokeping_health.php` 58 |
| Cacti objects and templates | cloudflare `includes/cacti_objects.php` 507, `includes/templates.php` 306, `includes/script_server.php` 136, `includes/polling.php` 171; smokeping `lib/SmokeProvisioner.php` 414, `lib/TemplateInstaller.php` 172, `lib/CactiObjectRepository.php` 239 |
| Discovery and observation | cloudflare `src/Discovery/DiscoveryService.php` 509, `src/Metrics/ObservationReader.php` 269 |
| External client | cloudflare `src/Api/CloudflareClient.php` 602, `src/Http/CurlTransport.php` 202 |

## Per milestone

| Milestone | Production lines | Anchors and what has no anchor | Gates outside the code |
|---|---|---|---|
| 2. Foundation | 2,500 to 5,000 | Lifecycle, storage, repositories, one page, identity reconciliation: anchor files sum to about 1,700 (smallest choice of each) to 3,900 (largest choice of each). No anchor: the PHP 8.0-parseable shim layer and version guard (ADR 0006), and the command runner with timeout and output cap (ADR 0007). Those two are why the range runs to 5,000; the added amount (about 800 to 1,100) is judgment. | Test harness choice with Cacti maintainers (#12); repository home (#11) |
| 3. NVIDIA collection and Cacti objects | 2,700 to 3,700, plus template XML and data query XML | Cacti objects, templates, script server, polling, discovery, observation reader, one external tool wrapper (cloudflare client plus transport as the closest shape, 804): about 2,700 from cloudflare files alone, 3,300 adding smokeping's provisioner and installer. No anchor: the nvidia-smi CSV parser and fixture loader, which is why the upper figure is 3,700. | Real `nvidia-smi` fixtures for each driver line (#14, #26); identity decisions (#9, #10) |
| 4. Alerting, health, and phase 1 release | 600 to 1,400, mostly tests and docs | Health page (smokeping 58 to cloudflare 677); throttle decoder and ECC and XID signals (small, no anchor). Thold and Maint integration needs tests, not much production code. | Pilot on real hardware; a security review where the XID source needs privilege (#8); performance runs at 100, 1,000, and 8,000 GPUs (#25) |
| 5. DCGM and AMD backends | 1,200 to 2,400 | Two backends, each wrapper-sized; the exporter text parser and the AMD JSON unwrapper have no anchor. | Fixtures from DCGM and AMD nodes; security review of invocation and outbound request paths; AMD identity result (#19) |
| 6. MIG and remote collectors | 1,500 to 2,500 | MIG identity and field handling (about 500 to 1,000, judgment, no anchor); remote collector protocol and `remote_agent` actions (1,000 to 1,500, the same figure the plugin_cloud research used for its remote milestone, not re-derived: neither reference plugin has a released remote collector protocol of this kind) | MIG hardware; a Cacti remote collector test bed; security review of `remote_agent` actions |

Totals: phase 1 (M2 to M4) 5,900 to 10,100; phases 2 and 3 (M5 and M6) 2,700 to 4,900; all milestones 8,600 to 15,000. For comparison, the two reference plugins as a whole are 10,378 and 14,803.

Tests: from 0.8 to 2.0 times production lines, the range seen in the two plugins.

## Uncertainty

- The largest unknown in M2 is the command runner and the PHP 8.0 shim boundary; neither reference plugin has them.
- M3 depends on decisions #7 (collection path) and #13 (MIG representation); a different path changes the backend and transport pieces, not the Cacti object pieces.
- M5 and M6 have no direct precedent in code that has shipped; their ranges are judgment around the nearest anchors.
- No live check from M1 has run. A hardware fact that differs from the documentation (for example a placeholder string or an unprivileged failure) lands first in M3's parser and M4's privilege story.
- The line counts include AI-assisted code in both reference plugins; they say how large similar work was, not how long it took a person.

## Limits

- No duration is stated. Calendar effort depends on capacity not documented in this repository.
- The anchors are single files, not features; a file can hold more or less than the corresponding GPU component.
- The M6 remote figure repeats the plugin_cloud research rather than being re-derived.
