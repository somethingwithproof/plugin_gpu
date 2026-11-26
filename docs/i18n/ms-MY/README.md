<!-- Source: README.md; SHA256: 126deef1783d64f878bf565f1433c10f31aef828eb9bc313aeff2b0335e54c7c -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Pemalam Cacti 1.2 yang membawakan penggunaan per-GPU, memori, suhu, kuasa, ralat ECC, dan sebab pendikitan daripada alatan NVIDIA ke dalam Cacti. Sokongan AMD dan pengumpulan ralat XID adalah dirancang, belum dilaksanakan. Setiap GPU menjadi satu indeks bagi pertanyaan data pada peranti nodnya, jadi graf GPU berada di sebelah graf lain nod tersebut di bawah kebenaran Cacti, serta Thold dan Maint boleh memberi amaran dan menyekatnya.

> **Status: dalam pembangunan, belum dikeluarkan.** Pencapaian 4 menjadikan fasa 1 (NVIDIA melalui sambungan SNMP) lengkap dari segi kod: pengumpulan, identiti, graf, isyarat pendikitan dan ECC, amaran Thold dan Maint, halaman kesihatan, serta dokumentasi pengendali. Ia hanya pernah dijalankan terhadap `snmpd` sebenar dengan `nvidia-smi` palsu yang mencetak output sintetik, tidak pernah pada perkakasan GPU. Masih terbuka sebelum keluaran: projek rintis pada perkakasan sebenar ([docs/pilot-plan.md](../../pilot-plan.md), #54), semakan keselamatan luaran ([docs/security-review.md](../../security-review.md)), lekapan yang dirakam daripada alatan sebenar (#14), dan skala yang diukur pada perkakasan bersaiz pengeluaran ([docs/performance.md](../../performance.md)). Ralat XID menunggu keputusan #8. Jangan jalankannya dalam persekitaran pengeluaran.

## Peranan akses

| Alam | Akses |
| --- | --- |
| GPU View | Halaman pemantauan sedia ada. |
| GPU Operator | Pemantauan serta halaman Operasi dan pengakuan triaj peribadi. |
| GPU Admin | Akses Operator serta konfigurasi sedia ada dan tindakan pentadbiran. |

Selepas menyalin fail naik taraf, buka halaman Plugins sebagai pentadbir untuk mendaftarkan
alam baharu. Berikan alam Operator secara eksplisit di bawah **Configuration > Users** atau kumpulan pengguna.
Naik taraf mengekalkan ID alam View dan Admin serta pemberian sedia ada. Akses Admin
mewarisi akses Operator, dan akses Operator mewarisi akses View pada setiap permintaan;
ini tidak menambah pemberian tersimpan kepada pengguna atau kumpulan. Tetapan pemalam juga memerlukan
akses GPU Admin sebagai tambahan kepada kebenaran Settings Cacti.

Kebenaran peranti Cacti masih mengehadkan baris dan tindakan yang tersedia kepada setiap pengguna; objek yang tidak dipetakan memerlukan pentadbir.
Bukti kelayakan, konfigurasi, pemetaan, pemadaman dan perubahan pentadbiran lain
kekal dalam halaman pentadbiran. Pengakuan hanya bertahan dalam sesi pengguna
semasa, selama paling lama satu jam dan hanya selagi pemerhatian yang sama kekal
segar. Pemerhatian baharu atau yang berubah akan membatalkannya. Ia tidak pernah menandakan pengukuran
yang gagal sebagai sihat atau menyekat amaran.

## Mengapa

Nod GPU biasanya sudah berada dalam Cacti untuk CPU, memori, dan antara muka. Kesihatan GPU ialah persoalan berasingan yang tidak dijawab oleh data SNMP nod: sesuatu kad boleh mengalami pendikitan terma, mengumpul ralat ECC, atau melaporkan kerosakan XID sedangkan nod itu sendiri kelihatan sihat. Alatan vendor melaporkannya (`nvidia-smi`, DCGM, `amd-smi`), tetapi outputnya berada di luar graf, kebenaran, dan sistem amaran Cacti.

Pemalam ini merapatkan jurang tersebut dengan menukar output alatan vendor menjadi sumber data Cacti, yang dikunci mengikut identiti setiap GPU dan bukannya kedudukannya, supaya but semula yang menyusun semula GPU tidak melampirkan sejarah satu kad kepada kad yang lain. Ia menggunakan semula penggrafan, kebenaran, pengumpul data jauh, Thold, dan Maint milik Cacti daripada membina versi selari baginya.

## Skop yang dirancang

| Fasa | Skop | Pencapaian |
|---|---|---|
| 0, penyelidikan | Output alatan vendor, isyarat XID dan pendikitan, identiti GPU, keistimewaan, keputusan laluan pengumpulan | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Pengumpulan `nvidia-smi`, inventori mengikut UUID, graf, isyarat pendikitan, ECC dan XID, Thold dan Maint, halaman kesihatan | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, bahagian belakang | Bahagian belakang DCGM, bahagian belakang `amd-smi` | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig dan jauh | Kejadian MIG, pengumpul data jauh Cacti | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Di luar skop: sebarang arahan yang mengubah GPU atau nod (jam, had kuasa, tetapan semula, susun atur MIG), enjin amaran milik pemalam, daemon tersuai atau protokol rangkaian, serta penjadualan atau perakaunan kerja. Lihat pelan untuk tunggakan penyelidikan.

## Perkara yang ditambah oleh pencapaian 4

- **Sebab pendikitan sebagai nilai amaran.** Topeng peristiwa jam dinyahkod menjadi satu penunjuk 0 atau 1 bagi setiap sebab; topeng yang tidak diketahui menghasilkan penunjuk yang tidak diketahui, tidak pernah sifar, dan bit tanpa penunjuk kekal dalam topeng mentah, yang dipaparkan oleh GPU Health.
- **ECC sebagai nilai amaran** ([docs/metrics.md](../../metrics.md)). Ralat terbetul dan tidak terbetul baharu sejak sampel sebelumnya dikira oleh pemalam daripada dua jumlah berturutan dan disimpan sebagai tolok: tidak diketahui bagi sampel pertama, bacaan yang terlepas, atau muat semula pemacu, supaya tetapan semula tidak pernah dibaca sebagai sifar atau sebagai kiraan negatif. Kiraan halaman bersara bit tunggal dan bit berganda serta mod ECC turut dibaca. Dua graf ECC tambahan bagi setiap GPU membolehkan Cacti menyimpannya; dengan Power Percent, terdapat sebelas graf kesemuanya. XID tidak dikumpul: tiada sumber yang sesuai dengan arahan `nvidia-smi` tetap (#8).
- **Thold dan Maint** ([docs/alerting.md](../../alerting.md)). Templat Thold yang dibekalkan untuk suhu, kuasa berbanding hadnya, penunjuk pendikitan, ralat ECC tidak terbetul baharu, persaraan halaman belum selesai, dan umur sampel. Setiap nilai ialah tolok, kerana Thold 1.8.2 gagal pada pembilang yang tidak diketahui. Ujian hujung ke hujung dengan Thold 1.8.2 dan Maint 1.3 yang tidak diubah menunjukkan setiap ambang dicetuskan dan dipulihkan, merentasi tetapan semula ECC, dan Maint menyekatnya.
- **GPU Health** (GPU View). Bagi setiap nod: kesegaran, cara pengumpulan terakhir berakhir, keadaan Maint, bahagian belakang, versi alatan dan pemacu, serta kiraan GPU; bagi setiap GPU: kehadiran, umur sampel, keadaan ECC, dan topeng mentah. Ia hanya membaca rekod yang disimpan.
- **Kebenaran.** Suit kebenaran merangkumi setiap fail halaman dan tindakan, pembatalan pada permintaan seterusnya, dan penolakan yang tidak mendedahkan apa-apa.
- **Kelayakan kegagalan dan pemulihan.** Larian hujung ke hujung berskrip memutuskan ejen, bukti kelayakan, alatan, outputnya, sambungan pangkalan data, dan penulisan RRD, serta memulihkan pangkalan data daripada lambakan, termasuk yang lebih lama daripada fail RRD; GPU baharu tidak pernah mewarisi fail RRD milik GPU lain ([docs/backup.md](../../backup.md)).
- **Skala** ([docs/performance.md](../../performance.md)). Ujian skala mencatat masa pengumpulan, input pelayan skrip, penciptaan objek Cacti, kitaran peninjau, dan halaman kesihatan pada kiraan GPU tertentu; CI menjalankannya pada 100 GPU. Pengukuran 1,000 dan 8,000 GPU belum direkodkan lagi, jadi tiada kiraan GPU yang disokong dinyatakan.
- **Dokumentasi pengendali** ([docs/operations.md](../../operations.md)): pemasangan, naik taraf, nyahpasang, keistimewaan minimum, nilai tidak tersedia, dan penyelesaian masalah. Perubahan disenaraikan dalam [CHANGELOG.md](../../../CHANGELOG.md).

## Perkara yang ditambah oleh pencapaian 3

- **Pengumpulan melalui sambungan SNMP** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Pentadbir nod menambah dua baris `extend` tetap ke dalam `snmpd.conf` ([docs/node-setup.md](../../node-setup.md)); pemalam membaca NET-SNMP-EXTEND-MIB melalui fungsi SNMP Cacti dengan bukti kelayakan peranti itu sendiri, SNMPv3 disyorkan. Ia tidak menjalankan apa-apa pada pelayan Cacti atau nod.
- **Penghuraian ketat.** Medan dibaca mengikut kedudukan, setiap baris menamakan GPU-nya mengikut UUID, dan status keluar bukan sifar, tamat masa, output terpotong, baris dengan kiraan medan yang salah, atau teks ralat yang digabungkan akan menggagalkan keseluruhan pengumpulan: nilai menjadi `U`, tiada GPU ditandakan tidak hadir, dan GPU Nodes menunjukkan sebabnya.
- **Pengumpul selepas setiap kitaran peninjau.** Ia membaca ejen setiap nod yang didayakan, mengemas kini rekod identiti, dan mengcache sampel terkini. Input pelayan skrip hanya membaca cache tersebut, jadi tinjauan Cacti tidak pernah menunggu nod GPU.
- **Objek Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Templat dan pertanyaan data *GPU - Devices* dibekalkan sebagai XML dan diimport semasa pemasangan dan naik taraf. Menyimpan nod melampirkan pertanyaan data pada peranti sedia ada dengan kaedah pengindeksan semula None; GPU Nodes boleh mencipta peranti hanya apabila nod tiada peranti. Setiap GPU ialah indeks yang dikunci mengikut vendor dan UUID, dengan satu sumber data yang dikongsi oleh graf Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages, dan Sample Age. Setiap medan model dilukis oleh sesuatu graf, kerana Cacti hanya menyimpan medan yang digunakan oleh graf. Pemalam mengindeks semula selepas setiap perubahan GPU, menyimpan pengindeksan semula yang dilangkau oleh Cacti semasa peranti berada dalam keadaan Down atau dinyahdayakan dan menjalankannya sebaik sahaja peranti berada dalam keadaan Up, serta mencipta graf untuk GPU baharu.
- **Sejarah kekal bersama kad.** GPU yang tidak hadir kekal sebagai indeks, jadi grafnya menunjukkan jurang dan bersambung semula dalam fail RRD yang sama apabila ia kembali. Kad gantian dalam slot yang sama mendapat sumber datanya sendiri. **Remove from Data Query** pada halaman nod mengeluarkan GPU yang tidak hadir daripada penyenaraian; fail RRD dan grafnya disimpan sehingga anda memadamkannya.
- **Kebenaran peranti pada GPU Nodes.** GPU Admin melihat, mengubah, mengeksport, dan memindahkan hanya nod dan GPU pada peranti yang boleh mereka lihat dalam Cacti; pemindahan GPU memerlukan kedua-dua nod. Nod yang perantinya telah dipadamkan boleh dinyahdayakan atau dipadamkan.
- **Model metrik umum** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Memori adalah dalam bait, topeng peristiwa jam dinyahkod menjadi penunjuk `thr_*`, dan `age` ialah umur masa sampel GPU itu sendiri, atau `U` apabila tidak diketahui.

Pengguna yang memasang (pelayan web) mesti boleh menulis ke `<cacti>/resource/script_server/`, tempat pemalam meletakkan XML pertanyaan datanya; pemasangan dan naik taraf berhenti dengan mesej jika tidak dan mencuba semula pada percubaan seterusnya. Penyahpasangan membuang templat dan XML hanya apabila *Remove the plugin's Cacti objects on uninstall* ditandakan, dan tidak pernah memadamkan fail RRD, peranti, graf, atau sumber data ([docs/operations.md](../../operations.md#uninstall)). Nod pada pengumpul data jauh tidak dikumpul sehingga fasa 3.

## Perkara yang dibekalkan oleh pencapaian 2

- **Pemasangan terkawal.** Pada PHP di bawah 8.2, pemasangan dan pendayaan ditolak dengan mesej yang menamakan versi yang diperlukan, dan bahagian lain Cacti terus berfungsi. `setup.php` dan skrip halaman dihuraikan pada PHP 8.0.
- **Kitaran hayat.** Pasang, naik taraf, nyahdaya, dan nyahpasang melalui halaman Plugins Cacti. Naik taraf adalah tersusun, selesai selepas gangguan, dan menukar versi yang direkodkan hanya selepas ia berjaya. Penyahpasangan mengekalkan jadual pemalam melainkan anda mengosongkan *Keep Plugin Tables on Uninstall*; ia tidak pernah menyentuh peranti, sumber data, graf, atau fail RRD Cacti.
- **Alam berasingan.** *GPU Admin* membuka **GPU Nodes**, tempat anda mendaftarkan peranti Cacti sebagai nod GPU, mengesahkan atau menolak pemindahan GPU antara nod, dan mengeksport setiap rekod sebagai JSON. *GPU View* membuka **GPU Inventory**, yang menyenaraikan GPU hanya pada peranti yang boleh dilihat oleh penonton. Kedua-dua halaman berada di bawah Management, dan setiap perubahan ialah POST yang disemak oleh csrf-magic.
- **Rekod identiti GPU.** Setiap GPU dikunci mengikut vendor dan UUID. Indeks, ID bas PCI, nama, pemacu, dan alatan pelaporan ialah atribut yang berubah di tempatnya. UUID baharu dalam slot yang diketahui ditandakan sebagai kemungkinan penggantian dan memulakan sejarahnya sendiri; GPU yang dilihat pada nod lain berpindah hanya apabila pengendali mengesahkan; GPU yang hilang daripada pengumpulan yang berjaya ditandakan tidak hadir selepas kiraan boleh dikonfigurasi dan tidak pernah dipadamkan. Hanya UUID keseluruhan GPU NVIDIA diterima buat masa ini; kejadian MIG menunggu keputusan #13, dan AMD pada penyelidikan #19. Peraturan dijalankan oleh jujukan lekapan, memandangkan belum ada pengumpul yang menyuapkannya.
- **Ujian dan CI.** Suit unit dan peraturan sumber tanpa GPU atau pangkalan data, semakan kitaran hayat dan kebenaran halaman terhadap Cacti 1.2.31 dan 1.2.x semasa pada PHP 8.2 hingga 8.5, tugasan penolakan PHP 8.1, lint PHP 8.0 bagi titik masuk, PHPStan pada tahap 9, dan REUSE. Abah-abah adalah sementara sehingga #12 diputuskan.

### Pemasangan untuk penilaian

Langkah-langkah ini memasang kod semasa untuk mencubanya; [docs/operations.md](../../operations.md) mengandungi panduan pengendali penuh. Ia memerlukan Cacti 1.2.31 atau lebih baharu pada PHP 8.2 atau lebih baharu.

1. Salin repositori ke `plugins/gpu` di bawah direktori Cacti anda. Direktori tersebut mestilah dinamakan `gpu`.
2. Dalam Cacti, buka **Configuration > Plugins**, kemudian pasang dan dayakan **GPU**.
3. Berikan pengguna alam *GPU View*, *GPU Operator*, atau *GPU Admin* di bawah **Configuration > Users**, mengikut peranan mereka. Pengguna yang memasang menerima View dan Admin; Admin merangkumi akses Operator.
4. Sediakan ejen SNMP setiap nod GPU seperti yang diterangkan dalam [docs/node-setup.md](../../node-setup.md), kemudian tambah nod di bawah **Management > GPU Nodes**.
5. Untuk amaran, import templat Thold yang dibekalkan seperti yang diterangkan oleh [docs/alerting.md](../../alerting.md).
6. Secara pilihan, laraskan **Configuration > Settings > GPU**: *Mark Absent After* (lalai 3 pengumpulan berjaya, dari 1 hingga 100), *Stale After* (berapa lama sesuatu sampel boleh bertahan sebelum nilainya mencetak `U`), dan *Keep Plugin Tables on Uninstall* (lalai aktif).

## Cara metrik sampai ke Cacti

Keputusan [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) memilih SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): ejen nod itu sendiri menjalankan arahan `nvidia-smi` tetap dan pemalam hanya membaca hasilnya. Pelan ini membandingkan pilihan tersebut di bawah [Laluan pengumpulan](../../PLAN.md#collection-paths).

## Sasaran keserasian

| Komponen | Sasaran |
|---|---|
| Cacti | 1.2.31 atau lebih baharu, tiada tampalan teras |
| PHP | 8.2 atau lebih baharu; `setup.php` kekal boleh dihuraikan pada 8.0 supaya masa jalan yang tidak disokong mendapat penolakan dan bukannya UI Cacti yang rosak |
| NVIDIA | Baris pemacu dan alatan akan ditetapkan oleh penyelidikan [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26); ID medan DCGM disemak pada v3.3.9 dan v4.7.0 |
| AMD | `amd-smi` pada keluaran ROCm akan ditetapkan oleh penyelidikan [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 dan Maint 1.3, tidak diubah, diuji hujung ke hujung ([docs/alerting.md](../../alerting.md)) |

## Reka bentuk

- [docs/PLAN.md](../../PLAN.md) ialah pelan penuh: seni bina, fakta huluan yang menjadi sandarannya (berserta sumber), model identiti, sempadan keselamatan, aritmetik prestasi, pengujian, dan risiko.
- [docs/decisions/](../../decisions) menyimpan rekod keputusan seni bina bagi pilihan yang telah dibuat.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) memetakan setiap ID keperluan kepada isunya.

```mermaid
flowchart LR
    NODE[Nod GPU: snmpd extend menjalankan nvidia-smi] -->|Bacaan SNMP, v3 disyorkan| C[Pengumpul]
    C --> CACHE[(Cache pemalam dikunci mengikut UUID GPU)]
    CACHE --> SS[Input pertanyaan data pelayan skrip]
    SS --> RRD[(Fail RRD Cacti, satu sumber data setiap GPU)]
    SS -->|poller_output| T[Thold dan Maint]
    RRD --> G[Graf dan kebenaran Cacti]
```

## Cara kerja dijejaki

- **Pencapaian** mengikut pencapaian pelan. Setiap satu mempunyai isu epik yang menyenaraikan sub-isu dan bukti keluarannya.
- **Isu** ditaip mengikut label: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Isu keperluan menyatakan keperluan bernombor yang boleh diuji (`REQ-AREA-NNN`) dan kriteria penerimaan.
- **Label** turut membawa `area:`, `phase:`, `priority:`, dan `status:`. Lihat [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Papan projek](https://github.com/users/somethingwithproof/projects/9) menunjukkan semua isu merentasi pencapaian.

## Menyumbang

Baca [CONTRIBUTING.md](../../../CONTRIBUTING.md). Komit memerlukan tandatangan DCO. Perbincangan reka bentuk berlaku pada isu; keputusan terbuka membawa label `type: decision`. Output yang dirakam daripada alatan GPU sebenar ialah sumbangan paling berguna kepada pencapaian 1; buang nama hos, nombor siri, dan UUID terlebih dahulu.

## Keselamatan

Laporkan kelemahan secara peribadi; lihat [SECURITY.md](../../../SECURITY.md).

## Lesen

GNU General Public License v2.0, lesen yang sama seperti Cacti dan pemalamnya. Lihat [LICENSE](../../../LICENSE).
