# ANTAREJA NMS

Network Monitoring System untuk infrastruktur ICT tambang. Engine dan aplikasi ditulis sendiri dari nol
(**bukan** fork NMS lain) dan berlisensi tertutup. Lihat `LICENSE`, `docs/CLEAN-ROOM.md`, dan
`THIRD_PARTY_LICENSES.md`.

> **Status: tahap 3 (awal) selesai.** Collector, aplikasi web, problem engine, notifikasi/eskalasi, layar NOC, peta
> topologi, dan profil perangkat (MikroTik, Ubiquiti airMAX, Cisco, UPS) sudah berjalan. Profil vendor perlu
> diverifikasi dengan perangkat asli — lihat [`docs/PROFIL-PERANGKAT.md`](docs/PROFIL-PERANGKAT.md). Rencana lengkap ada di [`docs/RANCANGAN.md`](docs/RANCANGAN.md).

## Yang sudah bisa

| Fitur | Keterangan |
|---|---|
| Ping 1 menit | 3 paket per perangkat, paralel (ratusan perangkat sekaligus). Mencatat RTT dan loss |
| Status down/up | Down setelah 2 putaran gagal berturut-turut (±2 menit). Kejadian *up* mencatat lama down |
| SNMP v1/v2c/v3 | sysName, sysDescr, sysObjectID, lokasi, uptime. SNMPv3: MD5/SHA/SHA2 dan DES/AES |
| Deteksi reboot | Dari turunnya sysUpTime. Wrap counter 497 hari tidak dihitung sebagai reboot |
| Port & trafik | IF-MIB: nama, alias, kecepatan, status, MAC, counter byte & error 64-bit |
| Kejadian | down, up, reboot, flapping (≥3× down / 15 menit), latensi tinggi (>150 ms), SNMP gagal/pulih |
| Grafik | Semua angka tersimpan di VictoriaMetrics (bawaan 400 hari) |
| Keamanan | Kredensial SNMP terenkripsi AES-256 di database. Hanya membaca perangkat, tanpa SNMP SET. Collector tanpa root |
| **Web** | Setup awal (akun admin pertama), login dengan pembatas percobaan, peran Admin / Engineer / Viewer |
| Perangkat | Tambah/ubah dengan **uji koneksi** (ping + SNMP) sebelum simpan, daftar dengan filter (status, site, kategori), yang bermasalah di atas |
| Detail perangkat | Latensi, loss, uptime, info SNMP, grafik latensi/loss dan **trafik per port** (6 jam–30 hari), kejadian |
| Ringkasan | Ketersediaan, online/down/SNMP gagal, daftar down, latensi tertinggi, kejadian 24 jam, status per site |
| Lainnya | Site, riwayat kejadian dengan filter, pengguna, jejak audit, ganti password, tema terang/gelap, tampilan HP |
| **Problem** | Dinilai tiap menit: perangkat down, packet loss & latensi (rata-rata 5 menit), SNMP gagal, port dipantau putus/penuh. Selesai otomatis saat pulih; ACK dengan catatan |
| Korelasi | Isi *perangkat induk* (uplink). Bila induk down, problem anak ditandai **terdampak** dan tidak membanjiri notifikasi |
| Maintenance | Jadwal per perangkat, per site, atau semua; problem di dalamnya tetap tercatat tetapi tidak dinotifikasi |
| Notifikasi | **Telegram**, **Email (SMTP)**, **Webhook** (gateway WhatsApp, n8n, sistem tiket). Per kanal: severity minimal, site, kirim saat pulih. Banyak problem sekaligus → 1 pesan ringkasan |
| Eskalasi | Kanal dengan jeda (mis. 30 menit) hanya dikirimi problem yang **belum di-ACK** selama itu — untuk supervisor/manajemen |
| **Profil & sensor** | Deteksi otomatis: MikroTik (suhu, tegangan, sinyal/CCQ/rate wireless), Ubiquiti airMAX (sinyal, CCQ, quality, capacity, jarak link), Cisco (CPU, memori, suhu), UPS-MIB & APC (di baterai, muatan, waktu cadangan, beban), plus CPU/memori/disk/suhu standar. Sensor di luar ambang jadi problem. Halaman **Sensor**: Radio & PTP, Daya & UPS, Sistem & suhu |
| **IPAM** | Subnet otomatis dari IP interface perangkat + manual (nama, site, VLAN); pemakaian dari tabel ARP router/L3; peta alamat /24 berwarna, daftar IP kosong, cari IP/MAC (di router & port mana) |
| **Konflik IP** | Konservatif agar tidak ada alarm palsu: hanya bila di router & port yang sama satu IP bergantian antara tepat dua MAC berulang (bawaan ≥ 3 kali dalam 6 jam). Ganti MAC sekali (DHCP, ganti perangkat) bukan konflik |
| **CCTV** | Grid snapshot semua kamera (tiap 10 detik) dan **live view** di browser (sub-stream hemat / main-stream kualitas tinggi) lewat go2rtc. Tambah satu kamera atau banyak kanal NVR Hikvision sekaligus; password RTSP terenkripsi; video hanya bisa dibuka setelah login |
| **Cek gambar CCTV** | Tiap 15 menit satu gambar per kamera dinilai otomatis: **gelap**, **tertutup/buram** (gambar polos — debu, lumpur, lensa berembun), **beku** (gambar identik persis 3× berturut-turut), **tanpa sinyal** (login RTSP ditolak / kamera tidak menjawab). Kamera yang terkait perangkat menjadi problem |
| **Impor CSV** | Tambah perangkat massal dari CSV/Excel (nama, host, site, kategori, SNMP, community, induk) dengan pratinjau & validasi per baris; site dibuat otomatis |
| **Discovery jaringan** | Pindai subnet (maks. /22) dari web: ping lalu SNMP GET grup *system* — **hanya baca**. Vendor & kategori ditebak dari sysObjectID/sysDescr (MikroTik, Cisco, Ubiquiti, Hikvision, APC, Fortinet, dll.), alamat yang sudah terdaftar ditandai. Engineer meninjau nama/kategori/site lalu memilih yang ditambahkan; community yang menjawab ikut terpasang (terenkripsi, dihapus 7 hari setelah pemindaian) |
| **Kualitas jaringan** | Peringkat link terburuk 24 jam / 7 hari / 30 hari: rata-rata, p95, maksimum latensi, **jitter**, loss, ketersediaan ping. Grafik latensi perangkat dengan pita min–maks (gaya SmokePing) |
| **Ambang per perangkat** | Latensi, loss, port penuh, sinyal radio, CCQ, suhu, CPU, baterai & waktu cadangan UPS bisa diatur khusus per perangkat (mis. radio PTP jarak jauh lebih longgar) |
| **Backup konfigurasi** | MikroTik (`/export hide-sensitive`) & Cisco IOS (`show running-config`) via SSH tiap 24 jam atau tombol *Backup sekarang*. **Hanya baca**: perintah dipatok, tidak bisa diisi pengguna. Versi terenkripsi (50 terakhir), **diff** berwarna, kejadian *konfigurasi berubah*, unduh `.rsc`/`.txt`. Host key dicatat saat pertama; bila berubah backup ditolak sampai engineer mereset. Hanya admin/engineer |
| **Log (syslog)** | Penerima syslog UDP/TCP port 514 (RFC 3164 & 5424, format MikroTik & Cisco). Log dicocokkan ke perangkat lewat IP pengirim (IP manajemen atau IP interface dari IPAM); pengirim belum terdaftar tetap tercatat. Cari teks, filter tingkat/site/program/IP, mode *Live*, perangkat paling banyak error, log terbaru di detail perangkat. Log Critical ke atas → kejadian perangkat. Batas laju per pengirim agar banjir log tidak membebani server. Retensi diatur dari web (bawaan 14 hari) |
| **Backup** | Backup database harian otomatis (jam & jumlah simpanan diatur dari web) + tombol *Backup sekarang*, unduh dari web, pemulihan dengan `./antareja.sh restore`. Arahkan `BACKUP_DIR` ke NAS agar aman bila VM rusak |
| **SLA bulanan** | Ketersediaan per perangkat & per site, downtime, jumlah gangguan, rata-rata waktu pulih; jam maintenance tidak dihitung; target SLA; ekspor CSV (Excel) & cetak/PDF |
| **Laporan terjadwal** | Ringkasan harian (kemarin), mingguan (Senin–Minggu) atau bulanan otomatis ke kanal notifikasi: ketersediaan vs target, per site, perangkat dengan downtime terbesar, yang sedang down, problem baru per severity, reboot, perubahan konfigurasi, syslog error. Email tampil rapi (HTML + teks), Telegram ringkas. Pratinjau & *Kirim sekarang* dari web (admin) |
| **Topologi** | Peta otomatis dari **LLDP** dan **CDP**, sambungan manual (radio, NVR, kamera), garis putus-putus untuk perangkat induk. Warna garis = pemakaian, **trafik live** tiap 5 detik, perangkat down berdenyut, tetangga yang belum terdaftar tampil abu-abu, tata letak bisa disimpan |
| **Peta lokasi** | Site & perangkat berkoordinat (menara radio, NVR) di atas peta: warna = status (down berdenyut), jumlah down, garis sambungan antarperangkat (merah bila putus). Posisi diatur dengan klik/geser di peta atau tempel koordinat. Sumber gambar peta bisa diganti ke server tile internal untuk ruang kontrol tanpa internet |
| **Layar NOC** | Layar penuh untuk TV ruang kontrol (tombol `F`): alarm bertingkat (merah hanya untuk High/Disaster belum di-ACK) dengan **bunyi**, dinding status per site bergantian dengan peta topologi, problem aktif, kejadian 1 jam, port tersibuk. Diperbarui tiap 15 detik |

## Menjalankan (Docker)

Butuh Docker + Docker Compose di VM (mis. Ubuntu 24.04 di Proxmox).

```bash
cd deploy
cp .env.example .env
```

Isi `.env`:

```bash
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" .env
```

```bash
sed -i "s|^ANTAREJA_APP_KEY=.*|ANTAREJA_APP_KEY=base64:$(openssl rand -base64 32)|" .env
```

> **Simpan cadangan `ANTAREJA_APP_KEY`.** Kunci ini dipakai untuk membuka kredensial SNMP.
> Bila hilang, semua kredensial harus diisi ulang.

```bash
docker compose up -d --build
```

Stack ini bernama `antareja-core` sehingga bisa berjalan berdampingan dengan versi lama di VM yang sama.

Buka **https://IP-VM:8443** (port bawaan 8443/8080 agar tidak bentrok dengan aplikasi di 443/80; ubah lewat
`HTTPS_PORT`/`HTTP_PORT` di `.env`). Browser akan memperingatkan sertifikat *self-signed*; pilih lanjutkan. Saat
pertama dibuka muncul **Setup Awal** untuk membuat akun admin. Setelah itu tambahkan site, lalu perangkat lewat
menu *Perangkat → Tambah perangkat* dan tekan **Uji sekarang** sebelum menyimpan.

Layar NOC: buka *Layar NOC* di PC/TV ruang kontrol, login dengan centang *Ingat saya*, tekan `F` untuk layar
penuh, lalu klik tombol **SUARA** sekali agar alarm bisa berbunyi (browser mewajibkan satu klik).

Atur notifikasi di *Administrasi → Notifikasi*. Untuk Telegram: buat bot lewat @BotFather, masukkan bot ke grup
NOC, isi token dan chat ID, lalu tekan **Kirim uji**. Server perlu akses keluar HTTPS ke `api.telegram.org`.

| Peran | Hak |
|---|---|
| Admin | Semua, termasuk pengguna dan jejak audit |
| Engineer | Tambah/ubah/hapus perangkat dan site, uji koneksi, ACK problem, jadwal maintenance |
| Viewer | Hanya melihat (cocok untuk layar ruang kontrol / manajemen) |

## Perintah collector (opsional)

Perangkat biasanya dikelola dari web. Perintah berikut berguna untuk diagnosa atau impor massal lewat skrip.
Kredensial **selalu dibaca dari input**, tidak pernah ditulis di argumen, agar tidak tersimpan di riwayat shell.

Uji koneksi tanpa menyimpan:

```bash
docker compose exec collector antareja-collector test --host 10.10.1.1 --snmp-stdin
```

Tambah perangkat SNMP v2c (ketik community saat diminta):

```bash
docker compose exec collector antareja-collector device add --name "Core Switch Pit A" --host 10.10.1.1 --site PIT-A --category core --snmp-stdin
```

Tambah perangkat SNMPv3 (ketik password auth lalu password privacy):

```bash
docker compose exec collector antareja-collector device add --name "Radio PTP Pit A-B" --host 10.10.2.5 --site PIT-A --category ptp --snmp-stdin --snmp-version 3 --v3-user monitor --v3-level authPriv --v3-auth SHA --v3-priv AES
```

Tambah perangkat hanya-ping:

```bash
docker compose exec collector antareja-collector device add --name "NVR Workshop" --host 10.10.5.20 --site WS --category nvr
```

Daftar perangkat dan status:

```bash
docker compose exec collector antareja-collector device list
```

Kejadian terbaru:

```bash
docker compose exec db psql -U antareja -c "select at, kind, message from events order by at desc limit 20"
```

Log collector:

```bash
docker compose logs -f collector
```

## Operasional sehari-hari (`deploy/antareja.sh`)

| Perintah | Fungsi |
|---|---|
| `./antareja.sh up` | Jalankan / perbarui semua layanan |
| `./antareja.sh update` | `git pull` lalu jalankan ulang |
| `./antareja.sh status` | Status container |
| `./antareja.sh logs collector` | Log (collector, web, backup, db, metrics) |
| `./antareja.sh backup` | Backup database sekarang dan tunggu sampai selesai |
| `./antareja.sh backups` | Daftar berkas backup |
| `./antareja.sh restore <berkas>` | Pulihkan database dari backup (minta konfirmasi `PULIHKAN`) |

**Pindah / pulihkan ke VM baru:** pasang ANTAREJA seperti biasa memakai `deploy/.env` lama (terutama
`ANTAREJA_APP_KEY` yang sama), salin berkas `.dump` ke folder backup, lalu jalankan `./antareja.sh restore <berkas>`.
Simpan salinan `deploy/.env` di tempat aman terpisah: tanpa kunci yang sama, kredensial SNMP dan notifikasi di
dalam backup tidak bisa dibuka.

## Pengaturan (`deploy/.env`)

| Variabel | Bawaan | Arti |
|---|---|---|
| `PING_INTERVAL` | `60s` | Jarak antar putaran ping |
| `SNMP_INTERVAL` | `5m` | Jarak antar putaran SNMP |
| `LATENCY_HIGH_MS` | `150` | Ambang kejadian latensi tinggi |
| `METRICS_RETENTION` | `400d` | Lama data grafik disimpan |
| `TZ` | `Asia/Jakarta` | Zona waktu log & tampilan |
| `BACKUP_DIR` | `./backups` | Folder backup di VM (disarankan folder NAS yang di-mount) |
| `APP_URL` | — | Alamat web, mis. `https://192.168.x.x:8443` |
| `HTTPS_PORT` / `HTTP_PORT` | `8443` / `8080` | Port web (HTTP dialihkan ke HTTPS) |
| `SYSLOG_PORT` | `514` | Port syslog di VM (UDP & TCP). Buka di firewall VM bila perangkat berada di jaringan lain |

## Struktur repositori

```
collector/          engine Go (ping, SNMP, status, kejadian, metrik, API internal)
  cmd/antareja-collector/   program utama & perintah CLI
  internal/                 config, secret (enkripsi), ping, snmp, metrics, store, poller, migrate, api
web/                aplikasi web Laravel 12 (app/, resources/views, resources/css, resources/js)
db/migrations/      skema PostgreSQL (satu-satunya sumber skema)
deploy/             Docker Compose produksi + test/ (agen SNMP uji)
docs/               RANCANGAN.md (arsitektur & peta jalan), CLEAN-ROOM.md (aturan hak cipta)
```

## Pengembangan

```bash
cd collector && go vet ./... && go test ./...
```

```bash
cd web && composer install && npm install && npm run build
```

Lingkungan uji dengan agen SNMP palsu (community `uji-tambang`, user v3 `ujiv3`):

```bash
cd deploy && docker compose -f compose.yml -f test/compose.test.yml up -d --build
```

Sebelum menambah dependensi atau profil perangkat, baca `docs/CLEAN-ROOM.md`.
