# Komponen pihak ketiga

Hanya komponen berlisensi **permisif** (MIT, BSD, Apache-2.0, ISC, PostgreSQL License) yang boleh
digabung ke kode ANTAREJA NMS. Komponen GPL/AGPL/LGPL/SSPL **dilarang** di dalam kode; server terpisah
yang hanya diakses lewat jaringan dicatat di tabel kedua.

Setiap dependensi baru wajib dicatat di sini **sebelum** di-commit (lihat `docs/CLEAN-ROOM.md`).

## Digabung ke dalam program

| Komponen | Versi | Lisensi | Dipakai untuk |
|---|---|---|---|
| github.com/gosnmp/gosnmp | 1.47.0 | BSD-2-Clause | Klien SNMP v1/v2c/v3 (collector) |
| github.com/prometheus-community/pro-bing | 0.9.1 | MIT | Ping ICMP (collector) |
| github.com/jackc/pgx/v5 | 5.13.0 | MIT | Driver PostgreSQL (collector) |
| github.com/jackc/puddle/v2, pgpassfile, pgservicefile | — | MIT | Bagian dari pgx |
| golang.org/x/crypto (ssh) | 0.53.0 | BSD-3-Clause | Klien SSH backup konfigurasi (collector) |
| golang.org/x/net, x/sync, x/sys, x/text, github.com/google/uuid | — | BSD-3-Clause | Dependensi gosnmp / pro-bing |
| Go standard library | 1.25 | BSD-3-Clause | — |

| Laravel Framework + dependensinya (Symfony, Guzzle, Carbon, dll.) | 12.x | MIT / BSD-3-Clause / Apache-2.0 | Aplikasi web (`web/composer.lock`) |
| nette/utils, nette/schema | — | Lisensi ganda BSD-3-Clause **atau** GPL — dipakai di bawah **BSD-3-Clause** | Dependensi Laravel |
| uPlot | 1.6 | MIT | Grafik |
| vis-network, vis-data | 9.1 / 7.1 | Lisensi ganda Apache-2.0 **atau** MIT — dipakai di bawah **MIT** | Peta topologi |
| IBM Plex Sans / IBM Plex Mono (@fontsource) | 5.x | SIL Open Font License 1.1 | Huruf antarmuka |
| Vite, laravel-vite-plugin | 7.x / 2.x | MIT | Alat build aset (tidak ikut terpasang di server) |

Dependensi turunan tercantum di `collector/go.sum` dan `web/composer.lock`. Periksa sebelum rilis:
`go-licenses report ./...` (collector) dan `composer licenses --no-dev` (web).

## Server terpisah (dijalankan sebagai container, tidak digabung ke kode)

| Komponen | Versi | Lisensi |
|---|---|---|
| PostgreSQL | 17 | PostgreSQL License (permisif) |
| VictoriaMetrics (single-node) | 1.123 | Apache-2.0 |
| webdevops/php-nginx (image dasar web: PHP-FPM 8.4 + nginx) | 8.4-alpine | MIT (image); PHP License, BSD (nginx) |
| go2rtc (server media CCTV: RTSP → MSE/WebSocket) | 1.9.9 | MIT; image resminya memuat ffmpeg (LGPL/GPL) sebagai program terpisah, tidak digabung ke kode ANTAREJA |
| net-snmp `snmpd` | — | BSD-like — **hanya** untuk pengujian (`deploy/test`) |
