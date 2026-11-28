# Catatan perubahan ANTAREJA NMS

## 0.5.0

Pembaruan dari 0.4.0: ganti image lalu jalankan ulang; migrasi 0030 sampai 0032 otomatis. Tidak ada port baru di
server pusat. Collector site memakai image collector yang sama (lihat `deploy/site`).

- **Collector site**: collector kecil di site tambang mengukur ping dan SNMP perangkat lokal lalu mengirim ke pusat
  lewat HTTPS. Saat jalur ke pusat putus, hasil diantrekan di disk site dan dikirim berurutan saat pulih, jadi
  kejadian down/up tetap tercatat dengan jam aslinya dan perangkat site tidak ikut dinyatakan down karena jalurnya.
  Peringatan sistem bila collector site diam lebih dari 5 menit. Menu *Administrasi, Collector site*.
- **Verifikasi dua langkah (TOTP)** lewat aplikasi autentikator, 10 kode cadangan sekali pakai, kebijakan wajib untuk
  admin atau semua akun, reset oleh admin.
- **Login LDAP / Active Directory** (opsional): akun dibuat otomatis, peran dari grup direktori; akun lokal tetap bisa
  masuk walau server direktori mati.
- **Flow trafik** (NetFlow v5, v9, IPFIX di 2055/UDP; sFlow v5 di 6343/UDP): siapa dan aplikasi apa yang memakai
  bandwidth per port dan arah, ringkasan 5 menit (50 percakapan terbesar + lainnya), disimpan 7 hari; pemakai terbesar
  di halaman port. Hanya dari perangkat terdaftar. **Buka 2055/UDP dan 6343/UDP** dari subnet manajemen bila dipakai.
- **Profil login kamera dan tambah CCTV massal** (CCTV, Kelola): user, password, port, dan path RTSP disimpan
  sebagai profil lalu dipilih saat menambah atau mengubah kamera; ganti password profil langsung berlaku untuk semua
  kameranya. *Tambah banyak dari perangkat*: centang perangkat berkategori Kamera CCTV yang belum jadi kamera
  (bisa disaring nama/IP), pilih profil, semua ditambahkan sekaligus dengan nama, IP, dan site dari perangkat.
  Profil ikut ekspor/impor (password hanya di bagian rahasia). Migrasi 0049.
- **Impor dari Zabbix**: halaman Impor perangkat menerima ekspor host Zabbix (JSON dari Data collection, Hosts, Export).
  Nama, IP atau DNS, status aktif/nonaktif, SNMP (versi, port, community termasuk makro host; nilai makro global
  `{$SNMP_COMMUNITY}` bisa diisi), kategori ditebak dari templat dan awalan nama, grup/templat/deskripsi Zabbix
  masuk catatan. IP yang dipakai dua host: yang aktif diambil. Host tanpa IP (VMware, agent) dilewati. Pratinjau dulu.
- **SLA link ISP** (Laporan, *SLA link ISP*): laporan per link internet/Metro-E dari ping ANTAREJA. Gangguan = ping
  gagal atau packet loss di atas ambang link (bawaan 10%); maintenance bisa dikecualikan; waktu tanpa data tidak
  dihitung. Periode minggu lalu/ini, bulan lalu/ini, atau tanggal pilihan. Isi: ketersediaan dengan status Memenuhi /
  Peringatan / Tidak memenuhi (ambang per link, bawaan 98% dan 97%), waktu normal dan gangguan, latensi, jitter, loss
  rata-rata, jumlah sampel ping, tren harian, grafik detail dengan pita gangguan, daftar insiden (mulai, pulih, durasi,
  penyebab), tabel per hari, CSV dan cetak. Link diatur engineer (Atur link). Migrasi 0048.
- **Ubah kamera CCTV**: form ubah di Kelola kamera tidak lagi berupa popup yang terpotong; tombol *Ubah* membuka form
  lengkap di kartu samping (juga perangkat dan site bisa diganti), baris yang sedang diubah ditandai.
- **VLAN dari interface router**: halaman VLAN & STP kini juga mengenali VLAN yang berupa interface (MikroTik
  `/interface vlan`, Cisco `Vlan20` atau subinterface `Gi0/0.20`) lewat tipe interface l2vlan (135) / l3ipvlan (136)
  atau pola nama, bukan hanya tabel VLAN switch. Tabel VLAN switch dan STP kini dibaca juga dari perangkat kategori
  Firewall dan semua MikroTik. Ada daftar perangkat SNMP yang belum melaporkan VLAN beserta hal yang perlu dicek.
- **Grafik flow**: halaman Flow trafik punya grafik bertumpuk per aplikasi, IP sumber, atau IP tujuan (8 teratas +
  lainnya). Riwayat dari ringkasan 5 menit sesuai rentang; mode *Live* menampilkan potongan 10 detik selama 5 menit
  terakhir, diperbarui tiap 10 detik. Data live hanya disimpan di memori collector untuk port yang sedang dibuka
  (maks. 50 port, berhenti 2 menit setelah tidak dilihat). Untuk NetFlow/IPFIX set active timeout router 1 menit.
- **Templat perangkat dan makro** (Pemantauan, *Templat*): satu set ambang, makro, URL exporter dan layanannya, cek
  layanan, dan trigger (cakupan *Perangkat bertemplat*) dipasang ke banyak perangkat lewat form perangkat atau Ubah
  massal, *Pasang templat*. Ubah templat sekali, semua perangkatnya ikut. Ambang perangkat sendiri menang. Makro
  `{HOST}`, `{NAME}`, `{SITE}`, dan `{$NAMA}` (nilai templat, bisa ditimpa per perangkat) dipakai di cek dan URL
  exporter. Ikut ekspor/impor bersama aturan log dan makro perangkat. Migrasi 0047.
- **Peta beban link**: pilihan *Peta beban* di Topologi mewarnai sambungan dengan skala pemakaian (di bawah 30%, 30
  sampai 50, 50 sampai 70, 70 sampai 90, 90% ke atas), menebalkan garis, dan menampilkan persentase masuk/keluar di
  tiap garis; pilihan diingat per browser. Garis di Peta lokasi memakai skala yang sama dengan keterangan pemakaian.
- **VLAN dan STP** (Pemantauan, *VLAN & STP*): VLAN per switch (Q-BRIDGE-MIB, atau VTP untuk Cisco) dengan penanda
  nama yang beda antar switch, VLAN bawaan dan status STP per port di halaman port, root bridge dan jumlah perubahan
  topologi per switch. Perubahan topologi dan pergantian root bridge jadi kejadian *STP berubah*; perubahan di 3
  pembacaan dalam 1 jam jadi problem *STP tidak stabil*. Migrasi 0046.
- **BGP dan OSPF** (Pemantauan, *Routing*; juga di detail perangkat): sesi BGP (BGP4-MIB) dan tetangga OSPF (OSPF-MIB)
  router Core/Distribution/Firewall dan MikroTik tiap putaran SNMP. Sesi BGP yang di-start tetapi tidak established
  dan tetangga OSPF yang tidak full/2-way jadi problem High; yang hilang dari tabel router tetap dipantau sampai
  dilupakan. Perubahan status jadi kejadian *Routing berubah*. Migrasi 0045.
- **Prediksi habis** (Laporan, *Prediksi habis*): kapan disk penuh (tren 7 hari) dan bahan bakar genset habis (sejak
  isi ulang terakhir), dihitung tiap jam dari VictoriaMetrics. Disk penuh dalam 7 hari (Warning) atau 2 hari (Average)
  dan bahan bakar habis dalam 24 jam (Average) atau 6 jam (High) menjadi problem. Migrasi 0044.
- **Templat pesan notifikasi** per kanal (Notifikasi, ubah kanal, *Templat pesan*): isi pesan problem baru dan pulih
  bisa diubah dengan penanda `{judul}`, `{site}`, `{dampak}`, `{runbook}`, dll.; baris yang penandanya kosong
  dibuang, baris pertama jadi subjek email. Tombol Uji mengirim contoh dengan templat. Migrasi 0043.
- **Aturan log** (Log, *Aturan log*): pola teks atau regex pada syslog, N kali dalam jendela waktu per perangkat,
  jadi problem *Pola log* dengan severity pilihan; bisa dibatasi tingkat log, program, site, atau perangkat; pulih
  sendiri setelah sepi. *Uji ke log 24 jam* sebelum disimpan. Regex memakai RE2 (tanpa lookaround). Migrasi 0042.
- **Akun terbatas per site** (Pengguna, ubah akun, *Site yang boleh dilihat*): viewer atau engineer hanya melihat
  Ringkasan, Problem, Perangkat, Sensor, dan Kejadian site terpilih; halaman lain ditolak. Engineer terbatas bisa
  ACK problem site-nya tetapi tidak mengubah perangkat. Admin selalu melihat semua site. Migrasi 0041.
- **Exporter Prometheus**: server Linux (node_exporter) dan Windows (windows_exporter) dipantau tanpa SNMP. CPU,
  memori, disk per mount atau drive, dan status layanan pilihan (unit systemd, layanan Windows) jadi sensor dan
  problem. Diatur di halaman perangkat, *Uji scrape* untuk mencoba, ikut ekspor/impor dan collector site. API
  sensor menerima `source=exporter`. Migrasi 0040. Belum diuji dengan exporter sungguhan di lingkungan pengembangan.
- **Versi & jam perangkat** (menu *Pemantauan, Versi & jam*): versi perangkat lunak, model, dan nomor seri dibaca
  tiap 24 jam (MikroTik dari MIKROTIK-MIB, lainnya dari ENTITY-MIB, IEEE802dot11-MIB, atau "Version" di sysDescr),
  dikelompokkan per model; perangkat yang versinya beda dari mayoritas modelnya ditandai. Kejadian *Versi berubah*
  saat perangkat di-upgrade. Selisih jam dari hrSystemDate tiap putaran SNMP jadi sensor *Selisih jam* (peringatan
  1 menit, kritis 5 menit). Migrasi 0039.
- **Sinyal 4G/LTE** modem MikroTik (RSRP, RSRQ, SINR, RSSI) di halaman Sensor tab Radio, jadi problem bila di bawah
  ambang, bisa dipakai di trigger. Modem Teltonika belum didukung (MIB belum terverifikasi).
- **Uji beban 1.012 perangkat** (lihat `docs/UJI-BEBAN.md`): ping 2,7 detik, SNMP 46,5 detik per putaran di 4 vCPU.
  Pencocokan ulang tetangga LLDP/CDP ditulis ulang lewat indeks (migrasi 0037), puncak CPU database 211% → 96%.
  Log putaran ping dan SNMP kini mencantumkan durasi.
- **API**: `/api/v1/checks` (cek layanan, URL tanpa kredensial), `/api/v1/sensors` (SNMP & Modbus), `/api/v1/macs` (lokasi MAC).
- **Ekspor/impor konfigurasi** kini memuat collector site (tanpa token; collector hasil impor perlu *Ganti token*),
  penempatan perangkat ke collector, setelan dan titik Modbus, cek layanan, dan runbook.
- **Collector site menjalankan cek layanan dan Modbus** untuk perangkat miliknya (diantrekan saat jalur putus, kiriman
  ganda dilewati); konfigurasi dicoba ulang tiap 30 detik bila pusat belum terjangkau.
- **Modbus TCP (hanya baca)** untuk solar controller, baterai, genset, dan sensor daya di menara: titik baca per
  perangkat (register input/holding, tipe 16/32-bit dan float, skala, ambang warning/kritis), dibaca tiap menit dan
  menjadi sensor biasa (halaman Sensor tab Daya, grafik, problem, trigger). *Uji baca* dan salin titik antar perangkat.
- **Lacak MAC ke port switch**: tabel MAC switch Core/Distribution/Access (Q-BRIDGE-MIB, cadangan BRIDGE-MIB) dibaca
  tiap putaran SNMP; tiap MAC diberi lokasi port tepi (bukan uplink), dengan riwayat pindah port 90 hari. Tampil di
  pencarian IPAM, halaman port (*Terpasang di port ini*), dan halaman perangkat (*Terpasang di*).
- **Runbook** (menu *Runbook*): langkah penanganan per jenis problem, kategori, site, atau perangkat; yang paling
  spesifik dipakai. Tampil di daftar problem, halaman perangkat, dan lima langkah pertama ikut di pesan Telegram/email.
- **Cek layanan** (menu *Cek layanan*): HTTP(S) dengan kode status dan teks wajib, port TCP, dan DNS dari server
  pusat tiap 30 detik sampai 1 jam. Gagal dua kali berturut-turut menjadi problem (severity diatur per cek), lambat dan
  sertifikat HTTPS yang hampir habis menjadi Warning. Tombol *Uji sekarang*, kartu di halaman perangkat.
- **Peta dampak** (menu *Dampak*): untuk tiap perangkat down yang induknya tidak down, apa saja yang ikut terganggu
  (perangkat di bawahnya, kamera, layanan, site yang putus total), plus simulasi "bila perangkat ini mati" untuk
  rencana maintenance. Ringkasan dampak juga tampil di daftar problem, halaman perangkat, dan notifikasi problem down.
- **Ubah perangkat massal** di daftar Perangkat: site, induk, kategori, kredensial SNMP, collector, aktif/nonaktif, hapus.
- **Backup ke luar server**: SFTP atau S3/MinIO, retensi di tujuan, peringatan bila gagal.
- Perbaikan: login akun dengan password tersimpan bukan bcrypt (data impor) ditolak biasa, bukan error 500.
- Perbaikan: pengiriman notifikasi sekarang berjalan terpisah dari putaran ping. Sebelumnya kanal yang lambat atau
  tidak terjangkau (Telegram, webhook, SMTP) bisa menghabiskan jatah waktu putaran sehingga penilaian problem berikutnya
  gagal dan perangkat down atau pulih terlambat tercatat.
- Perbaikan: keterangan kamera di grid CCTV tidak lagi diawali koma untuk kamera tanpa site, dan nama perangkat tidak
  diulang bila sama dengan nama kamera (kamera hasil tambah massal).
- Teks layar, pesan notifikasi, dan laporan tidak lagi memakai titik tengah, panah, tanda pisah, atau elipsis.
- **Link radio Ubiquiti** (migrasi 0052 otomatis): collector membaca tabel station airMAX dan LTU serta status
  airFiber lewat SNMP. Hasilnya ada di menu *Link radio* dan di halaman perangkat: kartu *Link radio* di AP, dan
  *Tersambung lewat radio ke* di CPE. Lawan yang terdaftar dicocokkan lewat IP atau MAC lalu otomatis jadi garis
  di Topologi, berwarna sesuai sinyal (di bawah -70 dBm kuning, di bawah -78 dBm merah) dengan label dBm. Lawan yang
  belum terdaftar tampil sebagai kotak *baru*. Station yang lepas atau kembali dicatat di Kejadian.
  - Profil sensor baru **Ubiquiti airFiber**: daya terima rantai 0 dan 1, daya terima sisi lawan, kapasitas kirim
    dan terima, jarak, suhu radio, status link (putus = problem kritis). Semua perangkat SNMP dideteksi ulang sekali
    setelah pembaruan supaya airFiber yang sudah terdaftar langsung terbaca.
  - OID dari teks MIB Ubiquiti (UBNT-AirMAX-MIB, UBNT-AirFIBER-MIB, UBNT-AFLTU-MIB). Perlu dicek di perangkat
    asli: satuan rate station airMAX (dianggap Kbps), jarak station (dianggap meter), dan lama tersambung.
- **Cari kamera di halaman CCTV**: kolom cari menyaring kotak kamera saat mengetik (nama, site, perangkat, IP); Enter
  membuka live view bila tinggal satu. Halaman perangkat kamera punya tombol **Lihat CCTV** yang langsung membuka live
  view, dan daftar Perangkat menampilkan tombol *Lihat* di baris kamera. Sebelumnya klik dari hasil cari selalu ke
  halaman perangkat (ping dan grafik), bukan ke gambar kamera.
- **Cari di Kelola kamera**: saring daftar kamera menurut nama, IP, site, atau profil, dan menurut kondisi gambar
  (bermasalah, belum dicek, nonaktif). Saringan tetap ada setelah Ubah atau Simpan. Tiap baris punya tombol *Lihat*
  untuk live view.
- **Kontrol ISO/IEC 27001:2022** (pemetaan lengkap di `docs/ISO27001.md`). Migrasi 0050 dan 0051 otomatis.
  - Log audit tahan ubah: hash SHA-256 berantai per baris; database menolak ubah dan hapus kecuali pemangkasan
    sesuai batas simpan. Halaman *Jejak audit* punya saringan (pengguna, aksi, tanggal, teks), *Ekspor CSV* beserta
    hash, dan *Periksa keutuhan*. Logout dan keluar karena diam ikut tercatat. Kolom rahasia apa pun (`*_enc`,
    password, secret, token) tidak pernah tercatat.
  - Kebijakan baru di *Pengguna, Kebijakan login*: keluar otomatis bila admin/engineer diam (bawaan 30 menit),
    nonaktifkan akun tak dipakai (opsional), batas simpan log audit, pemberitahuan di halaman login.
  - *Tinjauan hak akses*: unduhan CSV semua akun (peran, site, 2FA, sumber login, terakhir aktif). Akun tidak dipakai
    lebih dari 90 hari ditandai. *Terakhir aktif* juga menghitung sesi "Ingat saya".
  - Salinan backup ke luar server bisa dienkripsi (AES-256-GCM, kunci Argon2id dari frasa sandi). Buka dengan
    `./antareja.sh decrypt`.
  - Data sesi dienkripsi (`SESSION_ENCRYPT=true`). **Semua pengguna perlu login sekali lagi setelah pembaruan.**
  - Pemindaian kerentanan dependensi di GitHub Actions (govulncheck, composer audit, npm audit), juga tiap minggu.
    Paket Symfony diperbarui ke 7.4.24 untuk menutup 5 CVE (routing, http-kernel, mailer).
    Collector dibangun dengan Go 1.26.11 (sebelumnya 1.25.0) serta `golang.org/x/net` 0.63 dan `x/crypto` 0.59, yang
    menutup kerentanan pustaka standar dan modul yang ditemukan govulncheck.
- Perbaikan Layar NOC: alarm, jumlah problem, dan grafik severity sebelumnya dihitung dari 30 problem teratas saja
  (tertulis "30 problem High/Disaster belum di-ACK" padahal ratusan). Sekarang dihitung dari semua problem terbuka.
- Layar NOC memeriksa perubahan tiap 5 detik lewat permintaan ringan dan langsung memuat ulang bila ada problem baru,
  ACK, pulih, atau perubahan status perangkat (sebelumnya tiap 15 detik). Problem baru 15 menit terakhir yang belum
  di-ACK selalu di urutan teratas walau severity-nya rendah; bunyi alarm untuk semua problem High/Disaster baru, bukan
  hanya yang masuk 20 teratas. Dinding status bergulir sendiri bila perangkat melebihi layar, site dengan perangkat
  down di atas. Umur problem di bawah semenit ditulis "dtk".

## 0.4.0

Pembaruan dari 0.3.0: ganti image lalu jalankan ulang; migrasi 0029 otomatis. Rekap 12 bulan ke belakang diisi
sendiri oleh collector dari data problem yang masih ada.

- **Down bulanan**: persen down per perangkat per bulan, total per site, CSV, API `/api/v1/downtime`; disimpan permanen.
- **Alat diagnosa** di halaman perangkat: ping, traceroute, cek port TCP dari collector ke perangkat itu.

## 0.3.0

Pembaruan dari 0.2.0: cukup ganti image lalu jalankan ulang (`./antareja.sh` atau `docker compose up -d`).
Migrasi database 0020 sampai 0028 dijalankan collector secara otomatis saat start.
Buka port **162/UDP** di firewall VM (hanya dari subnet manajemen perangkat) bila ingin memakai SNMP trap;
`TRAP_PORT` di `.env` opsional (bawaan 162).

### Operasi
- **Incident management**: problem yang berkaitan dikelompokkan jadi insiden, penanggung jawab, penyebab dan tindakan (RCA), linimasa.
- **Laporan insiden bulanan**: MTTA, MTTR, lama gangguan, persentase RCA, per severity/site/penanggung jawab, tren 12 bulan, CSV; ikut laporan email.
- **Jadwal piket**: giliran per orang, pembuat rotasi 8/12/24 jam, kanal email/Telegram "kirim ke yang sedang piket", tampil di Ringkasan dan Layar NOC.
- **ACK dari Telegram**: tombol di pesan problem; hanya engineer/admin terdaftar, tercatat di audit.

### Pemantauan
- **SLA per layanan**: layanan tersusun dari komponen dengan syarat minimal hidup (redundansi), status Tanpa cadangan, ketersediaan bulanan; ikut laporan email, halaman status publik, dan ekspor konfigurasi.
- **Perencanaan kapasitas port**: puncak harian p95, tren, perkiraan kapan penuh.
- **Halaman port lengkap**: trafik, pemakaian, paket, error dan discard, rentang 6 jam sampai 1 tahun; trafik live per port.
- **Trigger custom**: aturan problem dari latensi, loss, sensor, trafik, pemakaian, error dan discard port; durasi, cakupan, jadwal hari/jam.
- **Deteksi anomali**: latensi dan trafik dibanding jam yang sama 14 hari terakhir (median/MAD); tandai wajar dan abaikan sementara.
- **SNMP trap v1/v2c**: trap dikenal diberi ringkasan; trap link/restart/UPS memicu pembacaan segera.
- **Error dan discard port** per menit; **port naik-turun** (flapping) dari polling dan trap, dengan riwayat putus-sambung.
- **Topologi** bergaya kartu dengan label port di kedua ujung, pencocokan tetangga lewat nama/IP/MAC, tombol **Sinkron LLDP sekarang**.
- **Diagnosa SNMP** per perangkat: berkas JSON hasil baca tanpa kredensial untuk membuat profil perangkat baru.

### Integrasi dan keamanan
- **API baca-saja** `/api/v1` dengan token (hash, masa berlaku, batas IP, bisa dicabut). Lihat `docs/API.md`.
- Tes audit hak akses seluruh rute; rute bawaan `/storage/{path}` dimatikan.
- Tampilan web didesain ulang penuh (sistem token warna, tema gelap/terang, nyaman di HP).

### Perbaikan
- Kecepatan port bulat tampil benar (300 Kbps sebelumnya tampil 3 Kbps).
- Pembaca tombol Telegram tidak lagi membanjiri log saat internet putus.

### Dokumen
- `docs/UJI-LAPANGAN.md` (daftar uji dengan perangkat asli), `docs/API.md`, panduan dalam aplikasi diperbarui.

## 0.2.0

Fondasi ANTAREJA NMS: collector Go (ping, SNMP, sensor dan profil perangkat), web Laravel, problem engine dengan
korelasi induk dan maintenance, notifikasi dan eskalasi (Telegram, email, webhook), Layar NOC, topologi LLDP/CDP,
IPAM, CCTV, backup konfigurasi SSH, syslog, discovery, laporan terjadwal, peta lokasi dan denah tambang,
halaman status publik, ekspor/impor konfigurasi, backup NMS, pemantauan kesehatan ANTAREJA sendiri.
