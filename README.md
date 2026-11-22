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
| Ping 1 menit | 3 paket per perangkat, paralel (ratusan perangkat sekaligus). Mencatat RTT dan loss |
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
| **Trigger** | Aturan problem sendiri dari data yang sudah dikumpulkan: latensi, loss, nilai sensor per kelas (suhu, sinyal, CCQ, baterai, dll.), trafik masuk/keluar dan pemakaian port (saring nama port/sensor). Operator, ambang, harus bertahan N menit, berlaku untuk semua/site/kategori/perangkat terpilih, jadwal hari & jam (bisa melewati tengah malam). Ikut maintenance, korelasi induk, notifikasi & insiden seperti problem lain |
| **Kapasitas port** | Untuk port yang ditandai *pantau*: puncak harian (persentil 95), pemakaian vs kecepatan port, tren 30/90 hari, dan **perkiraan tanggal penuh** (batas bawaan 80%, bisa diubah admin). Urut dari yang paling mendesak, grafik kecil per port, CSV |
| **SLA layanan** | Ketersediaan per layanan yang dipakai pengguna (mis. *CCTV Pit A*, *Internet kantor*). Layanan = komponen; komponen = perangkat + syarat "minimal N hidup" (jalur seri atau perangkat cadangan). Status sekarang (Normal / Tanpa cadangan / Terganggu), ketersediaan bulanan vs target per layanan, daftar gangguan & komponen penyebab, sisa jatah downtime, riwayat 12 bulan, CSV, widget Ringkasan |
| **Insiden** | Satu gangguan nyata = satu insiden berisi semua problem terkait. Dibuat otomatis (problem Disaster, atau ≥ 3 problem High di satu site dalam 10 menit; anak yang terdampak induk ikut masuk) atau manual (centang problem → *Buat insiden*). Alur: Terbuka → Ditangani (penanggung jawab) → Pulih (otomatis) → Ditutup (wajib isi penyebab & tindakan). Kronologi & catatan tim, MTTR 30 hari, ekspor CSV |
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
| **Tautan status** | Halaman status hanya-baca untuk manajemen tanpa akun (admin membuat tautan rahasia per site/semua, masa berlaku, bisa dicabut): status per site, ketersediaan bulan ini, gangguan, jumlah problem, opsional nama perangkat down. Tanpa IP/konfigurasi; token disimpan sebagai hash & tidak dicatat di log |
| **Ekspor & impor konfigurasi** | Satu berkas JSON: site, perangkat (ambang, induk, koordinat), sambungan manual, subnet, kamera, kanal, laporan terjadwal, pengaturan — untuk pindah VM / VM uji / cadangan yang bisa dibaca. Rahasia opsional, dienkripsi frasa sandi (AES-256-GCM). Impor dengan pratinjau; menambah & memperbarui, tidak menghapus |
| **Kesehatan sistem** | ANTAREJA memantau dirinya sendiri: detak collector, putaran ping/SNMP terakhir, backup (gagal/terlambat), ruang disk folder backup, layanan backup, kegagalan notifikasi, syslog dibuang, ukuran database. Masalah dikirim ke kanal notifikasi (saat muncul, pengingat tiap 6 jam, saat pulih; dicoba ulang 15 menit bila semua kanal gagal). Bila collector berhenti, semua halaman & Layar NOC menampilkan peringatan "data usang" |
| **Topologi** | Peta otomatis dari **LLDP** dan **CDP**, sambungan manual (radio, NVR, kamera), garis putus-putus untuk perangkat induk. Warna garis = pemakaian, **trafik live** tiap 5 detik, perangkat down berdenyut, tetangga yang belum terdaftar tampil abu-abu, tata letak bisa disimpan |
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
| `ANTAREJA_TAG` | `0.2.0-dev` | Label versi image |

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

## Peran pengguna

| Peran | Hak |
|---|---|
| **Admin** | Semua: pengguna, notifikasi, laporan terjadwal, backup, tautan status, ekspor/impor, pengaturan, jejak audit |
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
