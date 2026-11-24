# ANTAREJA NMS

Network Monitoring System untuk infrastruktur ICT tambang: switch, router, radio PTP/wireless, CCTV/NVR, server, dan UPS
dari satu layar. Engine (Go) dan aplikasi web (Laravel) ditulis sendiri dari nol — **bukan** fork NMS lain — dan
berlisensi tertutup milik perusahaan. Lihat [`LICENSE`](LICENSE), [`docs/CLEAN-ROOM.md`](docs/CLEAN-ROOM.md), dan
[`THIRD_PARTY_LICENSES.md`](THIRD_PARTY_LICENSES.md).

> **Status:** semua fitur di bawah sudah diuji di lingkungan uji (agen SNMP/SSH/SMTP tiruan). Yang masih perlu
> dilakukan di jaringan tambang: **verifikasi profil sensor dengan `snmpwalk` perangkat asli** dan profil Hikvision
> (lihat [Langkah 9](#9-verifikasi-dengan-perangkat-asli)). Arsitektur & peta jalan: [`docs/RANCANGAN.md`](docs/RANCANGAN.md).
> Keamanan: [`docs/KEAMANAN.md`](docs/KEAMANAN.md).

## Daftar isi

- [Mulai dari sini: daftar langkah](#mulai-dari-sini-daftar-langkah)
- [Fitur](#fitur)
- [Arsitektur singkat](#arsitektur-singkat)
- [Kebutuhan](#kebutuhan)
- [1. Siapkan VM](#1-siapkan-vm)
- [2. Pasang Docker & Git](#2-pasang-docker--git)
- [3. Ambil kode dari GitHub](#3-ambil-kode-dari-github)
- [4. Isi konfigurasi `.env`](#4-isi-konfigurasi-env)
- [5. Jalankan](#5-jalankan)
- [6. Setup awal di browser](#6-setup-awal-di-browser)
- [7. Sertifikat HTTPS & firewall](#7-sertifikat-https--firewall)
- [8. Siapkan perangkat jaringan](#8-siapkan-perangkat-jaringan)
- [9. Verifikasi dengan perangkat asli](#9-verifikasi-dengan-perangkat-asli)
- [10. Pemakaian sehari-hari](#10-pemakaian-sehari-hari)
- [Operasional server](#operasional-server)
- [Pengaturan `.env`](#pengaturan-env)
- [Collector site](#collector-site)
- [Peran pengguna](#peran-pengguna)
- [Pemecahan masalah](#pemecahan-masalah)
- [Perintah collector (diagnosa)](#perintah-collector-diagnosa)
- [Struktur repositori & pengembangan](#struktur-repositori--pengembangan)

## Mulai dari sini: daftar langkah

| # | Langkah | Siapa | Selesai bila |
|---|---|---|---|
| 1 | Buat VM Ubuntu 24.04 (4 vCPU, 8 GB RAM, 100 GB disk) di Proxmox, IP tetap di jaringan manajemen | Tim server | VM bisa di-SSH |
| 2 | Pasang Docker & Git | Tim server | `docker compose version` jalan |
| 3 | Ambil kode dengan *deploy key* GitHub (hanya baca) | Admin ANTAREJA | Folder `/opt/antareja` berisi kode |
| 4–5 | `cd /opt/antareja/deploy && ./install.sh` (buat `.env` acak + jalankan) — **simpan cadangan `.env`** | Admin ANTAREJA | Skrip berakhir "Selesai"; `./antareja.sh cek` → semua OK |
| 6 | Buka web, buat akun admin, buat akun per orang | Admin ANTAREJA | Bisa login |
| 7 | Pasang sertifikat HTTPS kantor, atur firewall | Tim server / keamanan | Browser tidak memperingatkan sertifikat |
| 8 | Aktifkan SNMP (read-only) & syslog di perangkat; user SSH baca-saja untuk backup konfigurasi | Engineer jaringan | *Uji sekarang* di form perangkat berhasil |
| 9 | Kirim hasil `snmpwalk` beberapa model perangkat untuk verifikasi profil | Engineer jaringan | Nilai sensor cocok dengan Winbox/airOS/panel UPS |
| 10 | Isi site & perangkat (Discovery / impor CSV), notifikasi, laporan, layar NOC | Tim NOC | Ringkasan & layar NOC menampilkan perangkat |

## Fitur

| Fitur | Keterangan |
|---|---|
| Ping 1 menit | 3 paket per perangkat, paralel: 1.012 perangkat selesai dalam 2,7 detik di 4 vCPU (lihat [`docs/UJI-BEBAN.md`](docs/UJI-BEBAN.md)). Mencatat RTT dan loss |
| Status down/up | Down setelah 2 putaran gagal berturut-turut (±2 menit). Kejadian *up* mencatat lama down |
| SNMP v1/v2c/v3 | sysName, sysDescr, sysObjectID, lokasi, uptime. SNMPv3: MD5/SHA/SHA2 dan DES/AES |
| Deteksi reboot | Dari turunnya sysUpTime. Wrap counter 497 hari tidak dihitung sebagai reboot |
| Port & trafik | IF-MIB: nama, alias, kecepatan, status, MAC, counter byte & error 64-bit |
| Kejadian | down, up, reboot, flapping (≥3× down / 15 menit), latensi tinggi (>150 ms), SNMP gagal/pulih |
| Grafik | Semua angka tersimpan di VictoriaMetrics (bawaan 400 hari) |
| Keamanan | Semua kredensial (SNMP, SSH, kamera, notifikasi) terenkripsi di database & tidak pernah dikirim ke browser. Hanya membaca perangkat, tanpa SNMP SET. Collector tanpa root. HTTPS, CSP ketat, batas login, sesi diputus saat akun nonaktif/ganti password, jejak audit — lihat [`docs/KEAMANAN.md`](docs/KEAMANAN.md) |
| **Web** | Setup awal (akun admin pertama), login dengan pembatas percobaan, peran Admin / Engineer / Viewer |
| Perangkat | Tambah/ubah dengan **uji koneksi** (ping + SNMP) sebelum simpan, daftar dengan filter (status, site, kategori), yang bermasalah di atas |
| **Halaman port** | Klik nama port: statistik per rentang (sekarang, rata-rata, maks 5 menit, **persentil 95**, total volume, error, discard), grafik **trafik** (masuk ke atas / keluar ke bawah + garis persentil 95), **pemakaian %**, **paket/s** (unicast & non-unicast), **error & discard/s** — kursor keempat grafik tersinkron; rentang 6 jam–1 tahun; tetangga LLDP/CDP, alamat IP, trafik live, pindah port cepat. Daftar port di halaman perangkat punya grafik mini 24 jam per port |
| Detail perangkat | Latensi, loss, uptime, info SNMP, grafik latensi/loss dan **trafik per port** (6 jam–30 hari), **trafik live per port** (tombol *Live*: grafik tiap 2/5/10 detik langsung dari perangkat, angka sekarang/rata-rata/puncak) dan *Live semua port* (tabel port diperbarui tiap 5 detik), kejadian |
| Ringkasan | **Bisa diatur per pengguna** (tombol *Atur tampilan*): pilih widget, urutan, dan lebar (setengah/penuh) — angka utama, problem aktif, kejadian 24 jam, latensi & loss tertinggi, per site, sensor di luar ambang, kamera bermasalah, syslog error, perubahan konfigurasi, maintenance, peta lokasi |
| Lainnya | Site, riwayat kejadian dengan filter, pengguna, jejak audit, ganti password, tema terang/gelap, tampilan HP |
| **Problem** | Dinilai tiap menit: perangkat down, packet loss & latensi (rata-rata 5 menit), SNMP gagal, port dipantau putus/penuh. Selesai otomatis saat pulih; ACK dengan catatan |
| **Laporan insiden bulanan** | *Laporan → Insiden bulanan*: jumlah insiden, **MTTA** (sampai ditangani), **MTTR** (sampai pulih), total lama gangguan, persentase yang sudah diisi penyebab & tindakan; rekap per severity, per site, per penanggung jawab; daftar insiden terlama dengan penyebab; tren 12 bulan; CSV & cetak. Laporan email terjadwal ikut memuat ringkasan insiden |
| **Trigger** | Aturan problem sendiri dari data yang sudah dikumpulkan: latensi, loss, nilai sensor per kelas (suhu, sinyal, CCQ, baterai, dll.), trafik masuk/keluar, pemakaian port, serta error dan discard port per menit (saring nama port/sensor). Operator, ambang, harus bertahan N menit, berlaku untuk semua/site/kategori/perangkat terpilih, jadwal hari & jam (bisa melewati tengah malam). Ikut maintenance, korelasi induk, notifikasi & insiden seperti problem lain |
| **Kapasitas port** | Untuk port yang ditandai *pantau*: puncak harian (persentil 95), pemakaian vs kecepatan port, tren 30/90 hari, dan **perkiraan tanggal penuh** (batas bawaan 80%, bisa diubah admin). Urut dari yang paling mendesak, grafik kecil per port, CSV |
| **SLA layanan** | Ketersediaan per layanan yang dipakai pengguna (mis. *CCTV Pit A*, *Internet kantor*). Layanan = komponen; komponen = perangkat + syarat "minimal N hidup" (jalur seri atau perangkat cadangan). Status sekarang (Normal / Tanpa cadangan / Terganggu), ketersediaan bulanan vs target per layanan, daftar gangguan & komponen penyebab, sisa jatah downtime, riwayat 12 bulan, CSV, widget Ringkasan |
| **Insiden** | Satu gangguan nyata = satu insiden berisi semua problem terkait. Dibuat otomatis (problem Disaster, atau ≥ 3 problem High di satu site dalam 10 menit; anak yang terdampak induk ikut masuk) atau manual (centang problem → *Buat insiden*). Alur: Terbuka → Ditangani (penanggung jawab) → Pulih (otomatis) → Ditutup (wajib isi penyebab & tindakan). Kronologi & catatan tim, MTTR 30 hari, ekspor CSV |
| **Runbook** | Menu *Runbook*: langkah penanganan yang ditulis engineer, berlaku untuk jenis problem, kategori perangkat, site, atau perangkat tertentu (yang paling spesifik dipakai: perangkat, site, kategori, jenis). Tampil sebagai tautan di setiap problem, kartu di halaman perangkat, dan lima langkah pertama ikut di pesan Telegram/email beserta tautan runbook lengkap. Perubahan tercatat di jejak audit |
| **Cek layanan** | Menu *Cek layanan*: memeriksa aplikasinya, bukan hanya perangkatnya. **HTTP(S)** (kode status yang diharapkan, teks yang wajib ada di halaman, tanpa login dan tanpa mengikuti pengalihan, masa berlaku sertifikat), **port TCP** (mis. database, SSH, RTSP), **DNS** (server DNS perangkat itu harus menjawab nama tertentu, opsional dengan IP tertentu). Tiap 30 detik sampai 1 jam dari server pusat (atau dari collector site untuk perangkat miliknya), gagal 2 kali berturut-turut menjadi problem dengan severity pilihan; lambat di atas ambang dan sertifikat hampir habis menjadi Warning. Setiap cek melekat ke satu perangkat sehingga maintenance, perangkat induk, insiden, dan notifikasi ikut berlaku. *Uji sekarang* untuk engineer |
| **Peta dampak** | Menu *Dampak*: tiap perangkat down yang menjadi sumber gangguan beserta yang ikut terganggu, yaitu perangkat di bawahnya (dari isian perangkat induk), kamera terkait, layanan yang jatuh di bawah syarat minimal hidup, dan site yang putus total. Simulasi "bila perangkat ini mati" (bisa beberapa sekaligus) untuk rencana maintenance. Ringkasan dampak tampil di daftar problem, halaman perangkat, dan pesan Telegram/email problem down |
| Korelasi | Isi *perangkat induk* (uplink). Bila induk down, problem anak ditandai **terdampak** dan tidak membanjiri notifikasi |
| Maintenance | Jadwal per perangkat, per site, atau semua; problem di dalamnya tetap tercatat tetapi tidak dinotifikasi |
| **Jadwal piket** | *Operasi → Jadwal piket*: giliran per orang (mulai, selesai), tampilan mingguan, pembuat rotasi (8, 12, atau 24 jam bergantian untuk sampai 6 orang, bisa mengganti jadwal lama). Kanal email/Telegram bisa diset **kirim ke yang sedang piket**: penerima diganti email atau chat ID Telegram orang yang sedang bertugas (diisi di Profil); bila tidak ada yang piket atau kontaknya kosong, dikirim ke tujuan tetap kanal. Yang piket sekarang dan berikutnya tampil di Ringkasan (widget Piket) dan Layar NOC |
| **Deteksi anomali** | *Pemantauan → Anomali*: tiap 5 menit, latensi dan trafik port (rata-rata 15 menit) dibandingkan dengan jam yang sama 14 hari terakhir (median dan MAD). Mencatat latensi yang naik jauh, trafik yang melonjak, dan trafik yang anjlok (mis. kamera berhenti streaming), harus bertahan 10 menit. Masuk Kejadian dan bisa jadi problem (severity diatur admin, bawaan Warning). Tombol **Wajar** menutup anomali dan mengabaikan yang sama 1 jam, 24 jam, atau 7 hari. Perangkat baru dinilai setelah 7 hari data |
| Sinkron & diagnosa | Tombol **Sinkron LLDP sekarang** di Topologi (putaran SNMP penuh di luar jadwal, tidak tumpang tindih dengan putaran terjadwal). Tombol **Diagnosa SNMP** di halaman perangkat: berkas JSON hasil baca (tanpa kredensial) untuk membuat profil perangkat yang belum dikenal, lihat [`docs/UJI-LAPANGAN.md`](docs/UJI-LAPANGAN.md) |
| ACK dari Telegram | Pesan problem tunggal di kanal Telegram bisa diberi tombol **ACK, saya tangani** (centang di form kanal). Collector membaca tekanan tombol lewat `getUpdates`, jadi server tidak perlu bisa diakses dari internet. Hanya pengguna aktif engineer/admin yang chat ID Telegram-nya terisi di Profil yang bisa ACK; tercatat di problem dan jejak audit. `ANTAREJA_TELEGRAM_API` opsional untuk Bot API server sendiri |
| API baca-saja | `/api/v1` untuk SOC atau sistem lain: perangkat, site, problem, insiden, kejadian, anomali, layanan (JSON, kursor `after_id`). Token dibuat admin di *Token API* (tampil sekali, disimpan sebagai hash, masa berlaku, batas IP, bisa dicabut). Hanya GET, tanpa kredensial. Lihat [`docs/API.md`](docs/API.md) |
| Notifikasi | **Telegram**, **Email (SMTP)**, **Webhook** (gateway WhatsApp, n8n, sistem tiket). Per kanal: severity minimal, site, kirim saat pulih. Banyak problem sekaligus → 1 pesan ringkasan |
| Eskalasi | Kanal dengan jeda (mis. 30 menit) hanya dikirimi problem yang **belum di-ACK** selama itu — untuk supervisor/manajemen |
| **Profil & sensor** | Deteksi otomatis: MikroTik (suhu, tegangan, sinyal/CCQ/rate wireless, sinyal modem 4G/LTE), Ubiquiti airMAX (sinyal, CCQ, quality, capacity, jarak link), Cisco (CPU, memori, suhu), UPS-MIB & APC (di baterai, muatan, waktu cadangan, beban), plus CPU/memori/disk/suhu standar. Sensor di luar ambang jadi problem. Halaman **Sensor**: Radio & PTP, Daya & UPS, Sistem & suhu |
| **IPAM** | Subnet otomatis dari IP interface perangkat + manual (nama, site, VLAN); pemakaian dari tabel ARP router/L3; peta alamat /24 berwarna, daftar IP kosong, cari IP/MAC (di router & port mana) |
| **Modbus TCP** | Halaman perangkat → *Modbus*: membaca solar controller, baterai, genset, atau meter daya di menara lewat Modbus TCP tiap menit (port dan unit ID, juga lewat gateway RS485). **Hanya baca**: klien ditulis sendiri dan hanya mengenal fungsi 03 dan 04, tidak bisa menulis ke perangkat. Titik baca diisi dari tabel register di manual perangkat: input/holding register, alamat, tipe (16/32-bit bertanda/tanpa tanda, float), tukar urutan word, skala, tambahan, ambang warning dan kritis. Hasilnya menjadi sensor biasa: tab *Daya & UPS* di halaman Sensor (tegangan, arus, daya, bahan bakar), grafik, problem (warning = Warning, kritis = High), dan trigger. *Uji baca* menampilkan nilai dan register mentah; titik bisa disalin dari menara lain. Dibaca dari server pusat, atau dari collector site untuk perangkat miliknya |
| **Flow trafik** | Menu *Flow trafik*: router/switch mengirim **NetFlow v5/v9 atau IPFIX** ke port 2055/UDP, atau **sFlow v5** ke 6343/UDP. Per port dan arah: sumber terbesar, tujuan terbesar, aplikasi (protokol + port layanan, mis. RTSP kamera, HTTPS, DNS, MySQL), percakapan terbesar, untuk 1 jam sampai 7 hari; IP diberi nama perangkat bila terdaftar. Laju sampling diperhitungkan. Ringkasan 5 menit, 50 percakapan terbesar per port + "lainnya", disimpan 7 hari (`flow.keep_days`). Halaman port menampilkan pemakai terbesar 1 jam. Hanya diterima dari IP perangkat yang terdaftar |
| **Lacak MAC ke port** | Tabel MAC (FDB) switch kategori Core, Distribution, dan Access dibaca tiap putaran SNMP (Q-BRIDGE-MIB per VLAN, cadangan BRIDGE-MIB). Setiap MAC diberi lokasi **port tepi**: port tanpa tetangga LLDP/CDP atau sambungan manual dengan MAC paling sedikit (port berisi lebih dari 12 MAC dianggap uplink). Cari IP/MAC di IPAM untuk melihat "terpasang di switch X port Y sejak ...", riwayat pindah port 90 hari, daftar *Terpasang di port ini* di halaman port, dan *Terpasang di* di halaman perangkat (dicocokkan lewat MAC interface perangkat). Cisco IOS hanya terbaca untuk VLAN 1 (BRIDGE-MIB per VLAN butuh community@vlan) |
| **Konflik IP** | Konservatif agar tidak ada alarm palsu: hanya bila di router & port yang sama satu IP bergantian antara tepat dua MAC berulang (bawaan ≥ 3 kali dalam 6 jam). Ganti MAC sekali (DHCP, ganti perangkat) bukan konflik |
| **CCTV** | Grid snapshot semua kamera (tiap 10 detik) dan **live view** di browser (sub-stream hemat / main-stream kualitas tinggi) lewat go2rtc. Tambah satu kamera atau banyak kanal NVR Hikvision sekaligus; password RTSP terenkripsi; video hanya bisa dibuka setelah login |
| **Cek gambar CCTV** | Tiap 15 menit satu gambar per kamera dinilai otomatis: **gelap**, **tertutup/buram** (gambar polos — debu, lumpur, lensa berembun), **beku** (gambar identik persis 3× berturut-turut), **tanpa sinyal** (login RTSP ditolak / kamera tidak menjawab). Kamera yang terkait perangkat menjadi problem |
| **Impor CSV** | Tambah perangkat massal dari CSV/Excel (nama, host, site, kategori, SNMP, community, induk) dengan pratinjau & validasi per baris; site dibuat otomatis |
| **Discovery jaringan** | Pindai subnet (maks. /22) dari web: ping lalu SNMP GET grup *system* — **hanya baca**. Vendor & kategori ditebak dari sysObjectID/sysDescr (MikroTik, Cisco, Ubiquiti, Hikvision, APC, Fortinet, dll.), alamat yang sudah terdaftar ditandai. Engineer meninjau nama/kategori/site lalu memilih yang ditambahkan; community yang menjawab ikut terpasang (terenkripsi, dihapus 7 hari setelah pemindaian) |
| **Kualitas jaringan** | Peringkat link terburuk 24 jam / 7 hari / 30 hari: rata-rata, p95, maksimum latensi, **jitter**, loss, ketersediaan ping. Grafik latensi perangkat dengan pita min–maks (gaya SmokePing) |
| **Versi & jam** | Versi perangkat lunak, model, serial per perangkat (MIKROTIK-MIB, ENTITY-MIB, IEEE802dot11-MIB, sysDescr), dikelompokkan per model dengan penanda versi yang beda sendiri; kejadian saat versi berubah. Selisih jam perangkat dari hrSystemDate jadi sensor (peringatan 1 menit, kritis 5 menit) |
| **Ambang per perangkat** | Latensi, loss, port penuh, sinyal radio, RSRP/SINR LTE, CCQ, suhu, CPU, baterai & waktu cadangan UPS bisa diatur khusus per perangkat (mis. radio PTP jarak jauh lebih longgar) |
| **Backup konfigurasi** | MikroTik (`/export hide-sensitive`) & Cisco IOS (`show running-config`) via SSH tiap 24 jam atau tombol *Backup sekarang*. **Hanya baca**: perintah dipatok, tidak bisa diisi pengguna. Versi terenkripsi (50 terakhir), **diff** berwarna, kejadian *konfigurasi berubah*, unduh `.rsc`/`.txt`. Host key dicatat saat pertama; bila berubah backup ditolak sampai engineer mereset. Hanya admin/engineer |
| **Log (syslog)** | Penerima syslog UDP/TCP port 514 (RFC 3164 & 5424, format MikroTik & Cisco). Log dicocokkan ke perangkat lewat IP pengirim (IP manajemen atau IP interface dari IPAM); pengirim belum terdaftar tetap tercatat. Cari teks, filter tingkat/site/program/IP, mode *Live*, perangkat paling banyak error, log terbaru di detail perangkat. Log Critical ke atas → kejadian perangkat. Batas laju per pengirim agar banjir log tidak membebani server. Retensi diatur dari web (bawaan 14 hari) |
| **SNMP trap** | Penerima trap v1/v2c UDP 162. Dikenali: linkDown/linkUp (dengan nama port), coldStart/warmStart, authenticationFailure, trap UPS-MIB (pindah ke baterai, alarm). Trap link, restart, dan UPS memicu pembacaan SNMP perangkat itu saat itu juga, jadi status port dan sensor tidak menunggu putaran polling. Trap vendor lain tetap disimpan dengan OID dan isinya. Halaman *Pemantauan → Trap*, masuk Kejadian, retensi bawaan 30 hari |
| Error & discard port | Laju error dan discard per menit (masuk + keluar) dihitung tiap putaran SNMP dari counter IF-MIB: kolom di daftar port perangkat, widget Ringkasan *Port dengan error*, metrik trigger (mis. error radio di atas 30/menit selama 10 menit), dan di API. Tanda awal kabel, konektor, atau link radio yang memburuk sebelum port benar-benar putus |
| Port naik-turun | Perubahan status port (up/down) dicatat dari polling dan dari trap linkDown/linkUp (yang menangkap putus-sambung cepat di antara dua putaran). Port infrastruktur (dipantau, punya tetangga LLDP/CDP, atau sambungan manual) yang berubah ≥ 4 kali dalam 15 menit menjadi problem Average *Port naik-turun*, tertutup sendiri setelah stabil (ambang di halaman Notifikasi). Halaman port menampilkan riwayat putus-sambung 7 hari |
| **Down bulanan** | *Laporan → Down bulanan*: persentase down tiap perangkat per bulan (3 sampai 24 bulan), total per site, warna merah bila melewati batas target SLA, CSV, API `/api/v1/downtime`. Rekap disimpan collector tiap jam ke tabel tersendiri (bulan lalu dikunci), jadi riwayat tetap ada walau perangkat dihapus atau data problem lama dibersihkan |
| Alat diagnosa | Di halaman perangkat (engineer/admin): ping 10 paket dengan RTT per paket, traceroute, dan cek port TCP, dijalankan dari collector ke host perangkat itu saja. Tercatat di jejak audit |
| **Collector site** | Collector kecil (image yang sama) di VM / mini PC site tambang: ping dan SNMP perangkat lokal, hasil dikirim ke pusat lewat HTTPS dengan token per collector. Jalur ke pusat putus: hasil diantrekan di disk site (bawaan 512 MB) dan dikirim berurutan saat pulih, kejadian tetap memakai jam pengukuran, jam site yang meleset dikoreksi. Pusat tidak memeriksa perangkat itu sendiri, jadi putusnya jalur tidak membuat semua perangkat site down; yang muncul satu peringatan *Collector site tidak melapor*. Lihat [Collector site](#collector-site) |
| Verifikasi dua langkah | Kode 6 digit dari aplikasi autentikator (Google/Microsoft Authenticator, Aegis, FreeOTP) diaktifkan di Profil lewat kode QR; 10 kode cadangan sekali pakai. Admin bisa mewajibkan untuk admin atau semua akun dan mereset 2FA pengguna yang kehilangan HP |
| Login LDAP / AD | Opsional (*Pengguna → Kebijakan login*): akun kantor login dengan password direktori, akun dibuat otomatis, peran dari grup (admin/engineer/viewer), diperbarui tiap login. LDAPS atau StartTLS, tombol *Uji koneksi*. Akun lokal (termasuk admin) tetap bisa masuk walau server direktori mati |
| Ubah perangkat massal | Centang perangkat di daftar Perangkat: pindah site, atur induk, kategori, kredensial SNMP, dipantau dari collector mana, aktif/nonaktif, hapus (ketik HAPUS). Satu catatan audit per perangkat |
| Backup ke luar server | *Administrasi → Backup*: setiap backup yang berhasil disalin collector ke **SFTP** (NAS, server lain; host key dikunci saat koneksi pertama) atau **S3/MinIO** (SigV4, isi diverifikasi MD5/ETag), dengan retensi di tujuan, coba ulang otomatis, status per backup, tombol *Uji koneksi*, dan peringatan di Kesehatan sistem bila gagal. Kredensial terenkripsi |
| **Backup** | Backup database harian otomatis (jam & jumlah simpanan diatur dari web) + tombol *Backup sekarang*, unduh dari web, pemulihan dengan `./antareja.sh restore`. Arahkan `BACKUP_DIR` ke NAS agar aman bila VM rusak |
| **SLA bulanan** | Ketersediaan per perangkat & per site, downtime, jumlah gangguan, rata-rata waktu pulih; jam maintenance tidak dihitung; target SLA; ekspor CSV (Excel) & cetak/PDF |
| **Laporan terjadwal** | Ringkasan harian (kemarin), mingguan (Senin–Minggu) atau bulanan otomatis ke kanal notifikasi: ketersediaan vs target, per site, perangkat dengan downtime terbesar, yang sedang down, problem baru per severity, reboot, perubahan konfigurasi, syslog error. Email tampil rapi (HTML + teks), Telegram ringkas. Pratinjau & *Kirim sekarang* dari web (admin) |
| **Tautan status** | Halaman status hanya-baca untuk manajemen tanpa akun (admin membuat tautan rahasia per site/semua, masa berlaku, bisa dicabut): status per site, ketersediaan bulan ini, gangguan, jumlah problem, opsional nama perangkat down. Tanpa IP/konfigurasi; token disimpan sebagai hash & tidak dicatat di log |
| **VLAN & STP** | VLAN per switch dan nama yang tidak seragam, VLAN bawaan dan status STP per port, root bridge; perubahan topologi STP jadi kejadian dan problem bila sering |
| **BGP & OSPF** | Sesi BGP dan tetangga OSPF dari BGP4-MIB / OSPF-MIB; sesi turun atau tetangga hilang jadi problem, perubahan status jadi kejadian |
| **Prediksi habis** | Perkiraan disk penuh dan bahan bakar genset habis dari tren; jadi problem sebelum kejadian (disk 7 hari, solar 24 jam) |
| **Templat pesan** | Isi notifikasi problem baru dan pulih bisa diatur per kanal dengan penanda (`{judul}`, `{perangkat}`, `{site}`, `{durasi}`, `{dampak}`, `{runbook}`, `{tautan}`, ...) |
| **Aturan log** | Pola teks/regex pada syslog (mis. "login failure" 5 kali dalam 1 menit, "link down" sekali) jadi problem per perangkat, dengan uji ke log 24 jam terakhir; pulih sendiri setelah pola tidak muncul lagi |
| **Akun terbatas per site** | Viewer atau engineer bisa dibatasi ke site tertentu (mis. kontraktor satu pit): hanya melihat Ringkasan, Problem, Perangkat, Sensor, Kejadian site itu; engineer terbatas hanya bisa ACK |
| **Exporter Prometheus** | Halaman perangkat → *Exporter*: scrape node_exporter (Linux) atau windows_exporter (Windows) tiap menit. CPU, memori, dan disk per mount/drive jadi sensor dengan ambang CPU/memori/disk perangkat; layanan yang dipilih (unit systemd atau layanan Windows) jadi problem bila berhenti. *Uji scrape* untuk mencoba. Hanya GET, tanpa kredensial; juga lewat collector site |
| **Ekspor & impor konfigurasi** | Satu berkas JSON: site, perangkat (ambang, induk, koordinat, collector site, setelan dan titik Modbus), sambungan manual, subnet, kamera, kanal, laporan terjadwal, layanan, trigger, cek layanan, runbook, collector site (tanpa token), pengaturan, untuk pindah VM / VM uji / cadangan yang bisa dibaca. Rahasia opsional, dienkripsi frasa sandi (AES-256-GCM). Impor dengan pratinjau; menambah & memperbarui, tidak menghapus |
| **Kesehatan sistem** | ANTAREJA memantau dirinya sendiri: detak collector, putaran ping/SNMP terakhir, backup (gagal/terlambat), ruang disk folder backup, layanan backup, kegagalan notifikasi, syslog dibuang, ukuran database. Masalah dikirim ke kanal notifikasi (saat muncul, pengingat tiap 6 jam, saat pulih; dicoba ulang 15 menit bila semua kanal gagal). Bila collector berhenti, semua halaman & Layar NOC menampilkan peringatan "data usang" |
| **Topologi** | Peta otomatis dari **LLDP** dan **CDP**, sambungan manual (radio, NVR, kamera), garis putus-putus untuk perangkat induk. Perangkat tampil sebagai **kartu** (ikon kategori, nama, IP, status tertulis), **nama port di kedua ujung garis**, warna garis = pemakaian, **trafik live** tiap 5 detik. Bilah alat: site, pas layar, legenda, atur ulang tata letak, simpan posisi, sambungkan, tambah perangkat. Tab **Daftar sambungan** = versi tabel (port A/B, sumber, status, trafik); klik nama perangkat untuk memusatkan peta |
| **Peta lokasi** | Site & perangkat berkoordinat (menara radio, NVR) di atas peta jalan atau **topografi** (+ satelit bila perusahaan punya penyedia berlisensi), daftar site untuk lompat ke lokasi yang berjauhan: warna = status (down berdenyut), jumlah down, garis sambungan antarperangkat (merah bila putus). Posisi diatur dengan klik/geser di peta atau tempel koordinat. Sumber gambar peta bisa diganti ke server tile internal untuk ruang kontrol tanpa internet. **Denah tambang**: unggah gambar denah/pit (PNG/JPG/WebP) sebagai latar pengganti peta dunia — posisi per denah terpisah, gambar ikut backup database |
| **Layar NOC** | Layar penuh untuk TV ruang kontrol (tombol `F`): alarm bertingkat (merah hanya untuk High/Disaster belum di-ACK) dengan **bunyi**, dinding status per site bergantian dengan peta topologi dan **peta lokasi** (peta jalan versi gelap), problem aktif, kejadian 1 jam, port tersibuk. Diperbarui tiap 15 detik |

Keamanan: lihat [`docs/KEAMANAN.md`](docs/KEAMANAN.md) — kontrol yang diterapkan, batasan yang diketahui, dan daftar periksa pemasangan.

## Arsitektur singkat

```
 Perangkat tambang ──ICMP/SNMP/SSH (baca)──►  collector (Go) ──► PostgreSQL (konfigurasi, status, kejadian, log)
 (switch, radio,   ──syslog UDP/TCP 514────►       │        ──► VictoriaMetrics (grafik)
  NVR, UPS)                                         │ API internal (hanya jaringan Docker)
                                                    ▼
 Browser ◄──HTTP 80 / HTTPS 443── web (Laravel + nginx) ── go2rtc (video CCTV, internal) ── backup (pg_dump terjadwal)
```

Semua berjalan sebagai satu Docker Compose (proyek `antareja-core`) di satu VM. Collector **hanya membaca** perangkat:
tidak ada SNMP SET maupun perintah yang mengubah konfigurasi.

Untuk site yang jalurnya ke pusat bisa putus, pasang **collector site** di site itu (opsional):

```
 Perangkat site ──ICMP/SNMP──► collector site ──HTTPS (token)──► web pusat ──► collector pusat ──► PostgreSQL
                               (antrean disk saat putus)
```

## Kebutuhan

| Hal | Rekomendasi |
|---|---|
| VM | Ubuntu Server 24.04 LTS, **4 vCPU, 8 GB RAM, 100 GB disk** (cukup untuk ±2.000 perangkat). Tambah disk bila menyimpan syslog lama / banyak kamera |
| Jaringan | IP tetap di jaringan manajemen yang bisa menjangkau semua perangkat (ICMP, SNMP UDP 161, SSH 22, RTSP 554 kamera) |
| Internet keluar | Saat pasang & update (unduh image Docker, paket Go/PHP/npm). Saat berjalan: Telegram/SMTP bila dipakai, gambar peta (OpenStreetMap) di browser |
| Penyimpanan backup | Folder NAS yang di-mount ke VM (disarankan) |
| Jam | Sinkron NTP (`timedatectl` → `System clock synchronized: yes`) |

## 1. Siapkan VM

1. Buat VM di Proxmox: Ubuntu Server 24.04, spesifikasi di atas, **IP statis**.
2. Login SSH ke VM, perbarui sistem & pastikan jam tersinkron:

```bash
sudo apt update && sudo apt -y upgrade
```

```bash
timedatectl
```

## 2. Pasang Docker & Git

```bash
sudo apt install -y docker.io docker-compose-v2 git curl openssl snmp
```

Izinkan user Anda menjalankan Docker (logout lalu login lagi setelahnya):

```bash
sudo usermod -aG docker $USER
```

Cek:

```bash
docker compose version
```

## 3. Ambil kode dari GitHub

Repositori bersifat **private**. Cara paling aman: *deploy key* (hanya-baca, khusus VM ini).

Buat kunci di VM:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/antareja_deploy -N "" -C "antareja-vm"
```

```bash
cat ~/.ssh/antareja_deploy.pub
```

Salin isinya ke GitHub: repositori **AntarejaNMS → Settings → Deploy keys → Add deploy key** (jangan centang
*Allow write access*). Lalu ambil kodenya:

```bash
sudo mkdir -p /opt/antareja && sudo chown $USER: /opt/antareja
```

```bash
GIT_SSH_COMMAND="ssh -i ~/.ssh/antareja_deploy" git clone git@github.com:aidilandriandas/AntarejaNMS.git /opt/antareja
```

```bash
cd /opt/antareja && git config core.sshCommand "ssh -i ~/.ssh/antareja_deploy"
```

## 4. Isi konfigurasi `.env`

**Cara cepat (disarankan):** skrip pemasangan menanyakan IP, port (bawaan 80/443), dan folder backup, membuat `.env`
dengan password & kunci acak, mengecek port bentrok, lalu menjalankan dan memeriksa semuanya (langkah 4 & 5 sekaligus):

```bash
cd /opt/antareja/deploy && ./install.sh
```

Lanjut ke [langkah 6](#6-setup-awal-di-browser) bila skrip berakhir dengan "Selesai". Cara manual:

```bash
cd /opt/antareja/deploy && cp .env.example .env
```

Isi password database dan kunci enkripsi secara acak:

```bash
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" .env
```

```bash
sed -i "s|^ANTAREJA_APP_KEY=.*|ANTAREJA_APP_KEY=base64:$(openssl rand -base64 32)|" .env
```

Ganti `IP-VM-ANDA` di `APP_URL` dengan IP VM atau nama DNS (contoh `10.10.0.50`):

```bash
sed -i "s|IP-VM-ANDA|10.10.0.50|" .env
```

Bila backup disimpan di NAS, ubah `BACKUP_DIR` (mis. `/mnt/nas/antareja`). Pengaturan lain: lihat
[Pengaturan `.env`](#pengaturan-env).

> ⚠️ **Simpan salinan `deploy/.env` di tempat aman terpisah** (brankas password / NAS). `ANTAREJA_APP_KEY` mengunci semua
> kredensial (SNMP, SSH, kamera, notifikasi). Tanpa kunci yang sama, kredensial di backup tidak bisa dibuka.
> Jangan pernah commit `.env` ke Git.

## 5. Jalankan

```bash
cd /opt/antareja/deploy && chmod +x antareja.sh && ./antareja.sh up
```

Pemasangan pertama ±5–15 menit (mengunduh & membangun image). Lalu periksa:

```bash
./antareja.sh cek
```

Semua baris harus normal dan diakhiri "Semua pemeriksaan dasar OK".

## 6. Setup awal di browser

1. Buka **`http://IP-VM`** (otomatis dialihkan ke `https://IP-VM`). Sebelum sertifikat kantor dipasang, browser memperingatkan sertifikat — pilih lanjutkan.
2. Halaman **Setup Awal** muncul sekali: buat akun **admin**.
3. *Administrasi → Pengguna*: buat **akun per orang** (bukan akun bersama). Peran: lihat [Peran pengguna](#peran-pengguna).
4. *Site*: tambahkan site (kode singkat, mis. `PIT-A`, `JETTY`).

## 7. Sertifikat HTTPS & firewall

**Sertifikat kantor:** letakkan berkas sertifikat (beserta rantai/intermediate) dan kuncinya di VM:

```bash
mkdir -p /opt/antareja/deploy/certs
```

Salin menjadi `deploy/certs/server.crt` dan `deploy/certs/server.key`, lalu:

```bash
chmod 600 /opt/antareja/deploy/certs/server.key && cd /opt/antareja/deploy && ./antareja.sh up
```

`./antareja.sh` otomatis memakai sertifikat tersebut (berkas ini tidak masuk Git). Ubah `APP_URL` di `.env` ke nama
DNS sertifikat bila ada.

**Firewall** — atur di **firewall Proxmox (VM → Firewall)** atau firewall jaringan. Catatan: aturan `ufw` di dalam VM
**tidak** membatasi port yang dibuka Docker.

| Port | Dari mana | Untuk |
|---|---|---|
| 80/TCP & 443/TCP (80 dialihkan ke 443) | Jaringan kantor/NOC | Web |
| 514/UDP & TCP | Subnet manajemen perangkat | Syslog |
| 162/UDP | Subnet manajemen perangkat | SNMP trap |
| 2055/UDP, 6343/UDP | Subnet manajemen perangkat | NetFlow/IPFIX dan sFlow (opsional, bila router mengirim flow) |
| 22/TCP | Admin server saja | SSH ke VM |

Keluar dari VM: ke semua perangkat (ICMP, UDP 161, TCP 22, TCP 554), DNS, NTP, `api.telegram.org`/server SMTP bila dipakai.

## 8. Siapkan perangkat jaringan

Gunakan akun & community **khusus NMS yang hanya bisa membaca**, dan batasi ke IP VM ANTAREJA. Ganti `10.10.0.50`
dengan IP VM dan `RAHASIA…` dengan nilai acak.

### MikroTik (RouterOS 7)

SNMP v2c hanya-baca:

```
/snmp community add name=RAHASIA-RO addresses=10.10.0.50/32 read-access=yes write-access=no
/snmp set enabled=yes contact="NOC" location="PIT-A"
```

Atau SNMPv3 (disarankan):

```
/snmp community add name=nms-v3 addresses=10.10.0.50/32 security=private authentication-protocol=SHA1 authentication-password=RAHASIA-AUTH encryption-protocol=AES encryption-password=RAHASIA-PRIV read-access=yes write-access=no
```

Syslog ke ANTAREJA:

```
/system logging action add name=antareja target=remote remote=10.10.0.50 remote-port=514
/system logging add action=antareja topics=info
/system logging add action=antareja topics=warning
/system logging add action=antareja topics=error
/system logging add action=antareja topics=critical
```

SNMP trap ke ANTAREJA (port putus/tersambung dan restart langsung tercatat, perangkat dibaca ulang saat itu juga):

```
# MikroTik RouterOS
/snmp set enabled=yes trap-version=2 trap-target=10.10.0.50 trap-generators=interfaces,start-trap trap-interfaces=all

# Cisco IOS
snmp-server host 10.10.0.50 version 2c <community>
snmp-server enable traps snmp linkdown linkup coldstart warmstart
```

Parameter persisnya bisa berbeda antarversi firmware; periksa dokumentasi perangkat. Trap v1 dan v2c diterima; inform dan v3 belum.

User SSH untuk **backup konfigurasi** (hanya baca):

```
/user group add name=nms-backup policy=ssh,read,!write,!policy,!sensitive
/user add name=nms-backup group=nms-backup address=10.10.0.50/32 password=RAHASIA-SSH
```

### Cisco IOS / IOS-XE

```
ip access-list standard NMS-ONLY
 permit 10.10.0.50
snmp-server community RAHASIA-RO RO NMS-ONLY
logging host 10.10.0.50
logging trap informational
service timestamps log datetime localtime
```

Backup konfigurasi menjalankan `terminal length 0` dan `show running-config`. Pada IOS, isi `show running-config` lengkap
umumnya butuh privilege 15; batasi akun tersebut ke IP VM (ACL di `line vty`) dan, bila ada, pakai AAA command
authorization agar hanya perintah `show` yang diizinkan.

### Ubiquiti airMAX (airOS)

*Services → SNMP Agent*: aktifkan, isi community, contact, location. *Services → System Log*: Remote log ke IP VM port 514.

### Hikvision NVR / kamera

*Configuration → Network → Advanced → SNMP*: aktifkan **SNMP v2c**, isi *Read community* (jangan aktifkan *Write*).
Untuk CCTV live view: siapkan user **khusus lihat** (operator/viewer) di NVR untuk RTSP.

### UPS (APC dll.)

Aktifkan SNMP di kartu jaringan UPS (Network Management Card), community hanya-baca, batasi ke IP VM.

Setelah perangkat siap, di ANTAREJA: *Perangkat → Tambah perangkat* → isi → **Uji sekarang** harus berhasil sebelum disimpan.

## 9. Verifikasi dengan perangkat asli

Profil sensor (MikroTik, Ubiquiti, Cisco, UPS) ditulis dari dokumen MIB; skala bisa berbeda antarversi firmware.
Untuk **setiap model** perangkat, jalankan dari VM ANTAREJA lalu kirim berkasnya ke pengembang beserta tangkapan layar
nilai yang sama di Winbox/airOS/panel UPS:

```bash
snmpwalk -v2c -c COMMUNITY -On IP-PERANGKAT .1.3.6.1.2.1.1 > MODEL-sistem.txt
```

```bash
snmpwalk -v2c -c COMMUNITY -On IP-PERANGKAT .1.3.6.1.4.1 > MODEL-vendor.txt
```

```bash
snmpwalk -v2c -c COMMUNITY -On IP-PERANGKAT .1.3.6.1.2.1.25 > MODEL-host.txt
```

Untuk UPS tambahkan `.1.3.6.1.2.1.33`; untuk NVR Hikvision jalankan ketiganya (profil Hikvision dibuat dari hasil ini).
Hapus community dari nama berkas/isi sebelum dikirim. Detail: [`docs/PROFIL-PERANGKAT.md`](docs/PROFIL-PERANGKAT.md).

Cara yang lebih mudah dari web: buka halaman perangkat, tekan **Diagnosa SNMP**, kirim berkas JSON-nya (sudah tanpa
kredensial). Daftar uji lengkap per jenis perangkat ada di [`docs/UJI-LAPANGAN.md`](docs/UJI-LAPANGAN.md).

## 10. Pemakaian sehari-hari

| Tugas | Di mana |
|---|---|
| Tambah banyak perangkat | *Perangkat → Discovery* (pindai subnet, centang, tambah) atau *Impor CSV* |
| Backup konfigurasi MikroTik/Cisco | Form perangkat → bagian *Backup konfigurasi (SSH)*; lihat versi & diff di halaman perangkat |
| Notifikasi Telegram/email/webhook | *Administrasi → Notifikasi* → tambah kanal → **Kirim uji** |
| Laporan harian/mingguan/bulanan ke manajemen | *Laporan terjadwal* → pratinjau → *Kirim sekarang* |
| Layar NOC (TV ruang kontrol) | *Layar NOC*, login akun viewer dengan *Ingat saya*, tekan `F`, klik **SUARA** sekali, pilih **OTOMATIS** |
| Peta lokasi | *Peta lokasi → Atur posisi* → pilih site → klik di peta / tempel koordinat |
| Status untuk manajemen tanpa akun | *Administrasi → Tautan status* |
| Alarm khusus (mis. trafik uplink 0 di jam kerja, suhu panel ≥ 60 °C) | *Trigger* → *Tambah trigger* |
| Rencana upgrade link / bandwidth | *Kapasitas port* (tandai dulu port penting sebagai *pantau* di halaman perangkat) |
| SLA per layanan untuk manajemen | *SLA layanan* → *Tambah layanan* → tambah komponen (pilih perangkat, isi minimal hidup) |
| Catat gangguan besar, penanggung jawab, penyebab & tindakan | *Insiden* (otomatis) atau *Problem* → centang → *Buat insiden* |
| Jadwal pekerjaan (alarm tidak dikirim) | *Maintenance* |
| Susun Ringkasan sendiri | *Ringkasan → Atur tampilan* |
| Arti warna/severity, cara ACK, istilah | Tombol **?** di kanan atas / menu *Panduan* |
| Pindah ke VM baru | *Ekspor & impor* (dengan rahasia + frasa sandi) atau backup database + `.env` yang sama |

## Tes otomatis (CI)

Tiap push ke GitHub menjalankan `.github/workflows/ci.yml`: format dan vet Go, tes collector termasuk tes integrasi
dengan PostgreSQL sungguhan, tes web (PHPUnit, termasuk audit hak akses seluruh rute), build aset, dan build kedua image Docker.
Menjalankan sendiri di mesin pengembang (database tes wajib berakhiran `_test`):

```bash
cd collector && ANTAREJA_TEST_DATABASE_URL=postgres://USER:PASS@HOST:5432/antareja_go_test?sslmode=disable go test -p 1 ./...
```

```bash
cd web && DB_HOST=HOST DB_USERNAME=USER DB_PASSWORD=PASS php vendor/bin/phpunit
```

## Operasional server

Semua dari folder `deploy`:

| Perintah | Fungsi |
|---|---|
| `./antareja.sh cek` | Periksa pemasangan: `.env`, layanan, web, perangkat up, backup terakhir (dari web: *Administrasi → Kesehatan sistem*) |
| `./antareja.sh up` | Jalankan / terapkan perubahan |
| `./antareja.sh update` | Ambil versi terbaru dari Git lalu jalankan ulang (migrasi database otomatis) |
| `./antareja.sh status` | Status container |
| `./antareja.sh logs collector` | Log (collector, web, backup, db, metrics) |
| `./antareja.sh backup` | Backup database sekarang |
| `./antareja.sh backups` | Daftar berkas backup |
| `./antareja.sh restore <berkas>` | Pulihkan database (minta ketik `PULIHKAN`) |

**Update:** sebelum update, jalankan `./antareja.sh backup`. Lalu `./antareja.sh update` dan `./antareja.sh cek`.
Apa yang berubah tiap versi, termasuk port baru yang perlu dibuka: [`CHANGELOG.md`](CHANGELOG.md).

**Pindah / pulihkan ke VM baru:** pasang seperti langkah 1–5 memakai **`.env` lama** (kunci yang sama), salin berkas
`.dump` ke folder backup, lalu `./antareja.sh restore <berkas>`. Alternatif tanpa riwayat: *Ekspor & impor*.

**Uji pemulihan** minimal sekali setelah pemasangan (di VM uji), agar yakin backup bisa dipakai.

## Pengaturan `.env`

| Variabel | Bawaan | Arti |
|---|---|---|
| `DB_PASSWORD` | — (wajib, acak) | Password database internal |
| `ANTAREJA_APP_KEY` | — (wajib, acak) | Kunci enkripsi kredensial & sesi. **Simpan cadangannya** |
| `APP_URL` | — | Alamat web untuk tautan di notifikasi/laporan, mis. `https://10.10.0.50` atau `https://nms.perusahaan.co.id` (tambahkan `:PORT` bila `HTTPS_PORT` bukan 443) |
| `HTTP_PORT` / `HTTPS_PORT` | `80` / `443` | Port web; akses `http://IP-VM` dialihkan otomatis ke HTTPS. Ganti bila bentrok dengan aplikasi lain di VM yang sama (lihat [Mengganti port](#mengganti-port)) |
| `SYSLOG_PORT` | `514` | Port syslog (UDP & TCP) |
| `PING_INTERVAL` | `60s` | Jarak antar putaran ping |
| `SNMP_INTERVAL` | `5m` | Jarak antar putaran SNMP |
| `LATENCY_HIGH_MS` | `150` | Ambang kejadian latensi tinggi |
| `METRICS_RETENTION` | `400d` | Lama data grafik disimpan |
| `TZ` | `Asia/Jakarta` | Zona waktu |
| `BACKUP_DIR` | `./backups` | Folder backup (disarankan NAS) |
| `ANTAREJA_TAG` | `0.5.0` | Label versi image |

Ambang problem, retensi syslog, jadwal backup, target SLA, dan sumber peta diatur dari web (admin).

### Mengganti port

Bawaannya web memakai port **80** dan **443**: cukup ketik `http://IP-VM` di browser. Bila port itu sudah dipakai aplikasi
lain di VM yang sama (mis. NMS lama), pilih port lain di `deploy/.env`, contoh:

```
HTTP_PORT=8080
HTTPS_PORT=8443
APP_URL=https://10.10.0.50:8443
```

Lalu terapkan:

```bash
cd /opt/antareja/deploy && ./antareja.sh up
```

Akses menjadi `http://IP-VM:8080` (dialihkan ke `https://IP-VM:8443`). Sesuaikan juga aturan firewall. Port syslog
diganti dengan `SYSLOG_PORT` dengan cara yang sama (perangkat lalu mengirim ke port tersebut).

## Collector site

Dipakai bila sebuah site (pit, jetty, camp) terhubung ke server pusat lewat jalur yang bisa putus (radio, VSAT).
Tanpa collector site, putusnya jalur membuat semua perangkat site itu tampak down. Dengan collector site, perangkat
diukur dari site, hasilnya diantrekan saat jalur putus, dan pusat hanya menampilkan satu peringatan.

1. Di web pusat: *Administrasi → Collector site → Tambah collector*. Salin token yang tampil (hanya sekali).
2. Pindahkan perangkat: tombol *Pindahkan ke collector ini* (semua perangkat site-nya), kolom *Dipantau dari* di form
   perangkat, atau ubah massal di daftar Perangkat.
3. Di VM atau mini PC Linux di site (Docker terpasang, bisa menjangkau perangkat lokal dan web pusat lewat HTTPS):
   salin folder `deploy/site` dan image collector, lalu:

```bash
cp .env.example .env
```

   Isi `ANTAREJA_CENTRAL_URL` dan `ANTAREJA_SITE_TOKEN`. Bila sertifikat pusat dari CA internal kantor, isi
   `CENTRAL_CA_FILE` dengan path berkas CA (PEM). Verifikasi sertifikat tidak bisa dimatikan.

```bash
docker compose up -d
```

4. Dalam satu menit status di halaman collector menjadi *terhubung*. Log: `docker compose logs -f`.

Catatan:
- Mesin site tidak perlu database. Kredensial SNMP diterima dari pusat dan hanya disimpan di memori; daftar perangkat
  di disk (untuk tetap ping setelah restart saat jalur putus) tanpa kredensial.
- Collector site juga menjalankan **cek layanan**, membaca **Modbus**, dan scrape **exporter Prometheus** perangkat miliknya; hasilnya ikut diantrekan saat jalur putus.
- Backup konfigurasi SSH, cek gambar CCTV, alat diagnosa, discovery, dan tombol *Uji sekarang* / *Uji baca* / *Uji scrape* tetap berjalan dari server pusat.
- Jam site sebaiknya disinkronkan NTP; selisih lebih dari 30 detik dikoreksi otomatis dan ditampilkan di halaman collector.
- Hapus collector: perangkatnya kembali dipantau server pusat.

## Peran pengguna

| Peran | Hak |
|---|---|
| **Admin** | Semua: pengguna, kebijakan login (2FA, LDAP), collector site, notifikasi, laporan terjadwal, backup, tautan status, ekspor/impor, pengaturan, jejak audit |
| **Engineer** | Kelola perangkat & site, uji koneksi, discovery, impor CSV, backup konfigurasi, CCTV, IPAM, maintenance, ACK problem, posisi peta |
| **Viewer** | Lihat saja (layar NOC, manajemen, pemantau) |

## Pemecahan masalah

| Gejala | Periksa |
|---|---|
| Web tidak terbuka | `./antareja.sh cek`; `./antareja.sh logs web`; firewall Proxmox port 80/443; port sudah dipakai aplikasi lain? (`sudo ss -ltnp \| grep -E ':(80\|443) '`) → lihat [Mengganti port](#mengganti-port) |
| Semua perangkat *down* padahal hidup | VM bisa ping perangkat? (`ping IP` dari VM); `./antareja.sh logs collector` |
| SNMP gagal | Community/user v3 benar? ACL perangkat mengizinkan IP VM? Coba `snmpwalk -v2c -c COMMUNITY IP .1.3.6.1.2.1.1` dari VM |
| Backup konfigurasi "host key berubah" | Perangkat diganti/di-reset? Bila ya, tekan *Reset host key* di halaman perangkat |
| Syslog kosong | Perangkat mengirim ke IP VM port 514? Firewall? IP pengirim sama dengan IP perangkat terdaftar? (lihat *Log → Belum terdaftar*) |
| Notifikasi tidak terkirim | *Notifikasi → Kirim uji*; VM bisa keluar ke `api.telegram.org` / server SMTP? |
| Peta abu-abu tanpa gambar | Browser bisa akses `tile.openstreetmap.org`? Bila tidak ada internet, isi server tile internal di *Atur posisi → Peta dasar* |
| CCTV tidak tampil | User/password RTSP benar? Port 554 dari VM ke NVR terbuka? Lihat status kamera di *CCTV* |
| Collector site *tidak melapor* | Di site: `docker compose logs collector`. "token salah": ganti token di pusat lalu pasang di `.env` site. "x509": isi `CENTRAL_CA_FILE`. Jaringan putus: hasil tetap diantrekan dan dikirim saat pulih |
| HP hilang (2FA) | Pakai salah satu kode cadangan. Bila habis, admin membuka akun di *Pengguna* lalu *Reset 2FA* |
| Login LDAP gagal | *Pengguna → Kebijakan login → Uji koneksi* dengan akun uji; akun bukan anggota grup mana pun ditolak (lihat jejak audit) |
| Lupa password admin | Admin lain mereset di *Pengguna*. Bila tidak ada admin lain: hubungi pengembang (reset lewat database) |
| Disk penuh | `df -h`; kurangi retensi syslog (*Log → Pengaturan*), `METRICS_RETENTION`, jumlah backup disimpan |

## Perintah collector (diagnosa)

Perangkat biasanya dikelola dari web. Perintah berikut untuk diagnosa; kredensial **selalu dibaca dari input** (tidak
tersimpan di riwayat shell). Jalankan dari folder `deploy`.

Uji koneksi tanpa menyimpan:

```bash
docker compose exec collector antareja-collector test --host 10.10.1.1 --snmp-stdin
```

Daftar perangkat & status:

```bash
docker compose exec collector antareja-collector device list
```

Kejadian terbaru:

```bash
docker compose exec db psql -U antareja -c "select at, kind, message from events order by at desc limit 20"
```

## Struktur repositori & pengembangan

```
collector/          engine Go: ping, SNMP, problem, notifikasi, syslog, discovery, backup konfigurasi SSH,
                    cek CCTV, laporan terjadwal, API internal
web/                aplikasi web Laravel 12 (app/, resources/views, resources/css, resources/js)
db/migrations/      skema PostgreSQL (satu-satunya sumber skema; dijalankan otomatis oleh collector)
deploy/             Docker Compose produksi, antareja.sh, backup, go2rtc, test/ (agen SNMP uji)
docs/               RANCANGAN.md, KEAMANAN.md, PROFIL-PERANGKAT.md, CLEAN-ROOM.md, brand/ (logo)
```

Uji & build:

```bash
cd collector && go vet ./... && go test ./...
```

```bash
cd web && composer install && npm install && npm run build
```

Tes web (PHPUnit) memakai PostgreSQL sungguhan di database terpisah yang **wajib** berakhiran `_test` (tes menolak
berjalan di database lain, karena skemanya dibangun ulang dari `db/migrations/*.sql`). Contoh dengan database stack
yang sedang berjalan — buat database uji sekali:

```bash
cd deploy && docker compose exec db psql -U antareja -c "create database antareja_test"
```

Lalu jalankan dari mesin pengembang (PHP 8.3+ dengan `pdo_pgsql`, dependensi dev Composer), dengan `DB_HOST` = IP
container `db` atau PostgreSQL lain:

```bash
cd web && DB_HOST=172.18.0.4 DB_USERNAME=antareja DB_PASSWORD=... php vendor/bin/phpunit
```

Yang diuji: hak akses tiap peran di semua halaman, setup awal sekali saja, batas login, akun nonaktif, header keamanan,
tidak ada skrip inline di template, tautan status, ekspor/impor & enkripsi frasa sandi, susunan Ringkasan.

Lingkungan uji dengan agen SNMP palsu (community `uji-tambang`, user v3 `ujiv3`):

```bash
cd deploy && docker compose -f compose.yml -f test/compose.test.yml up -d --build
```

Aturan pengembangan: baca [`docs/CLEAN-ROOM.md`](docs/CLEAN-ROOM.md) sebelum menambah dependensi/profil, dan
[`docs/KEAMANAN.md`](docs/KEAMANAN.md) (mis. tanpa skrip inline di template).
