# Rancangan ANTAREJA NMS (engine mandiri)

Status: **Tahap 6m selesai** (kesehatan sistem) · menunggu verifikasi perangkat lapangan · Dokumen hidup, perbarui setiap ada keputusan desain.

## 1. Sasaran

NMS/NOC untuk infrastruktur ICT tambang yang **sepenuhnya milik sendiri**: memantau ketersediaan,
latensi, reboot, trafik, dan kesehatan perangkat jaringan, radio PTP/wireless, CCTV/NVR, server, dan
UPS; menampilkan layar NOC, topologi, problem & eskalasi. Bukan inventaris aset (sistem terpisah).

Target kapasitas tahap awal: **2.000 perangkat**, ping tiap **60 detik**, SNMP tiap **5 menit**, pada
1 VM 4 vCPU / 8 GB RAM.

## 2. Arsitektur

```
                 ┌────────────────────── VM ANTAREJA ───────────────────────┐
 Perangkat  ICMP │  ┌──────────────┐  status, event   ┌──────────────┐      │
 tambang  ◄──────┼──┤  collector   ├─────────────────►│  PostgreSQL  │◄─┐   │
 (switch,   SNMP │  │  (Go)        │                  │  (konfigurasi│  │   │
 radio,   ◄──────┼──┤  ping, SNMP, │  metrik (time-   │   & status)  │  │   │
 NVR, UPS)       │  │  deteksi     │  series)         └──────────────┘  │   │
                 │  │  reboot      ├─────────────────►┌──────────────┐  │   │
                 │  └──────────────┘                  │VictoriaMetrics│ │   │
                 │                                    │  (grafik)    │  │   │
                 │  ┌──────────────┐   baca/tulis     └──────▲───────┘  │   │
 Browser ◄───────┼──┤  web (tahap  ├───────────────────────────┴──────────┘   │
 HTTPS           │  │  1b, Laravel)│                                         │
                 │  └──────────────┘                                         │
                 └──────────────────────────────────────────────────────────┘
```

- **collector** (Go): satu biner, konkuren (ribuan target/menit). Hanya **membaca** perangkat
  (ICMP echo, SNMP GET/GETBULK). Tidak pernah melakukan SNMP SET atau mengubah konfigurasi perangkat.
- **PostgreSQL**: data konfigurasi (perangkat, site, kredensial terenkripsi) dan status terkini.
- **VictoriaMetrics**: semua angka berkala (RTT, loss, counter trafik, CPU, sinyal radio) untuk grafik.
  Counter disimpan mentah; laju (bps) dihitung saat query dengan `rate()`.
- **web** (tahap 1b): Laravel 12 + desain ANTAREJA; membaca PostgreSQL & VictoriaMetrics (dari server, VictoriaMetrics
  tidak dibuka ke browser). Uji koneksi & "periksa sekarang" diteruskan ke **API internal collector** (`:9274`, hanya
  jaringan Docker; token = HMAC-SHA256 dari `ANTAREJA_APP_KEY`), sehingga web sendiri tidak pernah menghubungi perangkat.

Satu sumber skema: `db/migrations/*.sql`, dijalankan otomatis oleh collector saat start
(tabel `schema_migrations`). Web tidak membuat migrasi sendiri.

## 3. Keamanan

- Kredensial SNMP disimpan **terenkripsi** (AES-256-CBC + HMAC-SHA256, format payload kompatibel
  Laravel `Crypt`) dengan kunci `ANTAREJA_APP_KEY` dari environment. Tidak ada kredensial di kode,
  log, maupun respons API.
- Collector hanya baca (lihat di atas). Backup konfigurasi SSH (tahap 5c) juga hanya baca: perintah dipatok
  per jenis (`/export hide-sensitive`; `terminal length 0` + `show running-config`), tidak ada masukan perintah dari
  pengguna. Host key trust-on-first-use; key berubah → backup ditolak sampai engineer mereset dari web. Password SSH
  dan isi konfigurasi disimpan terenkripsi. Fitur yang **mengubah** perangkat tidak ada dan wajib persetujuan engineer
  bila kelak dirancang.
- Port collector tidak dibuka ke luar; metrik internal hanya untuk jaringan Docker.

## 4. Model data (tahap 1a)

| Tabel | Isi |
|---|---|
| `sites` | Site/lokasi tambang (kode, nama) |
| `devices` | Perangkat: nama, host/IP, site, kategori, pengaturan ping & SNMP (kredensial terenkripsi), status terkini (`up`/`down`/`unknown`), alasan, RTT & loss terakhir, sysName/sysDescr/sysObjectID, uptime |
| `interfaces` | Port per perangkat dari IF-MIB: ifIndex, nama, alias, tipe, kecepatan, status admin/oper, MAC |
| `events` | Kejadian: `down`, `up`, `reboot`, `snmp_fail`, `snmp_ok`, `latency_high`, `flapping` |

## 5. Metrik (VictoriaMetrics)

Label wajib: `device_id`. Label tambahan sesuai jenis (`if_index`, `if_name`).

| Metrik | Satuan | Sumber |
|---|---|---|
| `antareja_ping_rtt_ms` | ms (rata-rata 3 paket) | ICMP |
| `antareja_ping_loss_pct` | % | ICMP |
| `antareja_ping_up` | 1/0 | ICMP |
| `antareja_snmp_uptime_seconds` | detik | sysUpTime |
| `antareja_if_in_octets_total` / `antareja_if_out_octets_total` | counter byte | ifHCIn/OutOctets (fallback 32-bit) |
| `antareja_if_in_errors_total` / `antareja_if_out_errors_total` | counter | ifIn/OutErrors |
| `antareja_if_oper_up` | 1/0 | ifOperStatus |
| `antareja_if_speed_bps` | bps | ifHighSpeed / ifSpeed |

## 6. Aturan status

- **Down** bila ping gagal 100% pada **2 putaran berturut-turut** (± 2 menit); **up** kembali pada
  putaran pertama yang berhasil. Perangkat "SNMP saja" (ping diblok) dinilai dari SNMP.
- **Reboot** bila `sysUpTime` lebih kecil dari pengamatan sebelumnya (memperhitungkan wrap 32-bit
  ±497 hari).
- **Latensi tinggi** bila RTT > ambang (bawaan 150 ms) — dicatat saat melewati ambang, bukan tiap menit.
- **Flapping** bila ≥ 3 kali down dalam 15 menit.

## 7. Problem & notifikasi (tahap 2a)

Engine problem berjalan di collector setelah setiap putaran ping (±1 menit):

| Problem | Kondisi | Severity |
|---|---|---|
| `down` | status down (2 putaran ping gagal, atau SNMP bila ping dimatikan) | High; Core/Distribution/Firewall = Disaster |
| `loss` | rata-rata loss 5 putaran > ambang (bawaan 20%) | Average; ≥ 50% = High |
| `latency` | rata-rata RTT 5 putaran > ambang (bawaan 150 ms) | Warning |
| `snmp` | SNMP gagal ≥ N putaran berturut-turut (bawaan 2) sementara ping normal | Warning |
| `port_down` | port yang ditandai *Pantau* admin-up tetapi oper-down | High |
| `port_util` | port *Pantau* dengan pemakaian ≥ ambang (bawaan 90%) | Average |

- Satu problem terbuka per (perangkat, jenis, port). Problem selesai otomatis saat kondisi hilang.
- Data yang belum cukup (setelah collector restart, sedang menuju down, SNMP baru gagal sekali) dianggap
  *belum diketahui*: problem yang sudah terbuka dibiarkan, tidak ditutup lalu dibuka ulang.
- **Ditekan** (tetap tampil, tidak dinotifikasi): perangkat dalam maintenance, atau salah satu induknya (rantai
  `parent_id`, maks. 16 tingkat) sedang down.
- Notifikasi per kanal: severity minimal, filter site, `delay_minutes` (0 = segera; > 0 = eskalasi, hanya bila
  belum di-ACK), kirim saat pulih. Setiap (problem, kanal, jenis) dikirim sekali. ≥ 5 problem sekaligus → satu
  pesan ringkasan. Kanal yang gagal dicoba lagi setelah 5 menit. Konfigurasi kanal tersimpan terenkripsi.

## 8. Topologi (tahap 2b)

- Tiap putaran SNMP collector membaca `lldpRemTable` (+ `lldpLocPortTable`, `lldpRemManAddrTable`) dan
  `cdpCacheTable`, lalu menyimpannya di `neighbors`. Tetangga dicocokkan ke perangkat terdaftar lewat IP
  manajemen = host, sysName (juga tanpa domain), atau MAC chassis = MAC salah satu port; port lawan lewat
  nama/deskripsi/alias/MAC port.
- Graf web = tetangga yang cocok + `device_links` (manual) + `parent_id` (garis logis bila tak ada sambungan fisik).
  Tetangga yang belum terdaftar tampil sebagai node abu-abu.
- Trafik live: browser mengirim daftar port (maks. 60) tiap 5 detik → web → collector `POST /v1/live` (SNMP GET
  ifHCIn/OutOctets) → browser menghitung bps dari selisih dua pembacaan. Tidak disimpan.

## 9. Peta jalan

| Tahap | Isi | Status |
|---|---|---|
| 1a | Collector Go: ping, SNMP v1/v2c/v3 (system, IF-MIB), deteksi down/up/reboot, skema DB, VictoriaMetrics, Docker Compose | selesai |
| 1b | Web Laravel: setup awal, login & role (admin/engineer/viewer), tambah/ubah perangkat dengan uji koneksi, daftar & detail perangkat + grafik latensi/trafik, site, kejadian, pengguna, jejak audit | selesai |
| 2a | Problem engine, korelasi perangkat induk, maintenance, notifikasi Telegram/email/webhook, eskalasi bila belum di-ACK, ambang dari web | selesai |
| 2b | Topologi dari LLDP-MIB & CISCO-CDP-MIB + sambungan manual + perangkat induk, trafik live (API internal `/v1/live`), Layar NOC | selesai |
| 3 | Profil perangkat (`collector/internal/profiles`, lihat `docs/PROFIL-PERANGKAT.md`): HOST-RESOURCES, ENTITY-SENSOR, MikroTik, Ubiquiti airMAX, Cisco, UPS-MIB, APC; tabel `device_sensors`, problem `sensor`, halaman Sensor. **Sisa:** verifikasi `snmpwalk` lapangan, profil Hikvision, ambang per perangkat | sebagian |
| 4a | Backup NMS (layanan `backup`: pg_dump terjadwal & manual, retensi, unduh dari web, `antareja.sh restore`) dan laporan SLA bulanan (downtime dikurangi jendela maintenance, target, CSV) | selesai |
| 4b | IPAM: ipAddrTable & ipNetToMediaTable (router kategori Core/Distribution/Firewall atau MikroTik), subnet otomatis/manual, peta alamat, IP kosong, cari IP/MAC; konflik IP = 2 MAC bergantian ≥ 3× dalam 6 jam di router & port yang sama → problem `ip_conflict` | selesai |
| 4c | CCTV: go2rtc internal (tanpa port publik), kamera/kanal NVR (password terenkripsi), grid snapshot & live view MSE; nginx hanya membuka `/live/ws` & `/live/frame.jpeg` setelah dicek login Laravel (auth_request); URL RTSP berkredensial tidak pernah ke browser maupun disk | selesai |
| 5a | Cek kesehatan gambar CCTV (collector, lewat go2rtc; gelap / tertutup / beku = sidik seluruh piksel identik 3× / tanpa sinyal setelah 2× gagal) → problem `camera`; impor perangkat dari CSV | selesai |
| 5b | Kualitas jaringan (ping min/maks/jitter, halaman peringkat avg/p95/maks/jitter/loss), ambang per perangkat (`devices.thresholds` JSON: latensi, loss, util, kelas sensor) | selesai |
| 5c | Backup konfigurasi via SSH (MikroTik, Cisco IOS; hanya baca): jadwal 24 jam + manual, normalisasi baris volatil, versi terenkripsi (retensi 50), diff Myers, kejadian `config_change`, TOFU host key + reset | selesai |
| 6a | Syslog: penerima UDP/TCP (port 5514 di container, 514 di VM; collector tetap non-root), urai RFC 3164/5424 toleran, cocok IP → perangkat (host + `device_ips`), batch `COPY` tiap 1 detik / 1.000 baris, batas laju 200/detik (burst 1.000) per IP, antrean 20.000, retensi `syslog.keep_days`, tingkat ≤ `syslog.event_severity` → kejadian `syslog` (maks. 1 per perangkat per 10 menit), indeks trigram untuk cari teks | selesai |
| 6b | Discovery: antrean `discovery_scans` (maks. 3 antre/berjalan, 1 dikerjakan sekaligus), maks. /22, 64 pekerja, ping 2 paket + SNMP v2c GET system (timeout 1,5 s, tanpa retry; opsional juga untuk alamat yang tidak menjawab ping), tebakan vendor/kategori dari nomor enterprise IANA + sysDescr, cocok ke perangkat terdaftar (host + `device_ips`), batal dari web, perangkat dibuat hanya atas pilihan engineer | selesai |
| 6c | Laporan terjadwal: `report_schedules` (harian/mingguan/bulanan, jam kirim, kanal, site), collector menyusun data (rumus SLA yang sama: problem `down` dikurangi jendela maintenance) dan mengirim tiap menit bila jatuh tempo (`last_key` mencegah kirim ganda, jeda 15 menit setelah gagal), email multipart teks+HTML, pratinjau lewat API internal `GET /v1/reports/{id}/preview` (iframe sandbox + CSP) | selesai |
| 6d | Peta lokasi: kolom `lat`/`lng` di `sites` & `devices` (perangkat tanpa koordinat ikut site), Leaflet + penanda divIcon, status site = terburuk dari perangkatnya, garis dari graf topologi bila posisi kedua ujung berbeda, simpan posisi lewat `POST /peta/posisi` (engineer/admin, tercatat di audit), URL tile & atribusi di `settings` (admin) | selesai |
| 6e | Denah: `map_plans` (gambar BYTEA di database → ikut pg_dump; maks. 15 MB, 16.000 px, divalidasi `getimagesize`), `map_plan_points` (piksel x,y per denah, terpisah dari lat/lng), Leaflet `CRS.Simple` + imageOverlay, gambar dilayani dengan ETag; unggah/hapus khusus admin, penempatan engineer/admin | selesai |
| 6f | Peta dasar: kontrol lapisan Jalan (URL admin, bawaan OSM) / Topografi (OpenTopoMap, bisa dimatikan) / Satelit (URL & atribusi dari admin, opsional — wajib atribusi), pilihan diingat per browser; daftar site (urut paling parah) untuk lompat & fokus | selesai |
| 6g | Layar NOC: tampilan *Peta lokasi* (Leaflet dimuat saat pertama ditampilkan, data tiap 30 detik) ikut rotasi otomatis dinding → topologi → peta (bila ada site/perangkat berkoordinat); peta jalan diberi filter gelap di NOC & tema gelap | selesai |
| 6h | Ringkasan per pengguna: `users.dashboard` (JSON urutan + lebar), daftar widget di `App\Support\Dashboard` (kunci tak dikenal/duplikat dibuang), data hanya dihitung untuk widget yang tampil; widget baru: sensor di luar ambang, kamera bermasalah, syslog error, perubahan konfigurasi (tautan diff hanya admin/engineer), maintenance, peta lokasi | selesai |
| 6i | Tautan status: `status_links` (SHA-256 token 40 karakter acak, tautan hanya ditampilkan sekali), cakupan site, kedaluwarsa, cabut, hitung pemakaian; halaman `GET /status/{token}` tanpa login, throttle 60/menit, `noindex`, `no-referrer`, `no-store`, tanpa IP/host; nginx tidak mencatat URL `/status/` (map `$antareja_log`) | selesai |
| 6j | Audit keamanan: header HTTP (CSP, X-Frame-Options, nosniff, HSTS, Referrer/Permissions-Policy), akun nonaktif langsung keluar, sesi lain keluar saat password diganti (`AuthenticateSession`), ringkasan di `docs/KEAMANAN.md` | selesai |
| 6k | CSP ketat untuk skrip (`script-src 'self'`): skrip inline & atribut `on…` dipindah ke `app.js` (`data-confirm`, `data-autosubmit`, `data-print`, `data-autoreload`), inisialisasi tema dari `/theme-init.js` | selesai |
| 6l | Ekspor/impor konfigurasi (`App\Services\ConfigTransfer`, format `antareja-config` v1): rahasia opsional AES-256-GCM + PBKDF2-SHA256 200k dari frasa sandi; impor = pratinjau → terapkan dalam satu transaksi, upsert per kunci alami, nilai divalidasi (kategori, versi/level/protokol SNMP, CIDR, port, koordinat), pengaturan hanya awalan yang diizinkan | selesai |
| 6m | Kesehatan sistem: `system_status` (detak collector tiap menit, ruang disk dari layanan backup tiap 5 menit), `system_alerts` (backup gagal/terlambat 36 jam, layanan backup diam > 20 menit, disk < 10% / < 2 GB, ≥ 3 notifikasi gagal/jam tanpa yang berhasil, syslog dibuang) → kanal non-"Disaster saja": saat muncul, pengingat 6 jam, pulih; ulang 15 menit bila semua kanal gagal. Web: halaman admin, banner semua peran & alarm Layar NOC bila detak > 3 menit | selesai |
| 6n | Halaman *Panduan* untuk operator (semua peran; tombol ? di bilah atas): status & warna, problem & severity, langkah saat down, ACK/eskalasi, maintenance & induk, Layar NOC, ambang sensor bawaan, istilah, tanya jawab — angka diambil dari aturan engine | selesai |
| berikutnya | Verifikasi profil `snmpwalk` lapangan; profil Hikvision | |
