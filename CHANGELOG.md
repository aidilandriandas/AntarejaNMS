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
