<!-- Source: README.md; SHA256: 97ba37df4a883426d3984ed7d14547ad7367e973411a8abd930409ce259f545b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Karin manhajar Cacti 1.2 ce da ke kawo bayanan amfanin kowace GPU, ƙwaƙwalwa, zafi, wuta, kurakuran ECC, da dalilan rage gudu daga kayan aikin NVIDIA zuwa cikin Cacti. Tallafin AMD da tattara kurakuran XID suna cikin shiri, ba a aiwatar da su ba tukuna. Kowace GPU tana zama fihirisa ta tambayar bayanai (data query) a kan na'urar node ɗinta, don haka zane-zanen GPU suna zama kusa da sauran zane-zanen node ɗin ƙarƙashin izinin Cacti, kuma Thold da Maint za su iya aika gargaɗi da dakatar da su.

> **Matsayi: yana kan ginawa, ba a sake shi ba tukuna.** Mataki na 4 (Milestone 4) ya kammala lambar kashi na 1 (NVIDIA ta hanyar SNMP extend): tattarawa, tantancewa, zane-zane, alamun rage gudu da ECC, gargaɗin Thold da Maint, shafin lafiya, da takardun mai gudanarwa. An gwada shi ne kawai a kan `snmpd` na gaske tare da `nvidia-smi` na kwaikwayo da ke buga sakamakon gwaji, ba a taɓa gudanar da shi a kan na'urar GPU ta gaske ba. Abubuwan da suka rage kafin fitarwa: gwajin farko a kan kayan aiki na gaske ([docs/pilot-plan.md](../../pilot-plan.md), #54), bitar tsaro ta waje ([docs/security-review.md](../../security-review.md)), bayanan gwaji da aka ɗauko daga kayan aiki na gaske (#14), da auna ƙarfin aiki a kan kayan aiki masu girman aikin yau da kullum ([docs/performance.md](../../performance.md)). Kurakuran XID suna jiran shawara #8. Kada a yi amfani da shi a muhallin aiki na gaske (production).

## Matsayin shiga

| Fanni (Realm) | Dama (Access) |
| --- | --- |
| GPU View | Shafukan sa ido da ke akwai. |
| GPU Operator | Sa ido tare da shafin Operations da amincewar tantancewa ta mutum ɗaya. |
| GPU Admin | Damar Operator tare da zaɓuɓɓukan saiti da ayyukan gudanarwa da ke akwai. |

Bayan kwafin fayilolin haɓakawa (upgrade), buɗe shafin Plugins a matsayin mai gudanarwa (administrator) don yin rijistar sabon fannin (realm). Ba da fannin Operator kai tsaye ƙarƙashin **Configuration > Users** ko rukunin masu amfani.
Haɓakawa yana riƙe lambobin ID da izinin fannonin View da Admin da ke akwai. Damar Admin
tana gadar damar Operator, kuma damar Operator tana gadar damar View a kan kowace buƙata;
wannan ba ya ƙara izinin da aka adana ga masu amfani ko rukuni. Saitunan karin manhaja kuma suna buƙatar
damar GPU Admin baya ga izinin Settings na Cacti.

Izinin na'urori na Cacti har yanzu suna iyakance layuka da ayyukan da ke samuwa ga kowane mai amfani; abubuwan da ba a haɗa su ba suna buƙatar mai gudanarwa.
Bayanan sirri (credentials), saiti, haɗawa (mapping), gogewa da sauran sauye-sauyen gudanarwa
suna ci gaba da kasancewa a shafukan gudanarwa. Amincewa (acknowledgments) tana aiki ne kawai a zaman
mai amfani na yanzu, na tsawon awa ɗaya a mafi yawa kuma kawai yayin da wannan lura ta ci gaba
da kasancewa sabuwa. Sabbin bayanai ko waɗanda suka sauya suna soke su. Ba su taɓa nuna ma'aunin
da ya gaza a matsayin lafiyayye ko hana gargaɗi ba.

## Dalili

Nodes na GPU yawanci suna cikin Cacti tuni don CPU, ƙwaƙwalwa, da hanyoyin sadarwa (interfaces). Lafiyar GPU wani batu ne daban wanda bayanan SNMP na node ba sa bayar da amsarsa: kati na iya samun rage gudu saboda zafi, tara kurakuran ECC, ko ba da rahoton matsalar XID yayin da node ɗin da kansa yake kama da lafiyayye. Kayan aikin masana'antun suna ba da rahoton hakan (`nvidia-smi`, DCGM, `amd-smi`), amma sakamakonsu yana wajen zane-zane, izini, da gargaɗin Cacti.

Karin manhajar yana cike wannan giɓi ta hanyar mayar da sakamakon kayan aikin masana'anta zuwa tushen bayanan Cacti (data sources), wanda aka yi masa mabuɗi ta hanyar asalin kowace GPU maimakon matsayinta, ta yadda sake kunna na'ura (reboot) da ke sauya jerin GPUs ba zai haɗa tarihin wani kati zuwa wani ba. Yana sake amfani da zane-zane, izini, masu tattara bayanai na nesa (remote data collectors), Thold, da Maint na Cacti maimakon gina wasu nau'ikan su daban.

## Iyakar shirin

| Kashi | Iyaka | Matakai (Milestones) |
|---|---|---|
| 0, bincike | Sakamakon kayan aikin masana'anta, alamun XID da rage gudu, asalin GPU, izini, shawarar hanyar tattarawa | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Tattarawar `nvidia-smi`, lissafin kayayyaki ta UUID, zane-zane, alamun rage gudu, ECC da XID, Thold da Maint, shafin lafiya | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, backends | Backend na DCGM, backend na `amd-smi` | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig da na nesa | Sassan MIG, masu tattara bayanai na nesa na Cacti | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Abubuwan da ba sa cikin iyaka: kowace umarni da ke sauya GPU ko node (agogo, iyakokin wuta, sake saitawa, tsarin MIG), injin gargaɗi mallakar karin manhaja, daemon ko ka'idar sadarwa ta musamman, da lissafin mai tsara ayyuka (scheduler) ko ayyuka. Duba tsarin don ganin jerin ayyukan bincike.

## Abin da mataki na 4 (milestone 4) ya ƙara

- **Dalilan rage gudu a matsayin ƙimar gargaɗi.** Ana fassara abin rufe fuska na taron agogo (clock event mask) zuwa alamar 0 ko 1 ga kowane dalili; mask ɗin da ba a sani ba yana ba da alamun da ba a sani ba, ba sifili ba, kuma bits da ba su da alama suna zama a cikin ɗanyen mask ɗin, wanda GPU Health ke nunawa.
- **ECC a matsayin ƙimar gargaɗi** ([docs/metrics.md](../../metrics.md)). Karin manhajar yana lissafa sabbin kurakuran da aka gyara da waɗanda ba a gyara ba tun daga samfurin da ya gabata daga jimla biyu masu biye da juna kuma yana adana su a matsayin gauges: ba a sani ba ga samfurin farko, karatu da aka rasa, ko sake loda direba, ta yadda sake saitawa ba zai taɓa karantawa a matsayin sifili ko ƙidaya mara kyau ba. Ana kuma karanta yawan shafukan single-bit da double-bit da suka yi ritaya da yanayin ECC. Karin zane-zanen ECC guda biyu ga kowace GPU suna ba wa Cacti damar adana su; tare da Power Percent, akwai zane-zane goma sha ɗaya gaba ɗaya. Ba a tattara XID ba: babu tushen da ya dace da ƙayyadaddun umarnin `nvidia-smi` (#8).
- **Thold da Maint** ([docs/alerting.md](../../alerting.md)). Samfuran Thold da aka haɗa don zafi, wuta idan aka kwatanta da iyakarta, alamun rage gudu, sabbin kurakuran ECC da ba a gyara ba, jiran ritayar shafi, da tsufan samfuri. Kowace ƙima gauge ce, saboda Thold 1.8.2 yana gazawa a kan ma'aunin da ba a sani ba. Gwaji daga farko zuwa ƙarshe tare da Thold 1.8.2 da Maint 1.3 da ba a sauya ba yana nuna kowane iyaka yana tashi kuma yana dawowa, a yayin sake saita ECC, kuma Maint yana dakatar da shi.
- **GPU Health** (GPU View). Ga kowane node: sabunta, yadda tattarawar ƙarshe ta ƙare, yanayin Maint, backend, nau'ikan kayan aiki da direba, da adadin GPU; ga kowace GPU: kasancewa, tsufan samfuri, yanayin ECC, da ɗanyen mask. Yana karanta bayanan da aka adana ne kawai.
- **Izini.** Rukunin gwajin izini ya shafi kowane fayil na shafi da aiki, soke izini a buƙata ta gaba, da ƙin amincewa da ba ya tona komai.
- **Tabbatar da gazawa da maidowa.** Gwaje-gwajen da aka rubuta daga farko zuwa ƙarshe suna karya agent, bayanan sirri, kayan aiki, sakamakonsa, haɗin rumbun bayanai, da rubuce-rubucen RRD, kuma suna dawo da rumbun bayanai daga kwafin ajiya (dump), har ma da wanda ya tsufa fiye da fayilolin RRD; sabuwar GPU ba za ta taɓa gadar fayil ɗin RRD na wata ba ([docs/backup.md](../../backup.md)).
- **Girman aiki (Scale)** ([docs/performance.md](../../performance.md)). Gwajin girma yana auna lokacin tattarawa, shigarwar uwar garken rubutu (script server), ƙirƙirar abubuwan Cacti, zagayen poller, da shafin lafiya a wani adadin GPU; CI tana gudanar da shi a kan GPUs 100. Ba a yi rikodin ma'aunin GPUs 1,000 da 8,000 ba tukuna, don haka ba a bayyana adadin GPU da ake tallafawa ba.
- **Takardun mai gudanarwa** ([docs/operations.md](../../operations.md)): shigarwa, haɓakawa, cirewa, ƙarancin izini, ƙimomin da ba su samuwa, da magance matsala. An jera sauye-sauye a cikin [CHANGELOG.md](../../../CHANGELOG.md).

## Abin da mataki na 3 (milestone 3) ya ƙara

- **Tattarawa ta hanyar SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Mai gudanarwar node yana ƙara ƙayyadaddun layukan `extend` guda biyu zuwa `snmpd.conf` ([docs/node-setup.md](../../node-setup.md)); karin manhajar yana karanta NET-SNMP-EXTEND-MIB ta hanyar ayyukan SNMP na Cacti tare da bayanan sirri na na'urar kanta, an fi ba da shawarar SNMPv3. Ba ya gudanar da komai a kan uwar garken Cacti ko node ɗin.
- **Tsauraran fassara.** Ana karanta filaye ta matsayi, kowane layi yana sanya sunan GPU ɗinsa ta UUID, kuma matsayin fita da ba sifili ba, ƙarewar lokaci, yanke sakamako, layin da ke da kuskuren adadin filaye, ko haɗaɗɗen rubutun kuskure yana sa duka tattarawar ta gaza: ƙimomi suna zama `U`, ba a nuna wata GPU a matsayin ba ta nan, kuma GPU Nodes yana nuna dalili.
- **Mai tattarawa bayan kowane zagayen poller.** Yana karanta agent na kowane node da aka kunna, yana sabunta bayanan tantancewa, kuma yana adana sabon samfurin na ɗan lokaci (cache). Shigarwar script server tana karanta wannan cache ɗin ne kawai, ta yadda binciken Cacti ba zai taɓa jiran node na GPU ba.
- **Abubuwan Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Samfura da tambayar bayanai ta *GPU - Devices* suna zuwa a matsayin XML kuma ana shigo da su yayin shigarwa da haɓakawa. Adana node yana haɗa tambayar bayanai zuwa na'urar da ke akwai tare da hanyar sake fihirisa ta None; GPU Nodes zai iya ƙirƙirar na'ura ne kawai idan node ɗin ba ya da ita. Kowace GPU fihirisa ce da aka yi wa mabuɗi ta masana'anta da UUID, tare da tushen bayanai guda ɗaya da zane-zanenta na Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages, da Sample Age ke rabawa. Wani zane yana zana kowane fili na samfurin, saboda Cacti yana adana filayen da zane ke amfani da su ne kawai. Karin manhajar yana sake fihirisa bayan kowane sauyin GPU, yana adana sake fihirisar da Cacti ya tsallake yayin da na'urar take Down ko a kashe kuma yana gudanar da ita da zarar na'urar ta tashi Up, kuma yana ƙirƙirar zane-zane ga sabbin GPUs.
- **Tarihi yana zama tare da katin.** GPU da ba ta nan tana zama fihirisa, don haka zane-zanenta suna nuna giɓi kuma suna ci gaba a cikin wannan fayil ɗin RRD idan ta dawo. Katin maye gurbi a wannan ramin yana samun nasa tushen bayanai. **Remove from Data Query** a shafin node ɗin yana cire GPU da ba ta nan daga jerin; ana adana fayilolin RRD da zane-zanenta har sai kun goge su.
- **Izinin na'ura a kan GPU Nodes.** GPU Admin yana gani, sauyawa, fitarwa, da matsar da nodes da GPUs ne kawai a kan na'urorin da aka ba su izinin gani a Cacti; matsar da GPU yana buƙatar duka nodes biyun. Za a iya kashe ko goge node ɗin da aka goge na'urarsa.
- **Tsarin ma'auni na gama-gari** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Ana auna ƙwaƙwalwa a cikin bytes, ana fassara clock event mask zuwa alamun `thr_*`, kuma `age` shi ne tsufan lokacin samfurin GPU da kansa, ko `U` idan ba a sani ba.

Mai amfani mai shigarwa (uwar garken yanar gizo) dole ne ya sami damar rubutu a `<cacti>/resource/script_server/`, inda karin manhajar ke ajiye XML na tambayar bayanai; shigarwa da haɓakawa suna tsayawa tare da saƙo idan ba haka ba kuma suna sake gwadawa a yunƙuri na gaba. Cirewa (uninstall) yana cire samfura da XML kawai idan an duba *Remove the plugin's Cacti objects on uninstall*, kuma ba ya taɓa goge fayilolin RRD, na'urori, zane-zane, ko tushen bayanai ([docs/operations.md](../../operations.md#uninstall)). Ba a tattara nodes a kan masu tattara bayanai na nesa sai a kashi na 3.

## Abin da mataki na 2 (milestone 2) ya ƙunsa

- **Shigarwa mai kariya.** A kan PHP ƙasa da 8.2, ana ƙin shigarwa da kunnawa tare da saƙon da ke ambaton nau'in da ake buƙata, kuma sauran Cacti yana ci gaba da aiki. `setup.php` da rubutun shafi suna fassara a kan PHP 8.0.
- **Tsarin rayuwa (Lifecycle).** Shigarwa, haɓakawa, kashewa, da cirewa ta hanyar shafin Plugins na Cacti. Haɓakawa suna da tsari, suna ƙarewa bayan katsewa, kuma suna sauya nau'in da aka rubuta bayan sun yi nasara ne kawai. Cirewa yana riƙe teburin karin manhajar sai dai idan kun cire alamar *Keep Plugin Tables on Uninstall*; ba ya taɓa taɓa na'urar Cacti, tushen bayanai, zane, ko fayil ɗin RRD.
- **Fannoni daban-daban.** *GPU Admin* yana buɗe **GPU Nodes**, inda kuke yin rijistar na'urorin Cacti a matsayin nodes na GPU, tabbatarwa ko ƙin matsar da GPU tsakanin nodes, da fitar da kowane rikodi a matsayin JSON. *GPU View* yana buɗe **GPU Inventory**, wanda ke jera GPUs ne kawai a kan na'urorin da mai kallo zai iya gani. Duka shafukan suna ƙarƙashin Management, kuma kowane sauyi POST ne da csrf-magic ke bincikawa.
- **Bayanan asalin GPU.** Ana yi wa kowace GPU mabuɗi ta masana'anta da UUID. Index, PCI bus ID, suna, direba, da kayan aikin bayar da rahoto siffofi ne da ke sauyawa a wurin. Sabon UUID a cikin rami da aka sani ana yi masa alama a matsayin yiwuwar maye gurbi kuma yana fara nasa tarihi; GPU da aka gani a wani node tana motsawa kawai idan mai gudanarwa ya tabbatar; GPU da ba a gani ba a cikin tattarawa masu nasara ana yi mata alama a matsayin ba ta nan bayan adadin da za a iya saitawa kuma ba a taɓa goge ta. Ana karɓar NVIDIA whole-GPU UUIDs ne kawai a yanzu; sassan MIG suna jiran shawara #13, kuma AMD a kan bincike #19. Ana gwada ƙa'idodin ta jerin bayanan gwaji, tunda babu mai tattarawa da ke ba su bayanai tukuna.
- **Gwaje-gwaje da CI.** Rukunin gwaje-gwajen naúrar da ƙa'idodin tushe ba tare da GPU ko rumbun bayanai ba, binciken tsarin rayuwa da izinin shafi a kan Cacti 1.2.31 da 1.2.x na yanzu a kan PHP 8.2 zuwa 8.5, aikin ƙin yarda da PHP 8.1, binciken lint na PHP 8.0 na wuraren shiga, PHPStan a mataki na 9, da REUSE. Kayan aikin gwajin na wucin gadi ne har sai an yanke shawara kan #12.

### Shigarwa don gwaji

Waɗannan matakan suna shigar da lambar yanzu don gwada ta; [docs/operations.md](../../operations.md) yana da cikakken jagorar mai gudanarwa. Suna buƙatar Cacti 1.2.31 ko sabo a kan PHP 8.2 ko sabo.

1. Kwafi ma'ajiyar (repository) zuwa `plugins/gpu` ƙarƙashin kundin adireshin Cacti ɗinku. Dole ne a sanya wa kundin adireshin suna `gpu`.
2. A cikin Cacti, buɗe **Configuration > Plugins**, sannan ku shigar ku kunna **GPU**.
3. Ba wa masu amfani fannin *GPU View*, *GPU Operator*, ko *GPU Admin* ƙarƙashin **Configuration > Users**, gwargwadon matsayinsu. Mai amfani da ya shigar yana karɓar View da Admin; Admin yana ƙunshe da damar Operator.
4. Saita wakilin SNMP na kowane node na GPU kamar yadda aka bayyana a [docs/node-setup.md](../../node-setup.md), sannan ƙara node ɗin ƙarƙashin **Management > GPU Nodes**.
5. Don gargaɗi, shigo da samfuran Thold da aka haɗa kamar yadda [docs/alerting.md](../../alerting.md) ya bayyana.
6. Idan ana so, daidaita **Configuration > Settings > GPU**: *Mark Absent After* (tsoho tattarawa 3 masu nasara, daga 1 zuwa 100), *Stale After* (yawan tsufan da samfuri zai yi kafin ƙimominsa su buga `U`), da *Keep Plugin Tables on Uninstall* (a kunne ta tsoho).

## Yadda ma'auni ke isa Cacti

Shawara [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) ta zaɓi SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): wakilin node ɗin da kansa yana gudanar da ƙayyadadden umarnin `nvidia-smi` kuma karin manhajar yana karanta sakamakon ne kawai. Tsarin yana kwatanta zaɓuɓɓukan ƙarƙashin [Hanyoyin tattarawa](../../PLAN.md#collection-paths).

## Manufofin dacewa

| Bangare | Manufa |
|---|---|
| Cacti | 1.2.31 ko sabo, babu gyare-gyaren tushe |
| PHP | 8.2 ko sabo; `setup.php` yana ci gaba da zama mai fassaruwa a kan 8.0 don tsarin da ba a tallafawa ya sami ƙin amincewa maimakon lalacewar Cacti UI |
| NVIDIA | Layukan direba da kayan aiki da bincike [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) zai saita; an duba lambobin filayen DCGM a v3.3.9 da v4.7.0 |
| AMD | `amd-smi` a kan fitowar ROCm da bincike [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) zai saita |
| Thold, Maint | Thold 1.8.2 da Maint 1.3, ba a sauya su ba, an gwada su daga farko zuwa ƙarshe ([docs/alerting.md](../../alerting.md)) |

## Tsari

- [docs/PLAN.md](../../PLAN.md) shi ne cikakken tsari: gine-gine, bayanan gaskiya na sama da ya dogara da su (tare da tushe), tsarin asali, iyakokin tsaro, lissafin ƙarfin aiki, gwaji, da haɗari.
- [docs/decisions/](../../decisions) yana riƙe da bayanan shawarwarin gine-gine don zaɓuɓɓukan da aka riga aka yanke.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) yana haɗa kowace lambar buƙata (requirement ID) zuwa batunta.

```mermaid
flowchart LR
    NODE[Node na GPU: snmpd extend yana gudanar da nvidia-smi] -->|Karantun SNMP, an ba da shawarar v3| C[Mai tattarawa]
    C --> CACHE[(Cache na karin manhaja mai mabudin GPU UUID)]
    CACHE --> SS[Shigarwar tambayar bayanai ta script server]
    SS --> RRD[(Fayilolin RRD na Cacti, tushen bayanai guda daya ga kowace GPU)]
    SS -->|poller_output| T[Thold da Maint]
    RRD --> G[Zane-zane da izinin Cacti]
```

## Yadda ake bin diddigin aiki

- **Matakai (Milestones)** suna bin matakan tsarin. Kowane yana da babban batu (epic issue) da ke jera ƙananan batutuwa da shaidar kammalawa.
- **Batutuwa (Issues)** ana rarraba su ta alama (label): `type: requirement`, `type: research`, `type: decision`, `type: epic`. Batutuwan buƙatu suna bayyana buƙatu masu lamba da za a iya gwadawa (`REQ-AREA-NNN`) da sharuɗɗan karɓa.
- **Alamomi (Labels)** kuma suna ɗauke da `area:`, `phase:`, `priority:`, da `status:`. Duba [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Allon ayyuka](https://github.com/users/somethingwithproof/projects/9) yana nuna duk batutuwa a cikin matakai.

## Bayar da gudummawa

Karanta [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commits suna buƙatar sa hannun DCO (DCO sign-off). Tattaunawar tsari tana faruwa a kan batutuwa; buɗaɗɗun shawarwari suna ɗauke da alamar `type: decision`. Sakamakon da aka ɗauko daga kayan aikin GPU na gaske shi ne gudummawa mafi amfani ga mataki na 1; cire sunayen uwar garke (hostnames), lambobin serial, da UUIDs da farko.

## Tsaro

Ba da rahoton raunin tsaro a asirce; duba [SECURITY.md](../../../SECURITY.md).

## Lasisi

GNU General Public License v2.0, iri ɗaya da lasisin Cacti da karin manhajojinsa. Duba [LICENSE](../../../LICENSE).
