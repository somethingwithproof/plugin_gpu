<!-- Source: README.md; SHA256: 97ba37df4a883426d3984ed7d14547ad7367e973411a8abd930409ce259f545b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [Български](../bg-BG/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [Ελληνικά](../el-GR/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [ქართული](../ka-GE/README.md) | [한국어](../ko-KR/README.md) | [Latviešu](../lv-LV/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Português (Portugal)](../pt-PT/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Iplagini ye-Cacti 1.2 ezisa ukusetyenziswa kwe-GPU nganye, imemori, ubushushu, amandla ombane, iimpazamo ze-ECC, kunye nezizathu zokunciphisa isantya ezivela kwizixhobo ze-NVIDIA neze-AMD kwi-Cacti. I-GPU nganye (okanye umzekelo we-MIG) iba sisalathiso sesicelo sedatha kwisixhobo senodi yayo, ukuze iigrafu ze-GPU zihlale ecaleni kwezinye iigrafu zenodi phantsi kweemvume ze-Cacti, kwaye i-Thold ne-Maint zikwazi ukuthumela izilumkiso kwaye zizibambe.

> **Ubume: izigaba 1 ukuya ku-3 ziphunyeziwe kwikhowudi, azikakhululwa.** Isigaba soku-1 (i-NVIDIA `nvidia-smi` nge-SNMP extend, ukulandelela ubuwena, iigrafu, imiqondiso yokunciphisa isantya neye-ECC, izilumkiso ze-Thold neze-Maint, iphepha lempilo), Isigaba sesi-2 (umhlalutyi/umxhasi ofundwa kuphela we-NVIDIA DCGM Exporter HTTP(S) kunye nomhlalutyi we-AMD `amd-smi`), Isigaba sesi-3 (ukulandelela ubuwena bomzekelo we-MIG, iikholamu ze-Schema v4, ukusetyenziswa komqokeleli okude okulawulwa yi-poller, iihuku ze-`replicate_out` neze-`remote_agent`), i-CLI yomsebenzisi-nkqubo we-CasC (`cli/gpu.php`), kunye neehuku zenkqubo (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) ziphunyeziwe kwaye zagutyungelwa luvavanyo lweyunithi, olokhuseleko, kunye novavanyo lokudibanisa lwe-Cacti esetyenziswa kanye. Uqhutywo lwangoku lusebenzisa i-`snmpd` eyenziweyo kunye nedatha yovavanyo endaweni yezixhobo ze-GPU zokwenyani. Okusavulekile ngaphambi kokuphawula ukukhululwa: iinkqubo zovavanyo kwizixhobo zokwenyani ([docs/pilot-plan.md](../../pilot-plan.md), #54), uphononongo lwangaphandle lokhuseleko ([docs/security-review.md](../../security-review.md)), iisampulu ezithathwe kwizixhobo zokwenyani (#14), kunye nomlinganiselo wobungakanani kwizixhobo ezilingana nezemveliso ([docs/performance.md](../../performance.md)). Ukuqokelelwa kweempazamo ze-XID kuhlala kuvulekile phantsi kwesigqibo #8.

## Iindima zofikelelo

| Ummandla | Ufikelelo |
| --- | --- |
| GPU View | Amaphepha okubeka iliso akhoyo. |
| GPU Operator | Ukubeka iliso kunye nephepha le-Operations nezivumo zobuqu zokuhlela imiba. |
| GPU Admin | Ufikelelo lwe-Operator kunye noqwalaselo olukhoyo nezenzo zolawulo. |

Emva kokukopa iifayile zohlaziyo, vula iphepha le-Plugins njengomlawuli ukuze ubhalise ummandla omtsha. Yabela ummandla we-Operator ngokucacileyo phantsi kwe-**Configuration > Users** okanye amaqela abasebenzisi. Uhlaziyo lugcina ii-ID kunye nezibonelelo zommandla we-View newe-Admin ezikhoyo. Ufikelelo lwe-Admin lufumana ilifa lofikelelo lwe-Operator, kwaye ufikelelo lwe-Operator lufumana ilifa lofikelelo lwe-View kwisicelo ngasinye; oku akongezi zibonelelo zigciniweyo kubasebenzisi okanye kumaqela. Iisethingi zeplagini nazo zifuna ufikelelo lwe-GPU Admin ukongeza kwimvume ye-Settings ye-Cacti.

Iimvume zezixhobo ze-Cacti zisayilinganisela imiqolo kunye nezenzo ezifumaneka kumsebenzisi ngamnye; izinto ezingametywanga zifuna umlawuli. Iziqinisekiso, uqwalaselo, umepho, ukucima kunye nolunye utshintsho lolawulo zihlala kumaphepha olawulo. Izivumo zihlala kuphela kwiseshoni yomsebenzisi wangoku, kangangeyure enye ubuninzi kwaye kuphela ngelixa kwa oko kuqwalaselweyo kusekutsha. Uqwalaselo olutsha okanye olutshintshileyo luyaziphelisa. Azize ziphawule umlinganiselo ohlulekileyo njengosempilweni okanye zibambe izilumkiso.

## Kutheni

Iinodi ze-GPU ziqhele ukuba sele zikwi-Cacti nge-CPU, imemori, kunye nojongano lwenethiwekhi. Impilo ye-GPU ngumbuzo owahlukileyo idatha ye-SNMP yenodi engawuphenduliyo: ikhadi linokuncitshiswa isantya ngenxa yobushushu, liqokelele iimpazamo ze-ECC, okanye linike ingxelo yempazamo ye-XID ngelixa inodi ngokwayo ibonakala isempilweni. Izixhobo zabathengisi ziyayixela (`nvidia-smi`, i-DCGM, `amd-smi`), kodwa isiphumo sazo sihlala ngaphandle kweegrafu, iimvume, kunye nezilumkiso ze-Cacti.

Iplagini iyavala eso sikhewu ngokuguqula isiphumo sesixhobo somthengisi sibe yimithombo yedatha ye-Cacti, ekhiywe ngobuwena be-GPU nganye endaweni yendawo yayo, ukuze ukuqalisa kwakhona okucwangcisa ngokutsha ii-GPU kungafakeli imbali yekhadi elinye kwelinye. Isebenzisa kwakhona ukuzoba iigrafu kwe-Cacti, iimvume, abaqokeleli bedatha abakude, i-Thold, kunye ne-Maint endaweni yokwakha iinguqulelo zazo ezihambelanayo.

## Umda ocwangcisiweyo

| Isigaba | Umda | Ubume | Amanqanaba |
|---|---|---|---|
| 0, uphando | Isiphumo sesixhobo somthengisi, imiqondiso ye-XID neyokunciphisa isantya, ubuwena be-GPU, amalungelo, isigqibo sendlela yokuqokelela | Kugqityiwe | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Ukuqokelela kwe-`nvidia-smi`, uluhlu nge-UUID, iigrafu, imiqondiso yokunciphisa isantya neye-ECC, i-Thold ne-Maint, iphepha lempilo | Kuphunyeziwe (kuvavanywe ngamasampulu) | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, ii-backend | Umhlalutyi/umxhasi ofundwa kuphela we-DCGM Exporter HTTP(S) (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`), umhlalutyi we-AMD `amd-smi` (`lib/AmdSmiParser.php`) | Kuphunyeziwe (kuvavanywe ngeyunithi) | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, i-mig, ukukude, nenkqubo | Ukulandelela ubuwena bomzekelo we-MIG, abaqokeleli bedatha abakude (`INFO`, `poller_gpu.php --poller=N`, `replicate_out`, `remote_agent`), i-CLI ye-CasC (`cli/gpu.php`), iihuku ze-MCP/Doctor/CasC | Kuphunyeziwe (kuvavanywe ngeyunithi nokudibanisa kwe-CI) | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Okungaphandle komda: nawuphi na umyalelo otshintsha i-GPU okanye inodi (iiwotshi, imida yamandla ombane, ukusetha ngokutsha, uyilo lwe-MIG), injini yezilumkiso elawulwa yiplagini, i-daemon yesiqhelo okanye iprothokholi yenethiwekhi, kunye nolawulo lweengxelo lomsebenzi okanye lweshedyuli. Jonga isicwangciso ngomsebenzi wophando osele ulindile.

## Okongezwa linqanaba lesi-4

- **Umqokeleli ofundwa kuphela we-NVIDIA DCGM Exporter** (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`). Uhlela imilinganiselo yokuboniswa kombhalo we-Prometheus (`DCGM_FI_DEV_GPU_UTIL`, `DCGM_FI_DEV_FB_USED`, `DCGM_FI_DEV_FB_FREE`, `DCGM_FI_DEV_POWER_USAGE`, `DCGM_FI_DEV_GPU_TEMP`, `DCGM_FI_DEV_ECC_DBE_VOL_TOTAL`, `DCGM_FI_DEV_CLOCK_THROTTLE_REASONS`, kunye neeleyibhile ze-MIG `GPU_I_ID` no-`GPU_I_PROFILE`). Umxhasi we-HTTP(S) unyanzelisa uluhlu olucacileyo lweehost/CIDR ezivumelekileyo, uqinisekisa iziqinisekiso ze-TLS ngokungagqibekanga, ubeka umda wexesha lokuphela nobungakanani bempendulo, uvala ukuthunyelwa kwenye indawo, kwaye wala iinkqubo ezingezizo eze-HTTP(S).
- **Umhlalutyi ofundwa kuphela we-AMD `amd-smi`** (`lib/AmdSmiParser.php`). Ulungelelanisa isiphumo se-JSON/SNMP-extend se-`amd-smi` (ukusetyenziswa, i-VRAM, amandla ombane, ubushushu be-junction/edge, i-ECC, imethadatha yesahlulo, kunye neziqokeleli ezahlukeneyo zokunciphisa isantya nge-`Reading::$vendorExtensions`) kwimodeli eqhelekileyo yemilinganiselo phantsi kwe-`Vendor::Amd`.
- **Ukulandelela imizekelo ye-MIG kunye ne-Schema v4** (`lib/GpuReport.php`, `lib/GpuSnapshot.php`, `lib/GpuRecord.php`, `lib/Schema.php`, `lib/ScriptServer.php`). Ilandelela ii-UUID zemizekelo ye-MIG (`MIG-<uuid>` kunye ne-`MIG-GPU-<parent>/<gi>/<ci>`), `mig_mode`, `mig_instance_id`, `mig_profile`, `parent_uuid`, kunye ne-`partition_info` ecaleni kwee-GPU ezingabazali ngelixa igcina izitshixo zobuwena ezingaguqukiyo (`vendor`, `uuid`, `parent_uuid`) zikhuselekile kuhlaziyo kwaye ziqinisekiswa phambi kwemibuzo yedathabheyisi.
- **Abaqokeleli bedatha abakude** (`INFO`, `poller_gpu.php`, `lib/Poller.php`, `lib/ReplicationRepository.php`, `setup.php`). Ibhengeza `remote_collect:1, remote_poller:1` kwi-`INFO`, ixhasa uqhutywo olulawulwa yi-poller nge-`php plugins/gpu/poller_gpu.php --poller=N`, itsiba ukudalwa kwezinto ze-Cacti kwii-poller ezingezizo eziphambili, iphindaphinda iitheyibhile ezilawulwa yi-poller nge-`replicate_out`, kwaye iphendula izenzo ezifundwa kuphela ze-`gpu_health` neze-`gpu_inventory` nge-`remote_agent`.
- **I-CLI yomsebenzisi-nkqubo neye-CasC** (`cli/gpu.php`, `lib/CascProvider.php`). Isebenza kwi-PHP 8.2+ (icazululeka kwi-PHP 8.0) ngaphandle kweendlela zokuxhomekeka zangaphandle ze-Composer: `php plugins/gpu/cli/gpu.php export [--format=yaml|xml]`, `validate <file> [--format=yaml|xml]`, `plan <file> [--format=yaml|xml] [--output=text|json]`, `apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]`, kunye ne-`health [--format=text|json]`. Izicwangciso zibala i-hash yoqinisekiso ye-SHA-256 ezinzileyo, i-`apply` iyasilela ngokukhuselekileyo xa i-hash ingahambelani, kwaye ucazululo lwe-XML luyazala izibhengezo ze-`DOCTYPE` neze-`ENTITY`.
- **Iihuku zokudibanisa inkqubo** (`lib/EcosystemIntegration.php`, `setup.php`). Ibhalisa i-`abilities_register` (`gpu-list-nodes`, `gpu-list-devices`, `gpu-get-health`; zifundwa kuphela, zivulelwa kummandla we-`GPU View` nakwiimvume zezixhobo ze-Cacti), i-`doctor_register_checks` (`gpu_schema`, `gpu_poller_freshness`, `gpu_node_health`), kunye ne-`casc_register_providers`.
- **Izizathu zokunciphisa isantya ne-ECC njengamaxabiso ezilumkiso** ([docs/metrics.md](../../metrics.md)). Icazulula iimaski zeziganeko zewotshi ibe zizalathiso zika-0/1 kwaye ibala iimpazamo ezintsha ezilungisiweyo/ezingalungiswanga ze-ECC ngexesha ngalinye njengee-gauge ukuze i-Thold 1.8.2 ne-Maint 1.3 zilumkise kwaye zibambe ngaphandle kweekhawunta ezingaphantsi kwezero okanye ukutsiba kokusetha ngokutsha ([docs/alerting.md](../../alerting.md)).
- **I-GPU Health, iimvume, ukusilela/ukubuyisela, kunye nobungakanani.** I-GPU Health (GPU View) ibonisa ubutsha obugciniweyo, isiphumo sokuqokelela kokugqibela, ubume be-Maint, i-backend, iinguqulelo, kunye neemaski ezikrwada zokunciphisa isantya ngaphandle kokunxibelelana neenodi. Uvavanyo olubhalwe ngesikripthi ukusuka ekuqaleni ukuya esiphelweni luqinisekisa iimvume, ukubuyisela kwimeko yesiqhelo kokusilela kwe-arhente/DB/RRD ([docs/backup.md](../../backup.md)), kunye noqhutywo lobungakanani bee-GPU ezili-100 kwi-CI ([docs/performance.md](../../performance.md), [docs/operations.md](../../operations.md), [CHANGELOG.md](../../../CHANGELOG.md)).

## Okongezwa linqanaba lesi-3

- **Ukuqokelela nge-SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Umlawuli wenodi wongeza imigca emibini emiselweyo ye-`extend` kwi-`snmpd.conf` ([docs/node-setup.md](../../node-setup.md)); iplagini ifunda i-NET-SNMP-EXTEND-MIB ngemisebenzi ye-SNMP ye-Cacti ngeziqinisekiso zesixhobo ngokwaso, kucetyiswa i-SNMPv3. Ayiqhubi nto kwiseva ye-Cacti okanye kwinodi.
- **Ucazululo olungqongqo.** Imihlathi ifundwa ngokwendawo yayo, umgca ngamnye ubiza i-GPU yawo nge-UUID, kwaye ubume bokuphuma obungenguye uzero, ukuphela kwexesha, isiphumo esinqunyiweyo, umgca onenani elingalunganga lemihlathi, okanye umbhalo wempazamo odityanisiweyo uyawisa wonke umsebenzi wokuqokelela: amaxabiso aba ngu-`U`, akukho GPU iphawulwa njengengekho, kwaye i-GPU Nodes ibonisa isizathu.
- **Umqokeleli emva komjikelo ngamnye we-poller.** Ufunda iarhente yenodi nganye evuliweyo, uhlaziya iirekhodi zobuwena, kwaye ugcina isampulu yamva nje kuvimba wethutyana. Igalelo leseva yesikripthi lifunda loo vimba wethutyana kuphela, ukuze uvavanyo lwe-Cacti lungaze lulinde inodi ye-GPU.
- **Izinto ze-Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Iitemplate kunye nesicelo sedatha se-*GPU - Devices* zithunyelwa njenge-XML kwaye zingeniswa ekufakelweni nasekuhlaziyweni. Ukugcina inodi kuncamathisela isicelo sedatha kwisixhobo sayo esikhoyo ngendlela yokuphinda wenze isalathiso engu-None; i-GPU Nodes ingadala isixhobo kuphela xa inodi ingenayo. I-GPU nganye sisalathiso esikhiywe ngumthengisi kunye ne-UUID, inomthombo omnye wedatha owabelwanayo yi-Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages, kunye ne-Sample Age graphs. Umhlathi ngamnye wemodeli uzotywa yigrafu ethile, kuba i-Cacti igcina kuphela imihlathi esetyenziswa yigrafu. Iplagini iphinda yenze isalathiso emva kwalo lonke utshintsho lwe-GPU, igcina ukuphinda kwenziwa kwesalathiso i-Cacti ekutsibayo ngelixa isixhobo besiphantsi okanye sicinyiwe kwaye ikuqhube wakuba isixhobo siphakamile, kwaye idala iigrafu zee-GPU ezintsha.
- **Imbali ihlala nekhadi.** I-GPU engekho ihlala isisakhono sesalathiso, ukuze iigrafu zayo zibonise isikhewu kwaye ziqhube kwakhona kwakwifayile ye-RRD enye xa ibuya. Ikhadi elitshintshayo kwisikhundla esifanayo lifumana owalo umthombo wedatha. I-**Remove from Data Query** kwiphepha lenodi ikhupha i-GPU engekho kuluhlu; iifayile zayo ze-RRD kunye neegrafu ziyagcinwa de uzicime.
- **Iimvume zezixhobo kwi-GPU Nodes.** I-GPU Admin ibona, itshintsha, ithumela ngaphandle, kwaye ihambisa kuphela iinodi kunye nee-GPU ezikwizixhobo enokuzibona kwi-Cacti; ukuhambisa i-GPU kufuna zombini iinodi. Inodi esixhobo sayo sacinywayo ingavalwa okanye icinywe.
- **Imodeli eqhelekileyo yemilinganiselo** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Imemori ikwii-byte, imaski yesiganeko sewotshi icazululwa ibe zizalathiso ze-`thr_*`, kwaye i-`age` bubudala bexesha lesampulu le-GPU ngokwayo, okanye u-`U` xa bungaziwa.

Umsebenzisi ofakelayo (oweseva yewebhu) kufuneka akwazi ukubhala kwi-`<cacti>/resource/script_server/`, apho iplagini ibeka khona i-XML yesicelo sedatha; ukufakela kunye nokuhlaziya kuyema ngomyalezo ukuba akunjalo kwaye kuphinde kuzame kulingo olulandelayo. Ukususa kuthatha iitemplate kunye ne-XML kuphela xa i-*Remove the plugin's Cacti objects on uninstall* ikhethiwe, kwaye akaze kucime iifayile ze-RRD, izixhobo, iigrafu, okanye imithombo yedatha ([docs/operations.md](../../operations.md#uninstall)). Iinodi ezikubaqokeleli bedatha abakude zisebenza phantsi ko-`poller_id` ezabelwe wona.

## Okuhanjiswa linqanaba lesi-2

- **Ukufakela okukhuselekileyo.** Kwi-PHP engaphantsi kwe-8.2, ukufakela nokuvula kuyaliwa ngomyalezo obiza inguqulelo efunekayo, kwaye yonke enye i-Cacti iyaqhubeka isebenza. I-`setup.php` kunye nezikripthi zamaphepha ziyacazululeka kwi-PHP 8.0.
- **Umjikelo wobomi.** Ukufakela, ukuhlaziya, ukuvala, kunye nokususa ngephepha le-Plugins le-Cacti. Uhlaziyo lulandelelaniswa, lugqiba emva kokuphazamiseka, kwaye lutshintsha inguqulelo ebhalisiweyo kuphela emva kokuphumelela. Ukususa kugcina iitheyibhile zeplagini ngaphandle kokuba ucime i-*Keep Plugin Tables on Uninstall*; akaze kuchukumise isixhobo se-Cacti, umthombo wedatha, igrafu, okanye ifayile ye-RRD.
- **Imimandla eyahlukeneyo.** I-*GPU Admin* ivula i-**GPU Nodes**, apho ubhalisa khona izixhobo ze-Cacti njengeenodi ze-GPU, uqinisekise okanye wale ukuhamba kwee-GPU phakathi kweenodi, kwaye uthumele ngaphandle yonke irekhodi njenge-JSON. I-*GPU View* ivula i-**GPU Inventory**, edwelisa ii-GPU kuphela kwizixhobo umboni anokuzibona. Omabini amaphepha aphantsi kwe-Management, kwaye lonke utshintsho yi-POST ekhangelwa yi-csrf-magic.
- **Iirekhodi zobuwena be-GPU.** I-GPU nganye ikhiywe ngumthengisi kunye ne-UUID. Isalathiso, i-PCI bus ID, igama, umqhubi, kunye nesixhobo esinika ingxelo ziimpawu ezitshintsha kwindawo yazo. I-UUID entsha kwisikhundla esaziwayo iphawulwa njengokutshintsha okunokwenzeka kwaye iqala eyayo imbali; i-GPU ebonwe kwenye inodi ihamba kuphela xa umsebenzisi-nkqubo eqinisekisa; i-GPU elahlekileyo kwimisebenzi yokuqokelela ephumeleleyo iphawulwa njengengekho emva kwenani eliqwalaselwayo kwaye ayize icinywe. Zii-UUID ze-GPU iphela ze-NVIDIA kuphela ezamkelwayo okwangoku; imizekelo ye-MIG ilinde isigqibo #13, kwaye i-AMD ilinde uphando #19. Imithetho ilingwa ngokulandelelana kweesampulu zovavanyo, kuba akukho mqokeleli uzondlayo okwangoku.
- **Uvavanyo kunye ne-CI.** Iisuyithi zeyunithi nezemithetho yomthombo ezingena-GPU okanye dathabheyisi, ukukhangelwa komjikelo wobomi neemvume zamaphepha ngokuchasene ne-Cacti 1.2.31 neyamanje ye-1.2.x kwi-PHP 8.2 ukuya ku-8.5, umsebenzi wokwala we-PHP 8.1, i-lint ye-PHP 8.0 yee-entry point, i-PHPStan kwinqanaba le-9, kunye ne-REUSE. Izixhobo zovavanyo zezethutyana de kube kugqitywa nge-#12.

### Ukufakela ukuze kuvavanywe

La manyathelo afakela ikhowudi yangoku ukuze uyizame; i-[docs/operations.md](../../operations.md) inesikhokelo esipheleleyo somsebenzisi-nkqubo. Afuna i-Cacti 1.2.31 okanye yamva kwi-PHP 8.2 okanye yamva.

1. Kopa indawo yokugcina ikhowudi kwi-`plugins/gpu` phantsi kolawulo lwakho lwe-Cacti. Ulawulo kufuneka lubizwe ngokuba yi-`gpu`.
2. Kwi-Cacti, vula i-**Configuration > Plugins**, emva koko ufakele kwaye uvule i-**GPU**.
3. Nika abasebenzisi ummandla we-*GPU View*, i-*GPU Operator*, okanye i-*GPU Admin* phantsi kwe-**Configuration > Users**, ngokwendima yabo. Umsebenzisi ofakelayo ufumana i-View kunye ne-Admin; i-Admin iquka ufikelelo lwe-Operator.
4. Cwangcisa iarhente ye-SNMP yenodi nganye ye-GPU njengoko kuchaziwe kwi-[docs/node-setup.md](../../node-setup.md), emva koko wongeze inodi phantsi kwe-**Management > GPU Nodes**.
5. Ngezilumkiso, ngenisa iitemplate ze-Thold ezithunyelwayo njengoko kuchazwa yi-[docs/alerting.md](../../alerting.md).
6. Ngokuzikhethela lungisa i-**Configuration > Settings > GPU**: *Mark Absent After* (okusele kumiselwe yimisebenzi yokuqokelela ephumeleleyo emi-3, ukusuka ku-1 ukuya ku-100), *Stale After* (ukuba isampulu ingaba ndala kangakanani phambi kokuba amaxabiso ayo aprinte u-`U`), kunye ne-*Keep Plugin Tables on Uninstall* (okusele kuvuliwe).

## Indlela imilinganiselo efika ngayo kwi-Cacti

Isigqibo [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) sakhetha i-SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): iarhente yenodi ngokwayo iqhuba umyalelo omiselweyo we-`nvidia-smi` kwaye iplagini ifunda isiphumo kuphela. Isicwangciso sithelekisa iinketho phantsi kwe-[Collection paths](../../PLAN.md#collection-paths).

## Iinjongo zokuhambelana

| Ilungu | Injongo |
|---|---|
| Cacti | 1.2.31 okanye yamva, akukho zilungiso zongundoqo |
| PHP | 8.2 okanye yamva; i-`setup.php` ihlala icazululeka kwi-8.0 ukuze indawo yokusebenza engaxhaswayo ifumane ukwaliwa endaweni ye-UI ye-Cacti eyaphukileyo |
| NVIDIA | Imigca yomqhubi neyesixhobo ezakumiselwa luphando [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26); ii-ID zemihlathi ye-DCGM zikhangelwe kwi-v3.3.9 nakwi-v4.7.0 |
| AMD | I-`amd-smi` kwiinguqulelo ze-ROCm ezakumiselwa luphando [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | I-Thold 1.8.2 ne-Maint 1.3, zingatshintshwanga, zivavanywe ukusuka ekuqaleni ukuya esiphelweni ([docs/alerting.md](../../alerting.md)) |

## Uyilo

- I-[docs/PLAN.md](../../PLAN.md) sisicwangciso esipheleleyo: uyilo lwenkqubo, iinyaniso ezisemthombo ezixhomekeke kuzo (kunye nemithombo), imodeli yobuwena, imida yokhuseleko, izibalo zokusebenza, uvavanyo, kunye nemingcipheko.
- I-[docs/decisions/](../../decisions) igcina iirekhodi zezigqibo zoyilo lwenkqubo zokhetho olusele lwenziwe.
- I-[docs/REQUIREMENTS.md](../../REQUIREMENTS.md) ibeka i-ID yemfuneko nganye kumba wayo.

```mermaid
flowchart LR
    NODE["Inodi ye-GPU: snmpd extend / DCGM Exporter / amd-smi"] -->|"I-SNMPv3 efundwa kuphela okanye i-HTTPS evumelekileyo"| C["Umqokeleli olawulwa yi-poller"]
    C --> CACHE[("Uvimba weplagini okhiywe ngomthengisi + ne-UUID ye-GPU/MIG")]
    CACHE --> SS["Igalelo lesicelo sedatha leseva yesikripthi"]
    CACHE --> ECO["I-CLI ye-CasC, izakhono ze-MCP, iitshekhi ze-Doctor"]
    SS --> RRD[("Iifayile ze-Cacti RRD, umthombo omnye wedatha nge-GPU nganye")]
    SS -->|poller_output| T["I-Thold kunye ne-Maint"]
    RRD --> G["Iigrafu ze-Cacti kunye neemvume"]
```

## Indlela umsebenzi olandelelwa ngayo

- **Amanqanaba** alandela amanqanaba esicwangciso. Elinye nelinye linomba omkhulu odwelisa imiba yawo engaphantsi kunye nobungqina bokuphuma.
- **Imiba** ichazwa ngokweleyibhile: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Imiba yeemfuno ichaza iimfuno ezinamanani ezivavanyekayo (`REQ-AREA-NNN`) kunye nemigaqo yokwamkelwa.
- **Iileyibhile** zikwaphatha u-`area:`, `phase:`, `priority:`, kunye no-`status:`. Jonga i-[CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- I-[project board](https://github.com/users/somethingwithproof/projects/9) ibonisa yonke imiba kumanqanaba onke.

## Ukufaka isandla

Funda i-[CONTRIBUTING.md](../../../CONTRIBUTING.md). Ii-commit zifuna utyikityo lwe-DCO. Ingxoxo yoyilo yenzeka kwimiba; izigqibo ezivulekileyo ziphatha ileyibhile ye-`type: decision`. Isiphumo esithathwe kwizixhobo ze-GPU zokwenyani lelona galelo liluncedo kwinqanaba loku-1; susa amagama eehost, iinombolo zesiriyali, kunye nee-UUID kuqala.

## Ukhuseleko

Xela ubuthathaka ngasese; jonga i-[SECURITY.md](../../../SECURITY.md).

## Ilayisensi

I-GNU General Public License v2.0, ilayisensi efanayo neye-Cacti kunye neeplagini zayo. Jonga i-[LICENSE](../../../LICENSE).
