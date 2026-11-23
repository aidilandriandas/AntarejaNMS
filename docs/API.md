# API baca-saja ANTAREJA NMS

Untuk sistem lain (SOC, dashboard manajemen, skrip laporan). API ini **hanya GET**: tidak bisa membuat, mengubah,
atau menghapus apa pun, dan tidak pernah mengirim kredensial (SNMP, SSH, kamera, kanal notifikasi).

## Token

- Dibuat admin di *Administrasi, Token API*. Token tampil **sekali**; yang disimpan hanya hash SHA-256.
- Masa berlaku 30 hari sampai tanpa batas; bisa dibatasi ke IP atau CIDR tertentu (mis. IP server SOC).
- Dicabut kapan saja; permintaan berikutnya langsung ditolak.
- Setiap pemakaian mencatat waktu dan IP terakhir (tampil di daftar token).

```bash
curl -H "Authorization: Bearer ant_xxxxxxxx" https://nms.perusahaan.local/api/v1/problems
```

Token salah, dicabut, kedaluwarsa, atau dari IP yang tidak diizinkan dijawab `401 {"error": "token tidak valid"}`
(alasannya sengaja tidak dibedakan). Metode selain GET dijawab `405`. Batas 120 permintaan per menit per IP (`429`).

## Halaman

Daftar memakai kursor: `limit` (1 sampai 500, bawaan 100), lalu `after_id` dari `next_after_id`.
`next_after_id` bernilai `null` di halaman terakhir. Waktu dalam ISO 8601 dengan zona waktu.

```json
{ "data": [ ... ], "next_after_id": 1532 }
```

## Endpoint

| Endpoint | Saringan | Isi |
| --- | --- | --- |
| `GET /api/v1` | | nama, versi, waktu server, daftar endpoint |
| `GET /api/v1/sites` | | kode, nama, jumlah perangkat aktif, up, down |
| `GET /api/v1/devices` | `status` (up, down, unknown), `site` (kode), `category` | nama, host, site, kategori, status dan alasannya, RTT, loss, status SNMP, sysName, sysDescr, induk, koordinat |
| `GET /api/v1/devices/{id}` | | seperti di atas, ditambah `ports` (indeks, nama, alias, kecepatan, admin/oper, bps masuk/keluar, error dan discard per menit) dan `open_problems` |
| `GET /api/v1/problems` | `status` (open bawaan, resolved, all), `min_severity` (info, warning, average, high, disaster), `since`, `site` | jenis, severity, judul, detail, nilai, mulai, pulih, ACK dan oleh siapa, ditekan maintenance/induk |
| `GET /api/v1/incidents` | `status` (open, handling, recovered, closed; boleh dipisah koma), `since` | judul, severity, status, site, penanggung jawab, penyebab, tindakan, waktu dibuka/ditangani/pulih/ditutup, ID problem |
| `GET /api/v1/events` | `since` (bawaan 24 jam terakhir), `kind` (down, up, reboot, flapping, latency_high, snmp_fail, snmp_ok, config_change, syslog, anomaly) | perangkat, jenis, pesan, durasi, waktu |
| `GET /api/v1/anomalies` | `status` (open bawaan, all), `since` | metrik, port, arah, nilai, patokan, skor, mulai, selesai, ditandai wajar |
| `GET /api/v1/services` | | status sekarang per layanan dan komponen, target, ketersediaan bulan ini, downtime, jumlah gangguan |

## Contoh: menarik problem baru tiap menit

```bash
# simpan id terakhir yang sudah diproses, lalu ambil yang lebih baru
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://nms.perusahaan.local/api/v1/problems?status=all&min_severity=average&after_id=$LAST_ID&limit=500"
```
