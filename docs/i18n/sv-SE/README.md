<!-- Source: README.md; SHA256: 97ba37df4a883426d3984ed7d14547ad7367e973411a8abd930409ce259f545b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [Български](../bg-BG/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [Ελληνικά](../el-GR/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [ქართული](../ka-GE/README.md) | [한국어](../ko-KR/README.md) | [Latviešu](../lv-LV/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Português (Portugal)](../pt-PT/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Ett tillägg för Cacti 1.2 som hämtar användning, minne, temperatur, effekt, ECC-fel och orsaker till strypning per GPU från NVIDIA- och AMD-verktyg till Cacti. Varje GPU (eller MIG-instans) blir ett index i en datafråga på nodens enhet, så GPU-graferna visas bredvid nodens övriga grafer under Cactis behörighetsmodell, och Thold och Maint kan larma om och undertrycka dem.

> **Status: fas 1 till och med 3 implementerade i kod, ej utgiven ännu.** Fas 1 (NVIDIA `nvidia-smi` via SNMP extend, identitetsspårning, grafer, strypnings- och ECC-signaler, larm via Thold och Maint, hälsosida), fas 2 (skrivskyddad HTTP(S)-tolk/klient för NVIDIA DCGM Exporter samt tolk för AMD `amd-smi`), fas 3 (identitetsspårning för MIG-instanser, kolumner för Schema v4, poller-avgränsad körning av fjärrinsamlare, krokarna `replicate_out` och `remote_agent`), operatörs-CLI för CasC (`cli/gpu.php`) samt ekosystemkrokar (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) är implementerade och täcks av enhets-, säkerhets- och integrationstester mot tillfälliga Cacti-instanser. Körningar hittills använder syntetisk `snmpd` och testfixturer i stället för fysisk GPU-hårdvara. Kvarstår före en release-tagg: pilottester på riktig hårdvara ([docs/pilot-plan.md](../../pilot-plan.md), #54), en extern säkerhetsgranskning ([docs/security-review.md](../../security-review.md)), testfixturer insamlade från riktiga verktyg (#14) samt skalmätningar på hårdvara i produktionsstorlek ([docs/performance.md](../../performance.md)). Insamling av XID-fel förblir öppen under beslut #8.

## Åtkomstroller

| Behörighetsområde | Åtkomst |
| --- | --- |
| GPU View | Befintliga övervakningssidor. |
| GPU Operator | Övervakning samt sidan Operations och personliga kvitteringar vid triage. |
| GPU Admin | Operatörsåtkomst samt befintliga konfigurations- och administrationsåtgärder. |

När du har kopierat uppgraderingsfilerna öppnar du sidan Plugins som administratör för att registrera
det nya behörighetsområdet. Tilldela området GPU Operator uttryckligen under **Configuration > Users** eller användargrupper.
Uppgraderingar behåller befintliga behörighets-ID:n och tilldelningar för View och Admin. Admin-åtkomst
ärver Operator-åtkomst, och Operator-åtkomst ärver View-åtkomst vid varje begäran;
detta lägger inte till lagrade behörigheter för användare eller grupper. Tilläggets inställningar kräver även
GPU Admin-åtkomst utöver Cactis behörighet för Settings.

Cactis enhetsbehörigheter begränsar fortfarande vilka rader och åtgärder som är tillgängliga för varje användare; omappade objekt kräver en administratör.
Inloggningsuppgifter, konfiguration, mappning, borttagning och andra administrativa ändringar
finns kvar på administrationssidorna. Kvitteringar gäller endast i den aktuella
användarens session, i högst en timme och endast så länge samma observation förblir
färsk. Nya eller ändrade observationer gör dem ogiltiga. De markerar aldrig en misslyckad
mätning som frisk och undertrycker inte larm.

## Varför

GPU-noder finns vanligtvis redan i Cacti för övervakning av CPU, minne och nätverksgränssnitt. GPU-hälsa är en separat fråga som nodens SNMP-data inte besvarar: ett kort kan vara termiskt strypt, ackumulera ECC-fel eller rapportera ett XID-fel medan själva noden ser frisk ut. Tillverkarnas verktyg rapporterar detta (`nvidia-smi`, DCGM, `amd-smi`), men deras utdata hamnar utanför Cactis grafer, behörigheter och larmhantering.

Tillägget täpper till det gapet genom att omvandla utdata från tillverkarens verktyg till datakällor i Cacti, nycklade på varje GPU:s identitet i stället för dess position, så att en omstart som ändrar ordningen på GPU:erna inte kopplar ett korts historik till ett annat. Det återanvänder Cactis grafer, behörigheter, fjärrinsamlare, Thold och Maint i stället för att bygga parallella motsvarigheter.

## Planerad omfattning

| Fas | Omfattning | Status | Milstolpar |
|---|---|---|---|
| 0, förstudie | Utdata från tillverkarverktyg, XID- och strypningssignaler, GPU-identitet, behörigheter, beslut om insamlingsväg | Slutförd | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Insamling via `nvidia-smi`, inventering efter UUID, grafer, strypnings- och ECC-signaler, Thold och Maint, hälsosida | Implementerad (fixturtestad) | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, bakändar | Skrivskyddad HTTP(S)-tolk/klient för DCGM Exporter (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`), tolk för AMD `amd-smi` (`lib/AmdSmiParser.php`) | Implementerad (enhetstestad) | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig, fjärrinsamling och ekosystem | Identitetsspårning för MIG-instanser, fjärrinsamlare (`INFO`, `poller_gpu.php --poller=N`, `replicate_out`, `remote_agent`), CasC CLI (`cli/gpu.php`), MCP/Doctor/CasC-krokar | Implementerad (enhets- och CI-integrationstestad) | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Utanför omfattningen: alla kommandon som ändrar en GPU eller nod (klockfrekvenser, effektgränser, återställningar, MIG-layout), en egen larmmotor i tillägget, en anpassad daemon eller ett eget nätverksprotokoll samt schemaläggar- eller jobbredovisning. Se planen för förstudie-backloggen.

## Vad milstolpe 4 tillför

- **Skrivskyddad insamlare för NVIDIA DCGM Exporter** (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`). Tolkar Prometheus-mätvärden i textformat (`DCGM_FI_DEV_GPU_UTIL`, `DCGM_FI_DEV_FB_USED`, `DCGM_FI_DEV_FB_FREE`, `DCGM_FI_DEV_POWER_USAGE`, `DCGM_FI_DEV_GPU_TEMP`, `DCGM_FI_DEV_ECC_DBE_VOL_TOTAL`, `DCGM_FI_DEV_CLOCK_THROTTLE_REASONS`, samt MIG-etiketterna `GPU_I_ID` och `GPU_I_PROFILE`). HTTP(S)-klienten upprätthåller en explicit tillåtelselista för slutpunktsvärdar/CIDR, verifierar TLS-motpartscertifikat som standard, begränsar tidsgränser och svarsstorlek, inaktiverar omdirigeringar och avvisar scheman som inte är HTTP(S).
- **Skrivskyddad tolk för AMD `amd-smi`** (`lib/AmdSmiParser.php`). Normaliserar JSON/SNMP-extend-utdata från `amd-smi` (användning, VRAM, effekt, junction-/kanttemperatur, ECC, partitionsmetadata och separata strypningsackumulatorer via `Reading::$vendorExtensions`) till den gemensamma mätvärdesmodellen under `Vendor::Amd`.
- **Spårning av MIG-instanser och Schema v4** (`lib/GpuReport.php`, `lib/GpuSnapshot.php`, `lib/GpuRecord.php`, `lib/Schema.php`, `lib/ScriptServer.php`). Spårar UUID:n för MIG-instanser (`MIG-<uuid>` och `MIG-GPU-<parent>/<gi>/<ci>`), `mig_mode`, `mig_instance_id`, `mig_profile`, `parent_uuid` och `partition_info` vid sidan av överordnade GPU:er, samtidigt som oföränderliga identitetsnycklar (`vendor`, `uuid`, `parent_uuid`) skyddas vid uppdateringar och valideras före databasfrågor.
- **Fjärrinsamlare** (`INFO`, `poller_gpu.php`, `lib/Poller.php`, `lib/ReplicationRepository.php`, `setup.php`). Deklarerar `remote_collect:1, remote_poller:1` i `INFO`, stöder poller-avgränsad körning via `php plugins/gpu/poller_gpu.php --poller=N`, hoppar över skapande av Cacti-objekt på sekundära pollers, replikerar poller-avgränsade tabeller via `replicate_out` och besvarar skrivskyddade `gpu_health`- och `gpu_inventory`-anrop via `remote_agent`.
- **Operatörs- och CasC-CLI** (`cli/gpu.php`, `lib/CascProvider.php`). Körs på PHP 8.2+ (tolkbart på PHP 8.0) utan externa Composer-beroenden: `php plugins/gpu/cli/gpu.php export [--format=yaml|xml]`, `validate <file> [--format=yaml|xml]`, `plan <file> [--format=yaml|xml] [--output=text|json]`, `apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]` och `health [--format=text|json]`. Planer beräknar en deterministisk SHA-256-bekräftelsehash, apply avbryter säkert vid hash-avvikelse och XML-tolkning avvisar `DOCTYPE`- och `ENTITY`-deklarationer.
- **Ekosystem-integrationskrokar** (`lib/EcosystemIntegration.php`, `setup.php`). Registrerar `abilities_register` (`gpu-list-nodes`, `gpu-list-devices`, `gpu-get-health`; skrivskyddade, begränsade av behörighetsområdet `GPU View` och Cactis enhetsbehörigheter), `doctor_register_checks` (`gpu_schema`, `gpu_poller_freshness`, `gpu_node_health`) och `casc_register_providers`.
- **Strypningsorsaker och ECC som larmvärden** ([docs/metrics.md](../../metrics.md)). Avkodar klockhändelsemasker till 0/1-indikatorer och beräknar nya korrigerade/okorrigerade ECC-fel per intervall som mätarvärden (gauge) så att Thold 1.8.2 och Maint 1.3 larmar och undertrycker utan negativa räknare eller återställningstoppar ([docs/alerting.md](../../alerting.md)).
- **GPU Health, behörigheter, fel/återställning och skalning.** GPU Health (GPU View) visar lagrad aktualitet, utfall av senaste insamling, Maint-status, bakände, versioner och råa strypningsmasker utan att kontakta noder. Skriptade end-to-end-tester verifierar behörigheter, återställning vid agent-/DB-/RRD-fel ([docs/backup.md](../../backup.md)) samt en skalningskörning med 100 GPU:er i CI ([docs/performance.md](../../performance.md), [docs/operations.md](../../operations.md), [CHANGELOG.md](../../../CHANGELOG.md)).

## Vad milstolpe 3 tillför

- **Insamling via SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Nodens administratör lägger till två fasta `extend`-rader i `snmpd.conf` ([docs/node-setup.md](../../node-setup.md)); tillägget läser NET-SNMP-EXTEND-MIB via Cactis SNMP-funktioner med enhetens egna inloggningsuppgifter, där SNMPv3 rekommenderas. Det kör ingenting på Cacti-servern eller noden.
- **Strikt tolkning.** Fält läses efter position, varje rad identifierar sin GPU med UUID, och en slutkod skild från noll, en timeout, trunkerad utdata, en rad med fel antal fält eller sammanblandad feltext gör att hela insamlingen misslyckas: värden blir `U`, ingen GPU markeras som frånvarande och GPU Nodes visar orsaken.
- **En insamlare efter varje poller-cykel.** Den läser varje aktiverad nods agent, uppdaterar identitetsposterna och cachelagrar den senaste mätningen. Script server-indatan läser bara från den cachen, så en Cacti-pollning väntar aldrig på en GPU-nod.
- **Cacti-objekt** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Mallarna och datafrågan *GPU - Devices* levereras som XML och importeras vid installation och uppgradering. När en nod sparas kopplas datafrågan till dess befintliga enhet med omindexeringsmetoden None; GPU Nodes kan endast skapa en enhet när noden saknar en. Varje GPU är ett index nycklat på tillverkare och UUID, med en datakälla som delas av dess grafer för Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages och Sample Age. Varje modellfält ritas i någon graf, eftersom Cacti bara lagrar de fält som en graf använder. Tillägget omindexerar efter varje GPU-ändring, sparar en omindexering som Cacti hoppade över medan enheten var Down eller inaktiverad och kör den så snart enheten är Up, samt skapar grafer för nya GPU:er.
- **Historiken stannar hos kortet.** En frånvarande GPU förblir ett index, så dess grafer visar ett glapp och fortsätter i samma RRD-fil när den återkommer. Ett ersättningskort i samma kortplats får sin egen datakälla. **Remove from Data Query** på nodens sida tar bort en frånvarande GPU ur listan; dess RRD-filer och grafer behålls tills du tar bort dem.
- **Enhetsbehörigheter på GPU Nodes.** En GPU Admin ser, ändrar, exporterar och flyttar endast noder och GPU:er på enheter som användaren får se i Cacti; en GPU-flytt kräver åtkomst till båda noderna. En nod vars enhet har tagits bort kan inaktiveras eller raderas.
- **Den gemensamma mätvärdesmodellen** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Minne anges i byte, klockhändelsemasken avkodas till `thr_*`-indikatorer och `age` är åldern på GPU:ns egen mättidpunkt, eller `U` om den är okänd.

Den användare som utför installationen (webbservern) måste kunna skriva till `<cacti>/resource/script_server/`, där tillägget placerar sin XML-fil för datafrågan; annars avbryts installationen eller uppgraderingen med ett meddelande och försöker igen vid nästa försök. Avinstallation tar endast bort mallarna och XML-filen om *Remove the plugin's Cacti objects on uninstall* är markerad, och tar aldrig bort RRD-filer, enheter, grafer eller datakällor ([docs/operations.md](../../operations.md#uninstall)). Noder på fjärrinsamlare körs avgränsade till sitt tilldelade `poller_id`.

## Vad milstolpe 2 levererar

- **Skyddad installation.** På PHP lägre än 8.2 nekas installation och aktivering med ett meddelande som anger den version som krävs, medan resten av Cacti fortsätter att fungera. `setup.php` och sidskripten kan tolkas på PHP 8.0.
- **Livscykel.** Installation, uppgradering, inaktivering och avinstallation sker via Cactis Plugins-sida. Uppgraderingar körs i ordning, slutförs efter ett avbrott och uppdaterar den registrerade versionen först efter att de lyckats. Avinstallation behåller tilläggets tabeller om du inte avmarkerar *Keep Plugin Tables on Uninstall*; den rör aldrig någon Cacti-enhet, datakälla, graf eller RRD-fil.
- **Separata behörighetsområden.** *GPU Admin* öppnar **GPU Nodes**, där du registrerar Cacti-enheter som GPU-noder, bekräftar eller avvisar GPU-flyttar mellan noder och exporterar alla poster som JSON. *GPU View* öppnar **GPU Inventory**, som endast listar GPU:er på enheter som användaren får se. Båda sidorna ligger under Management, och varje ändring är en POST-begäran som kontrolleras med csrf-magic.
- **Identitetsposter för GPU.** Varje GPU är nycklad på tillverkare och UUID. Index, PCI-buss-ID, namn, drivrutin och rapporterande verktyg är attribut som ändras på plats. Ett nytt UUID i en känd kortplats flaggas som ett möjligt utbyte och påbörjar sin egen historik; en GPU som upptäcks på en annan nod flyttas först när en operatör bekräftar det; en GPU som saknas i lyckade insamlingar markeras som frånvarande efter ett konfigurerbart antal och tas aldrig bort. Endast UUID:n för hela NVIDIA-GPU:er accepteras tills vidare; MIG-instanser inväntar beslut #13 och AMD inväntar förstudie #19. Reglerna testas med fixtursekvenser, eftersom ingen insamlare matar dem ännu.
- **Tester och CI.** Enhets- och källregelsviter utan GPU eller databas, livscykel- och sidbehörighetskontroller mot Cacti 1.2.31 och aktuell 1.2.x på PHP 8.2 till 8.5, ett jobb för avvisande av PHP 8.1, en PHP 8.0-lintning av startpunkterna, PHPStan på nivå 9 och REUSE. Testramverket är provisoriskt tills #12 har avgjorts.

### Installation för utvärdering

Dessa steg installerar den aktuella koden för utvärdering; [docs/operations.md](../../operations.md) innehåller den fullständiga operatörsguiden. De kräver Cacti 1.2.31 eller senare på PHP 8.2 eller senare.

1. Kopiera arkivet till `plugins/gpu` under din Cacti-katalog. Katalogen måste heta `gpu`.
2. Öppna **Configuration > Plugins** i Cacti, och installera och aktivera sedan **GPU**.
3. Tilldela användare behörighetsområdet *GPU View*, *GPU Operator* eller *GPU Admin* under **Configuration > Users**, beroende på deras roll. Användaren som installerar får View och Admin; Admin inkluderar Operator-åtkomst.
4. Konfigurera varje GPU-nods SNMP-agent enligt beskrivningen i [docs/node-setup.md](../../node-setup.md), och lägg sedan till noden under **Management > GPU Nodes**.
5. För larm importerar du de medföljande Thold-mallarna enligt beskrivningen i [docs/alerting.md](../../alerting.md).
6. Justera vid behov **Configuration > Settings > GPU**: *Mark Absent After* (standard 3 lyckade insamlingar, från 1 till 100), *Stale After* (hur gammal en mätning får vara innan dess värden skriver ut `U`) och *Keep Plugin Tables on Uninstall* (på som standard).

## Hur mätvärden når Cacti

Beslut [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) valde SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): nodens egen agent kör ett fast `nvidia-smi`-kommando och tillägget läser bara resultatet. Planen jämför alternativen under [Insamlingsvägar](../../PLAN.md#collection-paths).

## Kompatibilitetsmål

| Komponent | Mål |
|---|---|
| Cacti | 1.2.31 eller senare, utan ändringar i kärnan |
| PHP | 8.2 eller senare; `setup.php` förblir tolkbar på 8.0 så att en körmiljö som inte stöds får ett avvisande i stället för ett trasigt Cacti-gränssnitt |
| NVIDIA | Drivrutins- och verktygsversioner fastställs av förstudie [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26); DCGM-fält-ID:n kontrollerade mot v3.3.9 och v4.7.0 |
| AMD | `amd-smi` på ROCm-versioner fastställs av förstudie [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 och Maint 1.3, oförändrade, testade end-to-end ([docs/alerting.md](../../alerting.md)) |

## Design

- [docs/PLAN.md](../../PLAN.md) är den fullständiga planen: arkitektur, uppströmsfakta som den bygger på (med källor), identitetsmodell, säkerhetsgränser, prestandaberäkningar, testning och risker.
- [docs/decisions/](../../decisions) innehåller arkitekturbeslut (ADR) för val som redan har gjorts.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) mappar varje krav-ID till motsvarande ärende.

```mermaid
flowchart LR
    NODE["GPU-nod: snmpd extend / DCGM Exporter / amd-smi"] -->|Skrivskyddad SNMPv3 eller HTTPS på tillåtelselista| C[Poller-avgränsad insamlare]
    C --> CACHE[(Tilläggscache nycklad på tillverkare + GPU/MIG-UUID)]
    CACHE --> SS[Indata för script server-datafråga]
    CACHE --> ECO[CasC CLI, MCP-funktioner, Doctor-kontroller]
    SS --> RRD[(Cacti RRD-filer, en datakälla per GPU)]
    SS -->|poller_output| T[Thold och Maint]
    RRD --> G[Cacti-grafer och behörigheter]
```

## Hur arbetet följs upp

- **Milstolpar** följer planens milstolpar. Var och en har ett övergripande ärende som listar dess delärenden och slutbevis.
- **Ärenden** typas med etiketter: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Kravärenden anger numrerade, testbara krav (`REQ-AREA-NNN`) och acceptanskriterier.
- **Etiketter** innehåller även `area:`, `phase:`, `priority:` och `status:`. Se [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Projekttavlan](https://github.com/users/somethingwithproof/projects/9) visar alla ärenden över milstolparna.

## Bidra

Läs [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commits kräver en DCO-signatur. Designdiskussioner sker i ärenden; öppna beslut har etiketten `type: decision`. Insamlad utdata från riktiga GPU-verktyg är det mest värdefulla bidraget till milstolpe 1; rensa bort värdnamn, serienummer och UUID:n först.

## Säkerhet

Rapportera sårbarheter privat; se [SECURITY.md](../../../SECURITY.md).

## Licens

GNU General Public License v2.0, samma licens som Cacti och dess tillägg. Se [LICENSE](../../../LICENSE).
