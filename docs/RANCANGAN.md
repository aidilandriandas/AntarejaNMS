# Rancangan ANTAREJA NMS (engine mandiri)

Status: **Tahap 3 awal selesai** (profil & sensor) · menunggu verifikasi perangkat lapangan · Dokumen hidup, perbarui setiap ada keputusan desain.

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
- Collector hanya baca (lihat di atas). Fitur yang mengubah perangkat (mis. backup konfigurasi via
  SSH) wajib persetujuan engineer dan dirancang terpisah.
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
| 4 | IPAM & konflik IP (ARP), CCTV live view, kualitas jaringan, SLA & laporan bulanan, backup NMS | |
| 5 | Alat impor dari `antareja-nms` (perangkat, site, grup) dan peralihan | |
