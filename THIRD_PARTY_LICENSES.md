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
| golang.org/x/net, x/sync, x/sys, x/text, github.com/google/uuid | — | BSD-3-Clause | Dependensi gosnmp / pro-bing |
| Go standard library | 1.25 | BSD-3-Clause | — |

Dependensi turunan (transitif) Go tercantum di `collector/go.sum`; periksa lisensinya dengan
`go-licenses report ./...` sebelum rilis.

## Server terpisah (dijalankan sebagai container, tidak digabung ke kode)

| Komponen | Versi | Lisensi |
|---|---|---|
| PostgreSQL | 17 | PostgreSQL License (permisif) |
| VictoriaMetrics (single-node) | 1.123 | Apache-2.0 |
| net-snmp `snmpd` | — | BSD-like — **hanya** untuk pengujian (`deploy/test`) |
