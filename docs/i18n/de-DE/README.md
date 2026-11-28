<!-- Source: README.md; SHA256: 97ba37df4a883426d3984ed7d14547ad7367e973411a8abd930409ce259f545b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [Български](../bg-BG/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](README.md) | [Ελληνικά](../el-GR/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [ქართული](../ka-GE/README.md) | [한국어](../ko-KR/README.md) | [Latviešu](../lv-LV/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Português (Portugal)](../pt-PT/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Ein Cacti-1.2-Plugin, das Auslastung, Speicher, Temperatur, Leistungsaufnahme, ECC-Fehler und Drosselungsgründe pro GPU aus NVIDIA- und AMD-Tools in Cacti einbindet. Jede GPU (oder MIG-Instanz) wird zu einem Index einer Datenabfrage auf dem Gerät ihres Knotens, sodass GPU-Graphen neben den anderen Graphen des Knotens unter den Berechtigungen von Cacti liegen und Thold sowie Maint darauf aufbauend alarmieren und Warnungen unterdrücken können.

> **Status: Phasen 1 bis 3 im Code implementiert, noch nicht veröffentlicht.** Phase 1 (NVIDIA `nvidia-smi` über SNMP-Extend, Identitätsverfolgung, Graphen, Drosselungs- und ECC-Signale, Alarmierung über Thold und Maint, Statusseite), Phase 2 (schreibgeschützter NVIDIA DCGM Exporter HTTP(S)-Parser/Client und AMD `amd-smi`-Parser), Phase 3 (Verfolgung der Identität von MIG-Instanzen, Schema-v4-Spalten, Poller-gebundene Ausführung von Remote Collectoren sowie `replicate_out`- und `remote_agent`-Hooks), die CasC-Betreiber-CLI (`cli/gpu.php`) und Ökosystem-Hooks (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) sind implementiert und durch Unit-, Sicherheits- und Wegwerf-Cacti-Integrationstests abgedeckt. Live-Läufe nutzen bislang synthetischen `snmpd` und Fixture-Nutzdaten statt physischer GPU-Hardware. Vor dem Taggen eines Releases stehen noch aus: Pilottests auf echter Hardware ([docs/pilot-plan.md](../../pilot-plan.md), #54), ein externes Sicherheitsaudit ([docs/security-review.md](../../security-review.md)), auf realen Tools aufgezeichnete Testdaten (#14) sowie Skalierungsmessungen auf produktionsnaher Hardware ([docs/performance.md](../../performance.md)). Die Erfassung von XID-Fehlern bleibt unter Entscheidung #8 offen.

## Zugriffsrollen

| Berechtigungsbereich | Zugriff |
| --- | --- |
| GPU View | Bestehende Überwachungsseiten. |
| GPU Operator | Überwachungsseiten sowie die Operations-Seite und persönliche Triage-Bestätigungen. |
| GPU Admin | Operator-Zugriff sowie die bestehenden Konfigurations- und Verwaltungsaktionen. |

Öffnen Sie nach dem Kopieren der Upgrade-Dateien als Administrator die Plugins-Seite, um den neuen Berechtigungsbereich zu registrieren. Weisen Sie den Operator-Bereich explizit unter **Configuration > Users** oder über Benutzergruppen zu. Upgrades behalten die bestehenden Realm-IDs und -Berechtigungen für View und Admin bei. Der Admin-Zugriff erbt bei jeder Anfrage den Operator-Zugriff, und der Operator-Zugriff erbt den View-Zugriff; dadurch werden keine dauerhaften Berechtigungen für Benutzer oder Gruppen hinzugefügt. Plugin-Einstellungen erfordern neben der Cacti-Berechtigung für Einstellungen zusätzlich GPU-Admin-Zugriff.

Cacti-Geräteberechtigungen schränken weiterhin die Zeilen und Aktionen ein, die jedem Benutzer zur Verfügung stehen; nicht zugeordnete Objekte erfordern einen Administrator. Zugangsdaten, Konfiguration, Zuordnung, Löschung und andere administrative Änderungen verbleiben auf den Verwaltungsseiten. Bestätigungen gelten nur in der aktuellen Benutzersitzung, für höchstens eine Stunde und nur solange derselbe Messwert aktuell bleibt. Neue oder geänderte Beobachtungen machen sie ungültig. Sie markieren eine fehlgeschlagene Messung niemals als fehlerfrei und unterdrücken keine Alarme.

## Warum

GPU-Knoten sind in der Regel bereits für CPU, Arbeitsspeicher und Netzwerkschnittstellen in Cacti erfasst. Der Zustand der GPUs ist eine separate Fragestellung, die die SNMP-Daten des Knotens nicht beantworten: Eine Karte kann thermisch gedrosselt sein, ECC-Fehler ansammeln oder einen XID-Fehler melden, während der Knoten selbst unauffällig erscheint. Hersteller-Tools melden dies (`nvidia-smi`, DCGM, `amd-smi`), ihre Ausgaben liegen jedoch außerhalb der Graphen, Berechtigungen und Alarmierung von Cacti.

Das Plugin schließt diese Lücke, indem es die Ausgaben der Hersteller-Tools in Cacti-Datenquellen umwandelt, die über die Identität jeder GPU statt über ihre Position indiziert sind, sodass ein Neustart mit geänderter GPU-Reihenfolge nicht die Historie einer Karte an eine andere anhängt. Es nutzt die Graphen, Berechtigungen, Remote Data Collectors, Thold und Maint von Cacti weiter, statt parallele Eigenentwicklungen aufzubauen.

## Geplanter Funktionsumfang

| Phase | Umfang | Status | Meilensteine |
|---|---|---|---|
| 0, Forschung | Ausgaben der Hersteller-Tools, XID- und Drosselungssignale, GPU-Identität, Berechtigungen, Entscheidung über den Erfassungspfad | Abgeschlossen | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Erfassung über `nvidia-smi`, Inventar nach UUID, Graphen, Drosselungs- und ECC-Signale, Thold und Maint, Statusseite | Implementiert (Fixture-getestet) | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, Backends | Schreibgeschützter DCGM Exporter HTTP(S)-Parser/Client (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`), AMD-`amd-smi`-Parser (`lib/AmdSmiParser.php`) | Implementiert (Unit-getestet) | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, MIG, Remote und Ökosystem | Identitätsverfolgung von MIG-Instanzen, Remote Data Collectors (`INFO`, `poller_gpu.php --poller=N`, `replicate_out`, `remote_agent`), CasC-CLI (`cli/gpu.php`), MCP/Doctor/CasC-Hooks | Implementiert (Unit- und CI-Integrationstest) | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Nicht im Umfang enthalten sind: Befehle, die eine GPU oder einen Knoten verändern (Taktraten, Leistungslimits, Resets, MIG-Layout), eine plugin-eigene Alarmierungs-Engine, ein eigener Daemon oder ein eigenes Netzwerkprotokoll sowie Scheduler- oder Job-Abrechnung. Den Forschungs-Backlog finden Sie im Projektplan.

## Neuerungen in Meilenstein 4

- **Schreibgeschützter Collector für NVIDIA DCGM Exporter** (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`). Parst Prometheus-Textexpositionsmetriken (`DCGM_FI_DEV_GPU_UTIL`, `DCGM_FI_DEV_FB_USED`, `DCGM_FI_DEV_FB_FREE`, `DCGM_FI_DEV_POWER_USAGE`, `DCGM_FI_DEV_GPU_TEMP`, `DCGM_FI_DEV_ECC_DBE_VOL_TOTAL`, `DCGM_FI_DEV_CLOCK_THROTTLE_REASONS` sowie die MIG-Labels `GPU_I_ID` und `GPU_I_PROFILE`). Der HTTP(S)-Client erzwingt eine explizite Host-/CIDR-Positivliste für Endpunkte, prüft standardmäßig TLS-Peer-Zertifikate, begrenzt Zeitlimits und Antwortgrößen, deaktiviert Weiterleitungen und lehnt Nicht-HTTP(S)-Schemata ab.
- **Schreibgeschützter Parser für AMD `amd-smi`** (`lib/AmdSmiParser.php`). Normalisiert JSON-/SNMP-Extend-Ausgaben von `amd-smi` (Auslastung, VRAM, Leistungsaufnahme, Junction-/Edge-Temperatur, ECC, Partitionsmetadaten und getrennte Drosselungsakkumulatoren über `Reading::$vendorExtensions`) in das gemeinsame Metrikmodell unter `Vendor::Amd`.
- **Verfolgung von MIG-Instanzen und Schema v4** (`lib/GpuReport.php`, `lib/GpuSnapshot.php`, `lib/GpuRecord.php`, `lib/Schema.php`, `lib/ScriptServer.php`). Verfolgt MIG-Instanz-UUIDs (`MIG-<uuid>` und `MIG-GPU-<parent>/<gi>/<ci>`), `mig_mode`, `mig_instance_id`, `mig_profile`, `parent_uuid` und `partition_info` neben übergeordneten GPUs, während unveränderliche Identitätsschlüssel (`vendor`, `uuid`, `parent_uuid`) bei Aktualisierungen geschützt und vor Datenbankabfragen validiert werden.
- **Remote Data Collectors** (`INFO`, `poller_gpu.php`, `lib/Poller.php`, `lib/ReplicationRepository.php`, `setup.php`). Deklariert `remote_collect:1, remote_poller:1` in `INFO`, unterstützt Poller-gebundene Ausführung über `php plugins/gpu/poller_gpu.php --poller=N`, überspringt die Bereitstellung von Cacti-Objekten auf Nicht-Haupt-Pollern, repliziert Poller-gebundene Tabellen über `replicate_out` und beantwortet schreibgeschützte `gpu_health`- und `gpu_inventory`-Aktionen über `remote_agent`.
- **Betreiber- und CasC-CLI** (`cli/gpu.php`, `lib/CascProvider.php`). Läuft unter PHP 8.2+ (unter PHP 8.0 syntaktisch prüfbar) ohne externe Composer-Abhängigkeiten: `php plugins/gpu/cli/gpu.php export [--format=yaml|xml]`, `validate <file> [--format=yaml|xml]`, `plan <file> [--format=yaml|xml] [--output=text|json]`, `apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]` und `health [--format=text|json]`. Pläne berechnen einen deterministischen SHA-256-Bestätigungshash, `apply` bricht bei Abweichung des Hashs sicher ab und das XML-Parsing lehnt `DOCTYPE`- sowie `ENTITY`-Deklarationen ab.
- **Ökosystem-Integrations-Hooks** (`lib/EcosystemIntegration.php`, `setup.php`). Registriert `abilities_register` (`gpu-list-nodes`, `gpu-list-devices`, `gpu-get-health`; schreibgeschützt, abgesichert durch den Bereich `GPU View` und Cacti-Geräteberechtigungen), `doctor_register_checks` (`gpu_schema`, `gpu_poller_freshness`, `gpu_node_health`) und `casc_register_providers`.
- **Drosselungsgründe und ECC als Alarmwerte** ([docs/metrics.md](../../metrics.md)). Dekodiert Takt-Ereignismasken in 0/1-Indikatoren und berechnet neue korrigierbare sowie nicht korrigierbare ECC-Fehler pro Intervall als Gauges, sodass Thold 1.8.2 und Maint 1.3 ohne negative Zähler oder Reset-Spitzen alarmieren und unterdrücken ([docs/alerting.md](../../alerting.md)).
- **GPU Health, Berechtigungen, Fehler/Wiederherstellung und Skalierung.** GPU Health (GPU View) zeigt gespeicherte Aktualität, das Ergebnis der letzten Erfassung, den Maint-Status, das Backend, Versionen und Roh-Drosselungsmasken an, ohne Knoten zu kontaktieren. Skriptgestützte End-to-End-Tests verifizieren Berechtigungen, die Wiederherstellung nach Agent-/DB-/RRD-Ausfällen ([docs/backup.md](../../backup.md)) sowie einen 100-GPU-Skalierungslauf in der CI ([docs/performance.md](../../performance.md), [docs/operations.md](../../operations.md), [CHANGELOG.md](../../../CHANGELOG.md)).

## Neuerungen in Meilenstein 3

- **Erfassung über SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Der Administrator des Knotens fügt zwei feste `extend`-Zeilen zu `snmpd.conf` hinzu ([docs/node-setup.md](../../node-setup.md)); das Plugin liest die NET-SNMP-EXTEND-MIB über die SNMP-Funktionen von Cacti mit den eigenen Zugangsdaten des Geräts aus, wobei SNMPv3 empfohlen wird. Auf dem Cacti-Server oder dem Knoten wird nichts aktiv ausgeführt.
- **Strikte Auswertung.** Felder werden nach Position gelesen, jede Zeile benennt ihre GPU per UUID, und ein Exit-Status ungleich null, eine Zeitüberschreitung, abgeschnittene Ausgaben, eine Zeile mit falscher Feldanzahl oder zusammengeführte Fehlertexte lassen die gesamte Erfassung fehlschlagen: Werte werden zu `U`, keine GPU wird als abwesend markiert, und GPU Nodes zeigt die Ursache an.
- **Ein Collector nach jedem Poller-Zyklus.** Er liest den Agenten jedes aktivierten Knotens aus, aktualisiert die Identitätsdatensätze und legt den neuesten Messwert im Cache ab. Die Script-Server-Eingabe liest nur diesen Cache, sodass ein Cacti-Poll niemals auf einen GPU-Knoten wartet.
- **Cacti-Objekte** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Die Vorlagen und die Datenabfrage *GPU - Devices* werden als XML mitgeliefert und bei Installation und Upgrade importiert. Beim Speichern eines Knotens wird die Datenabfrage mit der Reindex-Methode *None* an das bestehende Gerät angehängt; GPU Nodes kann nur dann ein Gerät anlegen, wenn der Knoten noch keines hat. Jede GPU ist ein über Hersteller und UUID indizierter Eintrag mit einer einzigen Datenquelle, die von den Graphen Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages und Sample Age gemeinsam genutzt wird. Jedes Modellfeld wird von einem Graphen gezeichnet, da Cacti nur Felder speichert, die ein Graph verwendet. Das Plugin führt nach jeder GPU-Änderung ein Reindexing durch, merkt sich ein von Cacti übersprungenes Reindexing, solange das Gerät *Down* oder deaktiviert war, und führt es aus, sobald das Gerät wieder *Up* ist, und legt Graphen für neue GPUs an.
- **Die Historie bleibt bei der Karte.** Eine abwesende GPU bleibt als Index erhalten, sodass ihre Graphen eine Lücke zeigen und in derselben RRD-Datei fortgesetzt werden, sobald sie wieder verfügbar ist. Eine Ersatzkarte im selben Steckplatz erhält eine eigene Datenquelle. **Remove from Data Query** auf der Seite des Knotens entfernt eine abwesende GPU aus der Auflistung; ihre RRD-Dateien und Graphen bleiben erhalten, bis Sie sie löschen.
- **Geräteberechtigungen unter GPU Nodes.** Ein GPU Admin sieht, ändert, exportiert und verschiebt nur Knoten und GPUs auf Geräten, die er in Cacti sehen darf; das Verschieben einer GPU erfordert Berechtigungen für beide Knoten. Ein Knoten, dessen Gerät gelöscht wurde, kann deaktiviert oder gelöscht werden.
- **Das gemeinsame Metrikmodell** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Der Speicher wird in Bytes angegeben, die Takt-Ereignismaske wird in `thr_*`-Indikatoren dekodiert, und `age` ist das Alter des eigenen Zeitstempels der GPU oder `U`, falls unbekannt.

Der installierende (Webserver-)Benutzer muss in `<cacti>/resource/script_server/` schreiben dürfen, wo das Plugin seine Data-Query-XML ablegt; Installation und Upgrade brechen andernfalls mit einer Meldung ab und versuchen es beim nächsten Aufruf erneut. Bei der Deinstallation werden die Vorlagen und die XML-Datei nur entfernt, wenn *Remove the plugin's Cacti objects on uninstall* aktiviert ist; RRD-Dateien, Geräte, Graphen oder Datenquellen werden niemals gelöscht ([docs/operations.md](../../operations.md#uninstall)). Knoten auf Remote Data Collectors laufen gebunden an ihre zugewiesene `poller_id`.

## Lieferumfang in Meilenstein 2

- **Abgesicherte Installation.** Bei PHP unter 8.2 werden Installation und Aktivierung mit einer Meldung verweigert, die die erforderliche Version nennt, während der Rest von Cacti weiterläuft. `setup.php` und die Seitenskripte sind unter PHP 8.0 syntaktisch gültig.
- **Lebenszyklus.** Installation, Upgrade, Deaktivierung und Deinstallation über die Plugins-Seite von Cacti. Upgrades erfolgen geordnet, werden nach einer Unterbrechung fortgesetzt und aktualisieren die gespeicherte Version erst nach erfolgreichem Abschluss. Bei der Deinstallation bleiben die Tabellen des Plugins erhalten, sofern Sie nicht *Keep Plugin Tables on Uninstall* abwählen; ein Cacti-Gerät, eine Datenquelle, ein Graph oder eine RRD-Datei wird niemals angetastet.
- **Getrennte Berechtigungsbereiche.** *GPU Admin* öffnet **GPU Nodes**, wo Sie Cacti-Geräte als GPU-Knoten registrieren, GPU-Verschiebungen zwischen Knoten bestätigen oder ablehnen und jeden Datensatz als JSON exportieren können. *GPU View* öffnet **GPU Inventory**, das GPUs nur auf Geräten auflistet, die der Betrachter sehen darf. Beide Seiten befinden unter Management, und jede Änderung ist eine durch csrf-magic geprüfte POST-Anfrage.
- **GPU-Identitätsdatensätze.** Jede GPU wird über Hersteller und UUID identifiziert. Index, PCI-Bus-ID, Name, Treiber und das meldende Tool sind Attribute, die direkt am Datensatz aktualisiert werden. Eine neue UUID in einem bekannten Steckplatz wird als möglicher Austausch markiert und beginnt eine eigene Historie; eine an einem anderen Knoten erkannte GPU wird erst verschoben, wenn ein Operator dies bestätigt; eine GPU, die in erfolgreichen Erfassungen fehlt, wird nach einer konfigurierbaren Anzahl als abwesend markiert und niemals gelöscht. Vorerst werden nur NVIDIA-UUIDs ganzer GPUs akzeptiert; MIG-Instanzen warten auf Entscheidung #13 und AMD auf Recherche #19. Die Regeln werden durch Fixture-Sequenzen getestet, da sie noch von keinem Collector gespeist werden.
- **Tests und CI.** Unit- und Quellregel-Suites ohne GPU oder Datenbank, Lebenszyklus- und Seitenberechtigungsprüfungen gegen Cacti 1.2.31 sowie aktuelle 1.2.x-Versionen unter PHP 8.2 bis 8.5, ein Ablehnungstest unter PHP 8.1, ein PHP-8.0-Lint der Einstiegspunkte, PHPStan auf Level 9 sowie REUSE. Die Testumgebung ist vorläufig, bis #12 entschieden ist.

### Installation für Testzwecke

Diese Schritte installieren den aktuellen Code zum Ausprobieren; die vollständige Betreiberanleitung finden Sie in [docs/operations.md](../../operations.md). Erforderlich sind Cacti 1.2.31 oder neuer unter PHP 8.2 oder neuer.

1. Kopieren Sie das Repository nach `plugins/gpu` unterhalb Ihres Cacti-Verzeichnisses. Das Verzeichnis muss `gpu` heißen.
2. Öffnen Sie in Cacti **Configuration > Plugins** und installieren sowie aktivieren Sie anschließend **GPU**.
3. Weisen Sie Benutzern unter **Configuration > Users** entsprechend ihrer Rolle den Berechtigungsbereich *GPU View*, *GPU Operator* oder *GPU Admin* zu. Der installierende Benutzer erhält View und Admin; Admin schließt den Operator-Zugriff ein.
4. Richten Sie den SNMP-Agenten jedes GPU-Knotens wie in [docs/node-setup.md](../../node-setup.md) beschrieben ein und fügen Sie den Knoten anschließend unter **Management > GPU Nodes** hinzu.
5. Für Alarme importieren Sie die mitgelieferten Thold-Vorlagen wie in [docs/alerting.md](../../alerting.md) beschrieben.
6. Passen Sie optional **Configuration > Settings > GPU** an: *Mark Absent After* (Standard: 3 erfolgreiche Erfassungen, von 1 bis 100), *Stale After* (wie alt ein Messwert sein darf, bevor seine Werte `U` ausgeben) und *Keep Plugin Tables on Uninstall* (standardmäßig aktiviert).

## Wie Metriken in Cacti gelangen

Entscheidung [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) fiel zugunsten von SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): Der eigene Agent des Knotens führt einen festen `nvidia-smi`-Befehl aus, und das Plugin liest lediglich das Ergebnis aus. Der Projektplan vergleicht die Optionen unter [Erfassungspfade](../../PLAN.md#collection-paths).

## Kompatibilitätsziele

| Komponente | Ziel |
|---|---|
| Cacti | 1.2.31 oder neuer, ohne Core-Patches |
| PHP | 8.2 oder neuer; `setup.php` bleibt unter 8.0 parsebar, sodass eine nicht unterstützte Laufzeitumgebung eine Ablehnung statt einer defekten Cacti-Oberfläche erhält |
| NVIDIA | Treiber- und Tool-Linien werden durch Recherche [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) festgelegt; DCGM-Feld-IDs geprüft mit v3.3.9 und v4.7.0 |
| AMD | `amd-smi` auf ROCm-Releases, festzulegen durch Recherche [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 und Maint 1.3, unverändert, End-to-End getestet ([docs/alerting.md](../../alerting.md)) |

## Design

- [docs/PLAN.md](../../PLAN.md) ist der vollständige Plan: Architektur, zugrunde liegende Upstream-Fakten (mit Quellen), Identitätsmodell, Sicherheitsgrenzen, Leistungsberechnungen, Tests und Risiken.
- [docs/decisions/](../../decisions) enthält Architecture Decision Records für bereits getroffene Entscheidungen.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) ordnet jede Anforderungs-ID ihrem Issue zu.

```mermaid
flowchart LR
    NODE["GPU-Knoten: snmpd extend / DCGM Exporter / amd-smi"] -->|Schreibgeschütztes SNMPv3 oder erlaubtes HTTPS| C[Poller-gebundener Collector]
    C --> CACHE[(Plugin-Cache nach Hersteller + GPU/MIG-UUID indiziert)]
    CACHE --> SS[Script-Server-Datenabfrage-Eingabe]
    CACHE --> ECO["CasC-CLI, MCP-Fähigkeiten, Doctor-Prüfungen"]
    SS --> RRD[(Cacti-RRD-Dateien, eine Datenquelle pro GPU)]
    SS -->|poller_output| T[Thold und Maint]
    RRD --> G[Cacti-Graphen und -Berechtigungen]
```

## Nachverfolgung der Arbeit

- **Meilensteine** folgen den Meilensteinen des Plans. Jeder hat ein Epic-Issue, das seine Unter-Issues und Abschlussnachweise auflistet.
- **Issues** sind über Labels typisiert: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Anforderungs-Issues definieren nummerierte, testbare Anforderungen (`REQ-AREA-NNN`) und Akzeptanzkriterien.
- **Labels** tragen außerdem `area:`, `phase:`, `priority:` und `status:`. Siehe [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- Das [Projektboard](https://github.com/users/somethingwithproof/projects/9) zeigt alle Issues über alle Meilensteine hinweg.

## Mitwirken

Lesen Sie [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commits benötigen ein DCO-Sign-off. Design-Diskussionen finden in Issues statt; offene Entscheidungen tragen das Label `type: decision`. Aufgezeichnete Ausgaben echter GPU-Tools sind der nützlichste Beitrag zu Meilenstein 1; entfernen Sie vorher Hostnamen, Seriennummern und UUIDs.

## Sicherheit

Melden Sie Sicherheitslücken vertraulich; siehe [SECURITY.md](../../../SECURITY.md).

## Lizenz

GNU General Public License v2.0, dieselbe Lizenz wie Cacti und seine Plugins. Siehe [LICENSE](../../../LICENSE).
