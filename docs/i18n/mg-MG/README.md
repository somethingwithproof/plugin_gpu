<!-- Source: README.md; SHA256: 97ba37df4a883426d3984ed7d14547ad7367e973411a8abd930409ce259f545b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Plugin ho an'ny Cacti 1.2 izay mitondra ao amin'ny Cacti ny fampiasana, ny fitadidiana, ny maripana, ny hery, ny hadisoana ECC, ary ny anton'ny fampihenana hafainganam-pandeha isaky ny GPU avy amin'ny fitaovana NVIDIA sy AMD. Ny GPU tsirairay (na santionan'ny MIG) dia lasa tondro iray amin'ny fanontaniana angona ao amin'ny fitaovan'ny vondronany, ka ny grafikan'ny GPU dia mipetraka eo anilan'ny grafika hafa an'ny vondrona eo ambanin'ny fahazoan-dalana Cacti, ary afaka mandefa fampandrenesana sy manafina azy ireny ny Thold sy ny Maint.

> **Sata: ny dingana 1 ka hatramin'ny 3 dia efa voapetraka ao amin'ny kaody, tsy mbola navoaka.** Ny dingana 1 (NVIDIA `nvidia-smi` amin'ny alalan'ny SNMP extend, fanaraha-maso famantarana, grafika, famantarana ny fampihenana hafainganam-pandeha sy ECC, fampandrenesana Thold sy Maint, pejin'ny fahasalamana), ny dingana 2 (mpamaky/mpanjifa HTTP(S) vakiana fotsiny ho an'ny NVIDIA DCGM Exporter sy mpamaky `amd-smi` ho an'ny AMD), ny dingana 3 (fanaraha-maso famantarana ny santionan'ny MIG, tsanganana Schema v4, fandefasana mpanangona lavitra voafetra amin'ny poller, hooks `replicate_out` sy `remote_agent`), ny CLI mpandraharaha CasC (`cli/gpu.php`), ary ny hooks fampidirana amin'ny tontolo iainana (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) dia efa voapetraka ary voasava amin'ny fitsapana singa, fitsapana fiarovana, ary fitsapana fampidirana Cacti azo ariana. Ny fandefasana mivantana hatreto dia mampiasa `snmpd` noforonina sy angona santionany fa tsy fitaovana GPU ara-batana. Mbola misokatra alohan'ny fanamarihana famoahana: andrana eny amin'ny fitaovana tena izy ([docs/pilot-plan.md](../../pilot-plan.md), #54), famerenana ara-piarovana avy any ivelany ([docs/security-review.md](../../security-review.md)), santionan-angona nalaina avy amin'ny fitaovana tena izy (#14), ary fandrefesana ny fahafaha-miitatra amin'ny fitaovana mitovy habe amin'ny famokarana ([docs/performance.md](../../performance.md)). Mbola misokatra eo ambanin'ny fanapahan-kevitra #8 ny fanangonana hadisoana XID.

## Anjara asan'ny fidirana

| Fari-piadidiana | Fidirana |
| --- | --- |
| GPU View | Pejy fanaraha-maso efa misy. |
| GPU Operator | Fanaraha-maso miampy ny pejy Asa sy ny fankatoavana fanasokajiana manokana. |
| GPU Admin | Fidiran'ny Operator miampy ny fikirakirana efa misy sy ny hetsika fitantanana. |

Rehefa avy mandika ny rakitra fanavaozana, sokafy amin'ny maha-mpitantana ny pejy Plugins mba hisoratra anarana ny fari-piadidiana vaovao. Omeo mazava eo ambanin'ny **Configuration > Users** na vondrona mpampiasa ny fari-piadidian'ny Operator.
Ny fanavaozana dia mitazona ny laharan'ny fari-piadidiana sy ny fahazoan-dalana View sy Admin efa misy. Ny fidiran'ny Admin dia mandova ny fidiran'ny Operator, ary ny fidiran'ny Operator dia mandova ny fidiran'ny View isaky ny fangatahana; tsy manampy fahazoan-dalana voatahiry ho an'ny mpampiasa na vondrona izany. Ny fikirakirana ny plugin dia mitaky ihany koa ny fidirana GPU Admin ho fanampin'ny fahazoan-dalana Settings an'ny Cacti.

Ny fahazoan-dalana fitaovana Cacti dia mbola mametra ny andalana sy ny hetsika azon'ny mpampiasa tsirairay ampiasaina; ny zavatra tsy voasoratra an-tsarintany dia mitaky mpitantana.
Ny mombamomba ny fidirana, ny fikirakirana, ny fampifandraisana, ny famafana ary ny fanovana fitantanana hafa dia mijanona ao amin'ny pejy fitantanana. Ny fankatoavana dia tsy maharitra afa-tsy ao amin'ny fotoam-pivorian'ny mpampiasa ankehitriny, mandritra ny adiny iray raha be indrindra ary raha mbola vaovao hatrany izany fandinihana izany ihany. Ny fandinihana vaovao na niova dia manafoana azy ireo. Tsy manamarika fandrefesana tsy nahomby ho salama na manafina fampandrenesana mihitsy izy ireo.

## Nahoana

Ny vondrona GPU dia matetika efa ao amin'ny Cacti ho an'ny CPU, ny fitadidiana, ary ny fampifandraisana. Fanontaniana hafa ny fahasalaman'ny GPU izay tsy voavalin'ny angona SNMP an'ny vondrona: mety ho voafetra noho ny hafanana ny karatra iray, hanangona hadisoana ECC, na hitatitra hadisoana XID raha toa ka hita ho salama ny vondrona. Ny fitaovan'ny mpamokatra no mitatitra izany (`nvidia-smi`, DCGM, `amd-smi`), saingy ny vokatra avoakany dia mitoetra ivelan'ny grafika, ny fahazoan-dalana, ary ny fampandrenesana an'ny Cacti.

Manakatona izany banga izany ny plugin amin'ny alalan'ny fanovana ny vokatry ny fitaovan'ny mpamokatra ho loharanon'angona Cacti, izay tondroina amin'ny alalan'ny famantarana ny GPU tsirairay fa tsy ny toerany, ka ny famerenana mandefa izay manova ny filaharan'ny GPU dia tsy mampifandray ny tantaran'ny karatra iray amin'ny karatra hafa. Mampiasa indray ny grafika, ny fahazoan-dalana, ny mpanangona angona lavitra, ny Thold, ary ny Maint an'ny Cacti izy fa tsy manangana dika mitovy mifanitsy amin'izany.

## Sehatra voalahatra

| Dingana | Sehatra | Sata | Dingana lehibe |
|---|---|---|---|
| 0, fikarohana | Vokatry ny fitaovan'ny mpamokatra, famantarana XID sy ny fampihenana hafainganam-pandeha, famantarana GPU, tombontsoa manokana, fanapahan-kevitra momba ny lalan'ny fanangonana | Vita | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Fanangonana `nvidia-smi`, lisitra araka ny UUID, grafika, famantarana fampihenana hafainganam-pandeha sy ECC, Thold sy Maint, pejin'ny fahasalamana | Voapetraka (voazaha tamin'ny santionan-angona) | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, backends | Mpamaky/mpanjifa HTTP(S) vakiana fotsiny ho an'ny DCGM Exporter (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`), mpamaky `amd-smi` ho an'ny AMD (`lib/AmdSmiParser.php`) | Voapetraka (voazaha tamin'ny fitsapana singa) | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig, lavitra, ary tontolo iainana | Fanaraha-maso famantarana ny santionan'ny MIG, mpanangona angona lavitra (`INFO`, `poller_gpu.php --poller=N`, `replicate_out`, `remote_agent`), CasC CLI (`cli/gpu.php`), hooks MCP/Doctor/CasC | Voapetraka (voazaha tamin'ny fitsapana singa sy fampidirana CI) | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Ivelan'ny sehatra: izay baiko manova GPU na vondrona (famantaranandro, fetran'ny hery, famerenana amin'ny laoniny, fandrindrana MIG), motera fampandrenesana an'ny plugin manokana, daemon na protokolon'ny tambajotra manokana, ary kaonty mpandrindra fotoana na asa. Jereo ny drafitra ho an'ny lisitry ny fikarohana miandry.

## Zavatra ampian'ny dingana lehibe 4

- **Mpanangona vakiana fotsiny ho an'ny NVIDIA DCGM Exporter** (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`). Mamaky ny metrika lahatsoratra Prometheus (`DCGM_FI_DEV_GPU_UTIL`, `DCGM_FI_DEV_FB_USED`, `DCGM_FI_DEV_FB_FREE`, `DCGM_FI_DEV_POWER_USAGE`, `DCGM_FI_DEV_GPU_TEMP`, `DCGM_FI_DEV_ECC_DBE_VOL_TOTAL`, `DCGM_FI_DEV_CLOCK_THROTTLE_REASONS`, ary ny marika MIG `GPU_I_ID` sy `GPU_I_PROFILE`). Ny mpanjifa HTTP(S) dia mampihatra lisitra mazava momba ny mpampiantrano/CIDR nahazo alalana, manamarina ny taratasy fanamarinana TLS amin'ny ankapobeny, mametra ny fe-potoana sy ny haben'ny valiny, manafoana ny fihodinana (redirects), ary mandà ny rafitra tsy HTTP(S).
- **Mpamaky vakiana fotsiny `amd-smi` ho an'ny AMD** (`lib/AmdSmiParser.php`). Manara-penitra ny vokatra JSON/SNMP-extend avy amin'ny `amd-smi` (fampiasana, VRAM, hery, maripana junction/edge, ECC, metadata fizarana, ary mpanangona fampihenana hafainganam-pandeha misaraka amin'ny alalan'ny `Reading::$vendorExtensions`) ho ao amin'ny modely fandrefesana iraisana eo ambanin'ny `Vendor::Amd`.
- **Fanaraha-maso ny santionan'ny MIG sy Schema v4** (`lib/GpuReport.php`, `lib/GpuSnapshot.php`, `lib/GpuRecord.php`, `lib/Schema.php`, `lib/ScriptServer.php`). Manara-maso ny UUID santionan'ny MIG (`MIG-<uuid>` sy `MIG-GPU-<parent>/<gi>/<ci>`), `mig_mode`, `mig_instance_id`, `mig_profile`, `parent_uuid`, ary `partition_info` miaraka amin'ny GPU ray aman-dreny sady mitazona ny fanalahidy famantarana tsy miova (`vendor`, `uuid`, `parent_uuid`) ho voaaro mandritra ny fanavaozana sy voamarina alohan'ny fangatahana angon-drakitra.
- **Mpanangona angona lavitra** (`INFO`, `poller_gpu.php`, `lib/Poller.php`, `lib/ReplicationRepository.php`, `setup.php`). Manambara `remote_collect:1, remote_poller:1` ao amin'ny `INFO`, manohana ny fandefasana voafetra amin'ny poller amin'ny alalan'ny `php plugins/gpu/poller_gpu.php --poller=N`, mandingana ny famoronana zavatra Cacti amin'ny poller tsy fototra, mandika ny latabatra voafetra amin'ny poller amin'ny alalan'ny `replicate_out`, ary mamaly ny hetsika vakiana fotsiny `gpu_health` sy `gpu_inventory` amin'ny alalan'ny `remote_agent`.
- **CLI ho an'ny mpandraharaha sy CasC** (`cli/gpu.php`, `lib/CascProvider.php`). Mandeha amin'ny PHP 8.2+ (azo vakiana amin'ny PHP 8.0) tsy misy fiankinan-doha Composer ivelany: `php plugins/gpu/cli/gpu.php export [--format=yaml|xml]`, `validate <file> [--format=yaml|xml]`, `plan <file> [--format=yaml|xml] [--output=text|json]`, `apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]`, ary `health [--format=text|json]`. Ny drafitra dia mikajy hash fanamafisana SHA-256 tsy miovaova, ny fampiharana dia mijanona raha tsy mitovy ny hash, ary ny famakiana XML dia mandà ny fanambarana `DOCTYPE` sy `ENTITY`.
- **Hooks fampidirana amin'ny tontolo iainana** (`lib/EcosystemIntegration.php`, `setup.php`). Misoratra ny `abilities_register` (`gpu-list-nodes`, `gpu-list-devices`, `gpu-get-health`; vakiana fotsiny, voafetra amin'ny fari-piadidiana `GPU View` sy ny fahazoan-dalana fitaovana Cacti), `doctor_register_checks` (`gpu_schema`, `gpu_poller_freshness`, `gpu_node_health`), ary `casc_register_providers`.
- **Anton'ny fampihenana hafainganam-pandeha sy ECC ho sandam-pampandrenesana** ([docs/metrics.md](../../metrics.md)). Mandika ny saron'ny hetsika famantaranandro ho tondro 0/1 ary mikajy ny hadisoana ECC vaovao voahitsy/tsy voahitsy isaky ny elanelam-potoana ho marika fandrefesana mba handefasan'ny Thold 1.8.2 sy ny Maint 1.3 fampandrenesana sy hanafenany azy ireny tsy misy isa miiba na fiakarana tampoka amin'ny famerenana amin'ny laoniny ([docs/alerting.md](../../alerting.md)).
- **GPU Health, fahazoan-dalana, tsy fahombiazana/famerenana amin'ny laoniny, ary fahafaha-miitatra.** Ny GPU Health (GPU View) dia mampiseho ny havaozana voatahiry, ny vokatra faran'ny fanangonana, ny toetry ny Maint, ny backend, ny dikan-teny, ary ny saron-javatra manta tsy mifandray amin'ny vondrona. Ny fitsapana manomboka hatramin'ny farany voasoratra dia manamarina ny fahazoan-dalana, ny fanarenana amin'ny tsy fahombiazan'ny masoivoho/DB/RRD ([docs/backup.md](../../backup.md)), ary ny fandefasana amin'ny ambaratonga GPU 100 ao amin'ny CI ([docs/performance.md](../../performance.md), [docs/operations.md](../../operations.md), [CHANGELOG.md](../../../CHANGELOG.md)).

## Zavatra ampian'ny dingana lehibe 3

- **Fanangonana amin'ny alalan'ny SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Ny mpitantana ny vondrona dia manampy andalana `extend` roa raikitra ao amin'ny `snmpd.conf` ([docs/node-setup.md](../../node-setup.md)); ny plugin dia mamaky ny NET-SNMP-EXTEND-MIB amin'ny alalan'ny asan'ny SNMP an'ny Cacti miaraka amin'ny mombamomba ny fidirana an'ilay fitaovana, SNMPv3 no soso-kevitra. Tsy mandefa na inona na inona ao amin'ny lohamilina Cacti na ao amin'ny vondrona izy.
- **Famakiana hentitra.** Ny saha dia vakiana araka ny toerany, ny andalana tsirairay dia manonona ny GPU-ny amin'ny alalan'ny UUID, ary ny sata fivoahana tsy zero, ny fahataperan'ny fotoana, ny vokatra tapaka, ny andalana manana isan'ny saha diso, na ny lahatsoratra hadisoana mitambatra dia mahatonga ny fanangonana manontolo ho tsy mahomby: lasa `U` ny sanda, tsy misy GPU voamarika ho tsy eo, ary ny GPU Nodes no mampiseho ny antony.
- **Mpanangona iray aorian'ny tsingerin'ny poller tsirairay.** Mamaky ny masoivohon'ny vondrona mandeha tsirairay izy, manavao ny rakitra famantarana, ary mitahiry ao amin'ny cache ny santionany farany. Ny fampidirana script server dia tsy mamaky afa-tsy izany cache izany, ka tsy miandry vondrona GPU mihitsy ny fanadihadian'ny Cacti.
- **Zavatra Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Ny modely sy ny fanontaniana angona *GPU - Devices* dia alefa ho XML ary ampidirina mandritra ny fametrahana sy ny fanavaozana. Ny fitehirizana vondrona iray dia mampifandray ny fanontaniana angona amin'ny fitaovany efa misy miaraka amin'ny fomba fanavaozana tondro None; ny GPU Nodes dia tsy afaka mamorona fitaovana raha tsy rehefa tsy manana ny vondrona. Ny GPU tsirairay dia tondro voasokajy amin'ny mpamokatra sy ny UUID, miaraka amin'ny loharanon'angona iray iombonan'ny grafika Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages, ary Sample Age. Ny saha tsirairay ao amin'ny modely dia soritan'ny grafika iray, satria ny saha ampiasain'ny grafika ihany no tehirizin'ny Cacti. Ny plugin dia manavao ny tondro isaky ny fiovan'ny GPU, mitazona ny fanavaozana tondro nolalovan'ny Cacti raha mbola Down na tsy mandeha ny fitaovana ary mandefa izany raha vantany vao Up ny fitaovana, ary mamorona grafika ho an'ny GPU vaovao.
- **Mijanona miaraka amin'ny karatra ny tantara.** Ny GPU tsy eo dia mijanona ho tondro, ka ny grafikany dia mampiseho banga ary mitohy ao amin'ny rakitra RRD mitovy rehefa miverina izy. Ny karatra fanoloana eo amin'ny toerana mitovy dia mahazo ny loharanon'angonany manokana. Ny **Remove from Data Query** ao amin'ny pejin'ny vondrona dia manala GPU tsy eo amin'ny lisitra; tehirizina hatrany ny rakitra RRD sy ny grafikany mandra-pamafanao azy.
- **Fahazoan-dalana fitaovana ao amin'ny GPU Nodes.** Ny GPU Admin dia tsy mahita, manova, manondrana, ary mamindra afa-tsy ny vondrona sy ny GPU eo amin'ny fitaovana azony jerena ao amin'ny Cacti; ny famindrana GPU dia mitaky ny vondrona roa. Azo atsangana tsy handeha na fafana ny vondrona izay voafafa ny fitaovany.
- **Ny modely fandrefesana iraisana** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Amin'ny bytes ny fitadidiana, ny saron'ny hetsika famantaranandro dia adika ho tondro `thr_*`, ary ny `age` dia ny taonan'ny fotoanan'ny santionan'ny GPU manokana, na `U` rehefa tsy fantatra.

Ny mpampiasa mametraka (lohamilina tranonkala) dia tsy maintsy afaka manoratra ao amin'ny `<cacti>/resource/script_server/`, izay hametrahan'ny plugin ny XML fanontaniana angonany; raha tsy izany dia mijanona miaraka amin'ny hafatra ny fametrahana sy ny fanavaozana ary manandrana indray amin'ny andrana manaraka. Ny fanesorana dia tsy manala ny modely sy ny XML raha tsy rehefa voamarika ny *Remove the plugin's Cacti objects on uninstall*, ary tsy mamafa rakitra RRD, fitaovana, grafika, na loharanon'angona mihitsy ([docs/operations.md](../../operations.md#uninstall)). Ny vondrona eo amin'ny mpanangona angona lavitra dia mandeha voafetra amin'ny `poller_id` voatendry ho azy.

## Zavatra atolotry ny dingana lehibe 2

- **Fametrahana voaaro.** Amin'ny PHP ambanin'ny 8.2, dia lavina ny fametrahana sy ny fampandehanana miaraka amin'ny hafatra manonona ny dikan-teny takiana, ary mitohy miasa ny ambiny amin'ny Cacti. Ny `setup.php` sy ny script an'ny pejy dia azo vakiana amin'ny PHP 8.0.
- **Tsingerim-piainana.** Fametrahana, fanavaozana, fampitsaharana, ary fanesorana amin'ny alalan'ny pejy Plugins an'ny Cacti. Milamina ny filaharan'ny fanavaozana, vita aorian'ny fahatapahana, ary tsy manova ny dikan-teny voarakitra raha tsy rehefa mahomby izy ireo. Ny fanesorana dia mitazona ny latabatry ny plugin raha tsy esorinao ny *Keep Plugin Tables on Uninstall*; tsy mikasika fitaovana Cacti, loharanon'angona, grafika, na rakitra RRD mihitsy izy.
- **Fari-piadidiana misaraka.** Ny *GPU Admin* dia manokatra ny **GPU Nodes**, izay ahafahanao misoratra fitaovana Cacti ho vondrona GPU, manamafy na mandà ny fifindran'ny GPU eo anelanelan'ny vondrona, ary manondrana ny rakitra rehetra ho JSON. Ny *GPU View* dia manokatra ny **GPU Inventory**, izay mitanisa ny GPU eo amin'ny fitaovana azon'ny mpijery jerena ihany. Eo ambanin'ny Management avokoa ny pejy roa, ary POST voamarin'ny csrf-magic avokoa ny fanovana rehetra.
- **Rakitra famantarana ny GPU.** Ny GPU tsirairay dia tondroina amin'ny mpamokatra sy ny UUID. Ny tondro, ny laharan'ny bus PCI, ny anarana, ny mpamily, ary ny fitaovana mitatitra dia toetra miova eo amin'ny toerany. Ny UUID vaovao ao amin'ny toerana efa fantatra dia marihina ho mety ho fanoloana ary manomboka ny tantarany manokana; ny GPU hita eo amin'ny vondrona hafa dia tsy mifindra raha tsy rehefa manamafy ny mpandraharaha; ny GPU tsy hita ao amin'ny fanangonana mahomby dia marihina ho tsy eo aorian'ny isa azo ovaina ary tsy fafana mihitsy. Ny UUID GPU manontolo NVIDIA ihany no ekena amin'izao fotoana izao; miandry ny fanapahan-kevitra #13 ny santionan'ny MIG, ary ny fikarohana #19 ny AMD. Ny fitsipika dia andramana amin'ny filaharana santionan-angona, satria mbola tsy misy mpanangona mamahana azy.
- **Fitsapana sy CI.** Andiam-pitsapana singa sy fitsipiky ny loharano tsy misy GPU na angon-drakitra, fanamarinana ny tsingerim-piainana sy ny fahazoan-dalana pejy amin'ny Cacti 1.2.31 sy ny 1.2.x ankehitriny amin'ny PHP 8.2 hatramin'ny 8.5, asa fandavana PHP 8.1, fanamarinana syntax PHP 8.0 ho an'ny teboka fidirana, PHPStan amin'ny ambaratonga 9, ary REUSE. Vonjimaika ny rafitra fitsapana mandra-panapaka ny #12.

### Fametrahana ho an'ny fanombanana

Ireo dingana ireo dia mametraka ny kaody ankehitriny mba hanandramana azy; ny [docs/operations.md](../../operations.md) no mirakitra ny torolalana feno ho an'ny mpandraharaha. Mitaky Cacti 1.2.31 na vaovao kokoa amin'ny PHP 8.2 na vaovao kokoa izy ireo.

1. Adikao ao amin'ny `plugins/gpu` eo ambanin'ny lahatahiry Cacti-nao ny tahiry. Tsy maintsy atao hoe `gpu` ny anaran'ny lahatahiry.
2. Ao amin'ny Cacti, sokafy ny **Configuration > Plugins**, avy eo apetraho ary alefaso ny **GPU**.
3. Omeo ny mpampiasa ny fari-piadidiana *GPU View*, *GPU Operator*, na *GPU Admin* eo ambanin'ny **Configuration > Users**, arakaraka ny anjara asany. Ny mpampiasa mametraka dia mahazo View sy Admin; ny Admin dia ahitana ny fidiran'ny Operator.
4. Amboary ny masoivoho SNMP an'ny vondrona GPU tsirairay araka ny voalaza ao amin'ny [docs/node-setup.md](../../node-setup.md), avy eo ampio eo ambanin'ny **Management > GPU Nodes** ilay vondrona.
5. Ho an'ny fampandrenesana, ampidiro ny modely Thold natolotra araka ny voalazan'ny [docs/alerting.md](../../alerting.md).
6. Raha tiana dia amboary ny **Configuration > Settings > GPU**: *Mark Absent After* (3 fanangonana mahomby araka ny toerana voalohany, manomboka amin'ny 1 ka hatramin'ny 100), *Stale After* (ny faharetan'ny santionany alohan'ny hamoahan'ny sandany `U`), ary ny *Keep Plugin Tables on Uninstall* (mandeha araka ny toerana voalohany).

## Fomba hahatongavan'ny fandrefesana ao amin'ny Cacti

Ny fanapahan-kevitra [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) dia nifidy ny SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): ny masoivohon'ny vondrona manokana no mandefa baiko `nvidia-smi` raikitra ary ny vokatra ihany no vakian'ny plugin. Mampitaha ireo safidy ny drafitra eo ambanin'ny [Lalan'ny fanangonana](../../PLAN.md#collection-paths).

## Tanjon'ny fifanarahana

| Singa | Tanjona |
|---|---|
| Cacti | 1.2.31 na vaovao kokoa, tsy misy fanovana fototra |
| PHP | 8.2 na vaovao kokoa; ny `setup.php` dia mijanona ho azo vakiana amin'ny 8.0 mba hahazoana fandavana ny tontolo fandefasana tsy tohanana fa tsy Cacti UI simba |
| NVIDIA | Ny andian'ny mpamily sy ny fitaovana dia hofaritana amin'ny fikarohana [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26); ny ID saha DCGM dia voamarina amin'ny v3.3.9 sy v4.7.0 |
| AMD | `amd-smi` amin'ny famoahana ROCm hofaritana amin'ny fikarohana [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 sy Maint 1.3, tsy novana, voazaha manomboka hatramin'ny farany ([docs/alerting.md](../../alerting.md)) |

## Famolavolana

- [docs/PLAN.md](../../PLAN.md) no drafitra feno: rafitra, zava-misy avy any ambony iankinany (miaraka amin'ny loharano), modely famantarana, sisin-tany ara-piarovana, kajy fahombiazana, fitsapana, ary loza mety hitranga.
- [docs/decisions/](../../decisions) dia mitahiry ny rakitra fanapahan-kevitra ara-drafitra ho an'ny safidy efa vita.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) dia mampifandray ny ID fepetra takiana tsirairay amin'ny olany.

```mermaid
flowchart LR
    NODE["Vondrona GPU: snmpd extend / DCGM Exporter / amd-smi"] -->|SNMPv3 vakiana fotsiny na HTTPS nahazo alalana| C[Mpanangona voafetra amin'ny poller]
    C --> CACHE[(Cache an'ny plugin voasokajy amin'ny mpamokatra + GPU/MIG UUID)]
    CACHE --> SS[Fampidirana fanontaniana angon'ny script server]
    CACHE --> ECO[CasC CLI, fahaiza-manao MCP, fanamarinana Doctor]
    SS --> RRD[(Rakitra RRD Cacti, loharanon'angona iray isaky ny GPU)]
    SS -->|poller_output| T[Thold sy Maint]
    RRD --> G[Grafika sy fahazoan-dalana Cacti]
```

## Fomba fanaraha-maso ny asa

- **Dingana lehibe** dia manaraka ny dingana lehibe ao amin'ny drafitra. Ny tsirairay dia manana olana fototra mitanisa ny zana-olany sy ny porofon'ny fivoahana.
- **Olana** dia sokajiana amin'ny marika: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Ny olana fepetra takiana dia milaza ny fepetra takiana misy laharana sy azo tsapaina (`REQ-AREA-NNN`) sy ny fepetra fanekena.
- **Marika** dia mitondra ihany koa ny `area:`, `phase:`, `priority:`, ary `status:`. Jereo ny [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- Ny [tabilao tetikasa](https://github.com/users/somethingwithproof/projects/9) dia mampiseho ny olana rehetra manerana ny dingana lehibe.

## Fandraisana anjara

Vakio ny [CONTRIBUTING.md](../../../CONTRIBUTING.md). Mila sonia DCO ny commit. Ny fifanakalozan-kevitra momba ny famolavolana dia atao ao amin'ny olana; mitondra ny marika `type: decision` ny fanapahan-kevitra misokatra. Ny vokatra azo avy amin'ny fitaovana GPU tena izy no fandraisana anjara mahasoa indrindra ho an'ny dingana lehibe 1; esory aloha ny anaran'ny mpampiantrano, ny laharan'ny andiany, ary ny UUID.

## Fiarovana

Tatitrao mitokana ny faharefoana; jereo ny [SECURITY.md](../../../SECURITY.md).

## Fahazoan-dalana

GNU General Public License v2.0, mitovy fahazoan-dalana amin'ny Cacti sy ny plugin-ny. Jereo ny [LICENSE](../../../LICENSE).
