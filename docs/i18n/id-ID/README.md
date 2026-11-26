<!-- Source: README.md; SHA256: 126deef1783d64f878bf565f1433c10f31aef828eb9bc313aeff2b0335e54c7c -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Bahasa Indonesia](README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Plugin Cacti 1.2 yang menghadirkan data utilisasi, memori, suhu, daya, galat ECC, dan alasan throttling per GPU dari perkakas NVIDIA ke dalam Cacti. Dukungan AMD dan pengumpulan galat XID masih direncanakan dan belum diimplementasikan. Setiap GPU menjadi indeks dari suatu kueri data pada perangkat node-nya, sehingga grafik GPU ditempatkan berdampingan dengan grafik lain milik node tersebut di bawah sistem izin Cacti, serta Thold dan Maint dapat memberikan peringatan dan menekan (suppress) peringatan tersebut.

> **Status: dalam pengembangan, belum dirilis.** Tonggak pencapaian (milestone) 4 membuat fase 1 (NVIDIA melalui SNMP extend) selesai secara kode: pengumpulan data, identitas, grafik, sinyal throttle dan ECC, peringatan Thold dan Maint, halaman kesehatan, serta dokumentasi operator. Plugin ini baru dijalankan terhadap `snmpd` asli dengan `nvidia-smi` tiruan yang mencetak keluaran sintetis, dan belum pernah pada perangkat keras GPU yang sebenarnya. Hal yang masih terbuka sebelum rilis: uji coba pada perangkat keras asli ([docs/pilot-plan.md](../../pilot-plan.md), #54), tinjauan keamanan eksternal ([docs/security-review.md](../../security-review.md)), fixture yang diambil dari perkakas asli (#14), serta pengukuran skala pada perangkat keras berukuran produksi ([docs/performance.md](../../performance.md)). Galat XID menunggu keputusan #8. Jangan jalankan di lingkungan produksi.

## Peran akses

| Realm | Akses |
| --- | --- |
| GPU View | Halaman pemantauan yang sudah ada. |
| GPU Operator | Pemantauan ditambah halaman Operations dan pengakuan (acknowledgment) triase pribadi. |
| GPU Admin | Akses Operator ditambah konfigurasi yang sudah ada dan tindakan administratif. |

Setelah menyalin berkas pemutakhiran, buka halaman Plugins sebagai administrator untuk mendaftarkan
realm baru. Tetapkan realm Operator secara eksplisit di bawah **Configuration > Users** atau grup pengguna.
Pemutakhiran mempertahankan ID dan hak akses (grant) realm View dan Admin yang sudah ada. Akses Admin
mewarisi akses Operator, dan akses Operator mewarisi akses View pada setiap permintaan;
hal ini tidak menambahkan grant tersimpan ke pengguna atau grup. Pengaturan plugin juga memerlukan
akses GPU Admin selain izin Settings milik Cacti.

Izin perangkat Cacti tetap membatasi baris dan tindakan yang tersedia bagi setiap pengguna; objek yang tidak terpetakan memerlukan seorang administrator.
Kredensial, konfigurasi, pemetaan, penghapusan, dan perubahan administratif lainnya
tetap berada di halaman administratif. Pengakuan (acknowledgment) hanya bertahan dalam sesi
pengguna saat ini, paling lama satu jam dan hanya selama pengamatan yang sama tetap
baru. Pengamatan yang baru atau berubah akan membatalkannya. Pengakuan tidak pernah menandai pengukuran
yang gagal sebagai sehat atau menekan (suppress) peringatan.

## Mengapa

Node GPU biasanya sudah ada di Cacti untuk pemantauan CPU, memori, dan antarmuka jaringan. Kesehatan GPU merupakan hal terpisah yang tidak dijawab oleh data SNMP node tersebut: sebuah kartu dapat mengalami pembatasan termal (thermal throttling), mengakumulasi galat ECC, atau melaporkan kegagalan XID sementara node itu sendiri tampak sehat. Perkakas vendor melaporkannya (`nvidia-smi`, DCGM, `amd-smi`), tetapi keluarannya berada di luar grafik, izin, dan sistem peringatan Cacti.

Plugin ini menutup celah tersebut dengan mengubah keluaran perkakas vendor menjadi sumber data Cacti, yang dikunci berdasarkan identitas masing-masing GPU alih-alih posisinya, sehingga boot ulang yang mengurutkan ulang GPU tidak menempelkan riwayat satu kartu ke kartu lainnya. Plugin ini menggunakan kembali sistem grafik, izin, kolektor data jarak jauh, Thold, dan Maint milik Cacti daripada membangun versi paralelnya.

## Ruang lingkup yang direncanakan

| Fase | Ruang lingkup | Tonggak pencapaian |
|---|---|---|
| 0, riset | Keluaran perkakas vendor, sinyal XID dan throttle, identitas GPU, hak akses, keputusan jalur pengumpulan | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Pengumpulan `nvidia-smi`, inventaris menurut UUID, grafik, sinyal throttle, ECC dan XID, Thold dan Maint, halaman kesehatan | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, backend | Backend DCGM, backend `amd-smi` | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig dan jarak jauh | Instans MIG, kolektor data jarak jauh Cacti | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Di luar ruang lingkup: perintah apa pun yang mengubah GPU atau node (clock, batas daya, reset, tata letak MIG), mesin peringatan milik plugin sendiri, daemon atau protokol jaringan kustom, serta penjadwal atau akuntansi pekerjaan (job accounting). Lihat rencana untuk daftar pekerjaan riset yang tertunda (backlog).

## Hal yang ditambahkan pada tonggak pencapaian 4

- **Alasan throttle sebagai nilai peringatan.** Mask peristiwa clock didekode menjadi satu indikator 0 atau 1 per alasan; mask yang tidak dikenal menghasilkan indikator yang tidak diketahui, tidak pernah nol, dan bit tanpa indikator tetap berada dalam mask mentah yang ditampilkan oleh GPU Health.
- **ECC sebagai nilai peringatan** ([docs/metrics.md](../../metrics.md)). Galat terkoreksi dan tidak terkoreksi yang baru sejak sampel sebelumnya dihitung oleh plugin dari dua total berturut-turut dan disimpan sebagai gauge: tidak diketahui untuk sampel pertama, pembacaan yang terlewat, atau muat ulang driver, sehingga reset tidak pernah terbaca sebagai nol atau sebagai hitungan negatif. Jumlah halaman single-bit dan double-bit yang dipensiunkan serta mode ECC juga dibaca. Dua grafik ECC tambahan per GPU memungkinkan Cacti menyimpannya; bersama Power Percent, terdapat sebelas grafik secara keseluruhan. XID tidak dikumpulkan: tidak ada sumber yang sesuai dengan perintah tetap `nvidia-smi` (#8).
- **Thold dan Maint** ([docs/alerting.md](../../alerting.md)). Templat Thold bawaan untuk suhu, daya terhadap batasnya, indikator throttle, galat ECC baru yang tidak terkoreksi, penangguhan pensiun halaman, dan usia sampel. Setiap nilai berupa gauge, karena Thold 1.8.2 gagal pada counter yang tidak diketahui. Pengujian ujung-ke-ujung dengan Thold 1.8.2 dan Maint 1.3 tanpa modifikasi menunjukkan setiap ambang batas memicu dan pulih, melintasi reset ECC, serta Maint menekan peringatan tersebut.
- **GPU Health** (GPU View). Per node: kesegaran data, bagaimana pengumpulan terakhir berakhir, status Maint, backend, versi perkakas dan driver, serta jumlah GPU; per GPU: keberadaan, usia sampel, status ECC, dan mask mentah. Halaman ini hanya membaca catatan yang tersimpan.
- **Izin.** Rangkaian pengujian izin mencakup setiap berkas halaman dan tindakan, pencabutan pada permintaan berikutnya, serta penolakan yang tidak membocorkan informasi apa pun.
- **Kualifikasi kegagalan dan pemulihan.** Eksekusi ujung-ke-ujung berskrip memutus agen, kredensial, perkakas, keluarannya, koneksi basis data, dan penulisan RRD, serta memulihkan basis data dari dump, termasuk dump yang lebih lama daripada berkas RRD; GPU baru tidak pernah mewarisi berkas RRD milik GPU lain ([docs/backup.md](../../backup.md)).
- **Skala** ([docs/performance.md](../../performance.md)). Pengujian skala mengukur waktu pengumpulan, input server skrip, pembuatan objek Cacti, siklus poller, dan halaman kesehatan pada jumlah GPU tertentu; CI menjalankannya pada 100 GPU. Pengukuran untuk 1.000 dan 8.000 GPU belum tercatat, sehingga belum ada jumlah GPU yang didukung secara resmi.
- **Dokumentasi operator** ([docs/operations.md](../../operations.md)): pemasangan, pemutakhiran, pencopotan pemasangan, hak istimewa minimal (least privilege), nilai tidak tersedia, dan pemecahan masalah. Daftar perubahan tercantum dalam [CHANGELOG.md](../../../CHANGELOG.md).

## Hal yang ditambahkan pada tonggak pencapaian 3

- **Pengumpulan melalui SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Administrator node menambahkan dua baris `extend` tetap ke `snmpd.conf` ([docs/node-setup.md](../../node-setup.md)); plugin membaca NET-SNMP-EXTEND-MIB melalui fungsi SNMP Cacti dengan kredensial milik perangkat itu sendiri, dengan SNMPv3 yang disarankan. Plugin ini tidak menjalankan apa pun di server Cacti maupun di node.
- **Penguraian ketat (strict parsing).** Bidang dibaca berdasarkan posisi, setiap baris menamai GPU-nya dengan UUID, dan status keluar bukan nol, batas waktu terlampaui (timeout), keluaran terpotong, baris dengan jumlah bidang yang salah, atau teks galat yang tercampur akan menggagalkan seluruh pengumpulan: nilai menjadi `U`, tidak ada GPU yang ditandai tidak hadir, dan GPU Nodes menunjukkan penyebabnya.
- **Kolektor setelah setiap siklus poller.** Kolektor membaca agen setiap node yang diaktifkan, memperbarui catatan identitas, dan menyinggahkannya (cache) sebagai sampel terbaru. Input server skrip hanya membaca singgahan tersebut, sehingga proses poll Cacti tidak pernah menunggu node GPU.
- **Objek Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Templat dan kueri data *GPU - Devices* dikirimkan sebagai XML dan diimpor saat pemasangan dan pemutakhiran. Menyimpan sebuah node akan menempelkan kueri data ke perangkat yang sudah ada dengan metode pengindeksan ulang None; GPU Nodes dapat membuat perangkat hanya ketika node belum memilikinya. Setiap GPU adalah indeks yang dikunci oleh vendor dan UUID, dengan satu sumber data yang dibagi oleh grafik Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages, dan Sample Age. Setiap bidang model digambarkan oleh suatu grafik, karena Cacti hanya menyimpan bidang yang digunakan oleh grafik. Plugin melakukan pengindeksan ulang setelah setiap perubahan GPU, menyimpan pengindeksan ulang yang dilewati Cacti saat perangkat dalam status Down atau dinonaktifkan dan menjalankannya begitu perangkat Up, serta membuat grafik untuk GPU baru.
- **Riwayat tetap melekat pada kartu.** GPU yang tidak hadir tetap menjadi indeks, sehingga grafiknya menunjukkan jeda dan berlanjut dalam berkas RRD yang sama saat GPU tersebut kembali. Kartu pengganti pada slot yang sama mendapatkan sumber datanya sendiri. **Remove from Data Query** pada halaman node menghapus GPU yang tidak hadir dari daftar; berkas RRD dan grafiknya tetap disimpan sampai Anda menghapusnya.
- **Izin perangkat pada GPU Nodes.** Seorang GPU Admin hanya melihat, mengubah, mengekspor, dan memindahkan node serta GPU pada perangkat yang boleh mereka lihat di Cacti; pemindahan GPU memerlukan kedua node. Node yang perangkatnya telah dihapus dapat dinonaktifkan atau dihapus.
- **Model metrik umum** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Memori dalam satuan byte, mask peristiwa clock didekode menjadi indikator `thr_*`, dan `age` adalah usia waktu sampel milik GPU itu sendiri, atau `U` jika tidak diketahui.

Pengguna yang memasang (server web) harus dapat menulis ke `<cacti>/resource/script_server/`, tempat plugin meletakkan XML kueri datanya; jika tidak, pemasangan dan pemutakhiran akan berhenti dengan pesan kesalahan dan mencoba kembali pada upaya berikutnya. Pencopotan pemasangan menghapus templat dan XML hanya jika *Remove the plugin's Cacti objects on uninstall* dicentang, dan tidak pernah menghapus berkas RRD, perangkat, grafik, maupun sumber data ([docs/operations.md](../../operations.md#uninstall)). Node pada kolektor data jarak jauh belum dikumpulkan hingga fase 3.

## Hal yang dikirimkan pada tonggak pencapaian 2

- **Pemasangan terproteksi.** Pada PHP di bawah 8.2, pemasangan dan pengaktifan ditolak dengan pesan yang menyebutkan versi yang diperlukan, sementara bagian Cacti lainnya tetap berjalan. `setup.php` dan skrip halaman tetap dapat diurai (parse) pada PHP 8.0.
- **Siklus hidup.** Pemasangan, pemutakhiran, penonaktifan, dan pencopotan pemasangan melalui halaman Plugins Cacti. Pemutakhiran dijalankan secara berurutan, diselesaikan setelah terjadi interupsi, dan mengubah versi tercatat hanya setelah berhasil. Pencopotan pemasangan mempertahankan tabel plugin kecuali Anda menghapus centang *Keep Plugin Tables on Uninstall*; tindakan ini tidak pernah menyentuh perangkat, sumber data, grafik, atau berkas RRD Cacti.
- **Realm terpisah.** *GPU Admin* membuka **GPU Nodes**, tempat Anda mendaftarkan perangkat Cacti sebagai node GPU, mengonfirmasi atau menolak pemindahan GPU antar-node, dan mengekspor setiap catatan sebagai JSON. *GPU View* membuka **GPU Inventory**, yang hanya mencantumkan GPU pada perangkat yang boleh dilihat oleh pemirsa. Kedua halaman berada di bawah Management, dan setiap perubahan berupa POST yang diperiksa oleh csrf-magic.
- **Catatan identitas GPU.** Setiap GPU dikunci oleh vendor dan UUID. Indeks, ID bus PCI, nama, driver, dan perkakas pelapor merupakan atribut yang berubah di tempat. UUID baru pada slot yang diketahui ditandai sebagai kemungkinan penggantian dan memulai riwayatnya sendiri; GPU yang terlihat di node lain berpindah hanya ketika operator mengonfirmasi; GPU yang hilang dari pengumpulan yang berhasil ditandai tidak hadir setelah jumlah yang dapat dikonfigurasi dan tidak pernah dihapus. Untuk saat ini hanya UUID GPU utuh NVIDIA yang diterima; instans MIG menunggu keputusan #13, dan AMD menunggu riset #19. Aturan-aturan ini diuji dengan rangkaian fixture, karena belum ada kolektor yang memasok datanya.
- **Pengujian dan CI.** Rangkaian pengujian unit dan aturan sumber tanpa GPU atau basis data, pemeriksaan siklus hidup dan izin halaman terhadap Cacti 1.2.31 serta 1.2.x terkini pada PHP 8.2 hingga 8.5, tugas penolakan PHP 8.1, lint PHP 8.0 untuk titik masuk, PHPStan pada level 9, dan REUSE. Rangkaian pengujian ini bersifat sementara hingga #12 diputuskan.

### Pemasangan untuk evaluasi

Langkah-langkah berikut memasang kode saat ini untuk dicoba; [docs/operations.md](../../operations.md) berisi panduan operator lengkap. Langkah ini memerlukan Cacti 1.2.31 atau yang lebih baru pada PHP 8.2 atau yang lebih baru.

1. Salin repositori ke `plugins/gpu` di bawah direktori Cacti Anda. Direktori harus bernama `gpu`.
2. Di Cacti, buka **Configuration > Plugins**, lalu pasang dan aktifkan **GPU**.
3. Berikan realm *GPU View*, *GPU Operator*, atau *GPU Admin* kepada pengguna di bawah **Configuration > Users**, sesuai dengan peran mereka. Pengguna yang memasang menerima View dan Admin; Admin mencakup akses Operator.
4. Siapkan agen SNMP setiap node GPU sebagaimana dijelaskan dalam [docs/node-setup.md](../../node-setup.md), lalu tambahkan node tersebut di bawah **Management > GPU Nodes**.
5. Untuk peringatan, impor templat Thold bawaan seperti yang dijelaskan oleh [docs/alerting.md](../../alerting.md).
6. Secara opsional sesuaikan **Configuration > Settings > GPU**: *Mark Absent After* (bawaan 3 pengumpulan berhasil, dari 1 hingga 100), *Stale After* (berapa lama usia sampel sebelum nilainya mencetak `U`), dan *Keep Plugin Tables on Uninstall* (bawaan aktif).

## Bagaimana metrik mencapai Cacti

Keputusan [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) memilih SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): agen milik node itu sendiri menjalankan perintah tetap `nvidia-smi` dan plugin hanya membaca hasilnya. Rencana membandingkan opsi-opsi tersebut di bawah [Jalur pengumpulan](../../PLAN.md#collection-paths).

## Target kompatibilitas

| Komponen | Target |
|---|---|
| Cacti | 1.2.31 atau yang lebih baru, tanpa patch inti |
| PHP | 8.2 atau yang lebih baru; `setup.php` tetap dapat diurai pada 8.0 agar runtime yang tidak didukung mendapatkan penolakan alih-alih UI Cacti yang rusak |
| NVIDIA | Lini driver dan perkakas akan ditetapkan oleh riset [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26); ID bidang DCGM diperiksa pada v3.3.9 dan v4.7.0 |
| AMD | `amd-smi` pada rilis ROCm yang akan ditetapkan oleh riset [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 dan Maint 1.3, tanpa modifikasi, diuji ujung-ke-ujung ([docs/alerting.md](../../alerting.md)) |

## Desain

- [docs/PLAN.md](../../PLAN.md) adalah rencana lengkapnya: arsitektur, fakta hulu (upstream) yang menjadi landasannya (beserta sumber), model identitas, batas keamanan, perhitungan performa, pengujian, dan risiko.
- [docs/decisions/](../../decisions) menyimpan catatan keputusan arsitektur untuk pilihan yang sudah dibuat.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) memetakan setiap ID persyaratan ke isu terkait.

```mermaid
flowchart LR
    NODE[Node GPU: snmpd extend menjalankan nvidia-smi] -->|Pembacaan SNMP, v3 disarankan| C[Kolektor]
    C --> CACHE[(Singgahan plugin dengan kunci UUID GPU)]
    CACHE --> SS[Input server skrip kueri data]
    SS --> RRD[(Berkas RRD Cacti, satu sumber data per GPU)]
    SS -->|poller_output| T[Thold dan Maint]
    RRD --> G[Grafik dan izin Cacti]
```

## Bagaimana pekerjaan dilacak

- **Tonggak pencapaian** mengikuti tonggak pencapaian rencana. Masing-masing memiliki isu epik yang mencantumkan sub-isu dan bukti penyelesaiannya.
- **Isu** diberi tipe menurut label: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Isu persyaratan menyatakan persyaratan bernomor yang dapat diuji (`REQ-AREA-NNN`) dan kriteria penerimaan.
- **Label** juga memuat `area:`, `phase:`, `priority:`, dan `status:`. Lihat [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Papan proyek](https://github.com/users/somethingwithproof/projects/9) menampilkan semua isu di seluruh tonggak pencapaian.

## Berkontribusi

Baca [CONTRIBUTING.md](../../../CONTRIBUTING.md). Komit memerlukan tanda tangan DCO (DCO sign-off). Diskusi desain berlangsung di isu; keputusan yang masih terbuka menggunakan label `type: decision`. Keluaran tangkapan dari perkakas GPU asli merupakan kontribusi paling bermanfaat untuk tonggak pencapaian 1; hapus nama host, nomor seri, dan UUID terlebih dahulu.

## Keamanan

Laporkan kerentanan secara privat; lihat [SECURITY.md](../../../SECURITY.md).

## Lisensi

GNU General Public License v2.0, lisensi yang sama dengan Cacti dan pluginnya. Lihat [LICENSE](../../../LICENSE).
