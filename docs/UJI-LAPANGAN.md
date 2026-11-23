# Uji lapangan dengan perangkat asli

Semua fitur ANTAREJA diuji di sandbox dengan `snmpd` Linux, SSH palsu, dan kamera RTSP buatan. Daftar ini untuk
memastikan hasilnya sama di jaringan tambang. Kerjakan berurutan; catat yang tidak sesuai beserta berkas diagnosa.

## 1. Sebelum mulai

- Collector bisa menjangkau perangkat: UDP 161 (SNMP), ICMP (ping), TCP 22 (backup konfigurasi), TCP 554 (RTSP kamera).
- Perangkat mengirim syslog ke IP server ANTAREJA port UDP 514 (opsional).
- Akun SNMP **hanya baca** (community read-only atau SNMPv3 tanpa hak tulis). ANTAREJA tidak pernah melakukan SNMP SET.

## 2. Per jenis perangkat

Tambahkan satu perangkat per jenis lewat *Perangkat → Tambah*, tekan **Uji koneksi** di form, simpan, tunggu satu putaran SNMP
(atau tekan *Periksa sekarang*). Lalu periksa:

| Jenis | Yang dicek di halaman perangkat |
| --- | --- |
| MikroTik RouterOS | Profil "MikroTik RouterOS" terdeteksi; CPU, memori, suhu, tegangan; port dan trafik live; tetangga LLDP/MNDP di Topologi; backup konfigurasi SSH (`/export`) |
| Cisco IOS / IOS-XE | Profil Cisco; CPU, memori, sensor ENTITY; tetangga CDP/LLDP; backup `show running-config` |
| Ubiquiti airMAX | Profil airMAX; sinyal, noise, CCQ, kapasitas, jarak link; trafik port radio |
| UPS (UPS-MIB / APC) | Kapasitas baterai, sisa waktu, beban, status sumber listrik |
| Switch lain (generik) | Port dan trafik; LLDP bila diaktifkan; sensor HOST-RESOURCES atau ENTITY-SENSOR bila ada |
| NVR / kamera (Hikvision, Dahua, dll.) | Ping dan status; kamera di menu CCTV (live view, snapshot, cek gambar gelap/beku/tertutup). **Belum ada profil sensor SNMP khusus**, lihat bagian 3 |

## 3. Perangkat yang sensornya belum terbaca

Bila halaman perangkat tidak menampilkan sensor yang seharusnya ada (mis. status HDD NVR, suhu kamera):

1. Buka halaman perangkat, tekan **Diagnosa SNMP** (engineer atau admin). Butuh sampai 1 menit.
2. Berkas JSON berisi: sysDescr, sysObjectID, profil yang cocok, sensor yang terbaca, daftar port, jumlah tetangga,
   MIB standar yang menjawab, dan maksimal 400 OID dari cabang enterprise vendor. Hanya hasil baca; community, user,
   dan password SNMP **tidak ikut**.
3. Periksa isinya sebelum dikirim: beberapa vendor menaruh nama pengguna atau catatan di MIB-nya. Hapus baris yang sensitif.
4. Kirim berkas itu bersama nama model dan versi firmware. Profil baru dibuat dari data asli tersebut, bukan dari tebakan.

## 4. Fitur yang bergantung pada data nyata

| Fitur | Cara memastikan |
| --- | --- |
| Topologi | Tekan **Sinkron LLDP sekarang** di halaman Topologi, tunggu 1 sampai 2 menit. Sambungan antarperangkat muncul; tetangga yang belum terdaftar tampil sebagai kartu "BARU" |
| Trafik live | Centang *Trafik live* di Topologi atau buka halaman port: angka harus mendekati grafik di Winbox/web perangkat |
| Deteksi anomali | Baru menilai setelah 7 hari data. Setelah 2 sampai 3 minggu, buka *Anomali*: bila terlalu banyak yang ternyata wajar, catat contohnya untuk menyetel ambang |
| SLA layanan | Susun layanan (mis. "CCTV Pit A" = uplink, radio PTP, NVR). Matikan satu perangkat redundan: status harus "Tanpa cadangan", bukan "Terganggu" |
| Notifikasi piket | Isi kontak di Profil, buat giliran, centang *Kirim ke yang sedang piket* di kanal, tekan *Kirim uji*: pesan sampai ke orang yang piket |
| Laporan email | *Laporan terjadwal → Pratinjau*: bagian Insiden dan Layanan terisi |

## 5. Yang dilaporkan bila ada masalah

- Nama model, versi firmware, dan berkas diagnosa (bagian 3).
- Tangkapan layar halaman yang salah dan apa yang seharusnya tampil.
- Potongan log collector: `docker compose logs collector --since 30m`.
