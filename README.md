# ANTAREJA NMS

Network Monitoring System untuk infrastruktur ICT tambang. Engine dan aplikasi ditulis sendiri dari nol
(**bukan** fork NMS lain) dan berlisensi tertutup. Lihat `LICENSE`, `docs/CLEAN-ROOM.md`, dan
`THIRD_PARTY_LICENSES.md`.

> **Status: tahap 1a.** Collector (ping + SNMP), database, dan penyimpanan grafik sudah berjalan.
> Antarmuka web menyusul di tahap 1b. Sampai saat itu perangkat dikelola lewat perintah
> `antareja-collector`. Rencana lengkap ada di [`docs/RANCANGAN.md`](docs/RANCANGAN.md).

## Yang sudah bisa (tahap 1a)

| Fitur | Keterangan |
|---|---|
| Ping 1 menit | 3 paket per perangkat, paralel (ratusan perangkat sekaligus). Mencatat RTT dan loss |
| Status down/up | Down setelah 2 putaran gagal berturut-turut (±2 menit). Kejadian *up* mencatat lama down |
| SNMP v1/v2c/v3 | sysName, sysDescr, sysObjectID, lokasi, uptime. SNMPv3: MD5/SHA/SHA2 dan DES/AES |
| Deteksi reboot | Dari turunnya sysUpTime. Wrap counter 497 hari tidak dihitung sebagai reboot |
| Port & trafik | IF-MIB: nama, alias, kecepatan, status, MAC, counter byte & error 64-bit |
| Kejadian | down, up, reboot, flapping (≥3× down / 15 menit), latensi tinggi (>150 ms), SNMP gagal/pulih |
| Grafik | Semua angka tersimpan di VictoriaMetrics (bawaan 400 hari) |
| Keamanan | Kredensial SNMP terenkripsi AES-256 di database. Hanya membaca perangkat, tanpa SNMP SET. Container tanpa root |

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

## Mengelola perangkat (sementara, sebelum ada web)

Kredensial **selalu dibaca dari input**, tidak pernah ditulis di argumen, agar tidak tersimpan di
riwayat shell.

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

## Pengaturan (`deploy/.env`)

| Variabel | Bawaan | Arti |
|---|---|---|
| `PING_INTERVAL` | `60s` | Jarak antar putaran ping |
| `SNMP_INTERVAL` | `5m` | Jarak antar putaran SNMP |
| `LATENCY_HIGH_MS` | `150` | Ambang kejadian latensi tinggi |
| `METRICS_RETENTION` | `400d` | Lama data grafik disimpan |
| `TZ` | `Asia/Jakarta` | Zona waktu log |

## Struktur repositori

```
collector/          engine Go (ping, SNMP, status, kejadian, metrik)
  cmd/antareja-collector/   program utama & perintah CLI
  internal/                 config, secret (enkripsi), ping, snmp, metrics, store, poller, migrate
db/migrations/      skema PostgreSQL (satu-satunya sumber skema)
deploy/             Docker Compose produksi + test/ (agen SNMP uji)
docs/               RANCANGAN.md (arsitektur & peta jalan), CLEAN-ROOM.md (aturan hak cipta)
```

## Pengembangan

```bash
cd collector && go vet ./... && go test ./...
```

Lingkungan uji dengan agen SNMP palsu (community `uji-tambang`, user v3 `ujiv3`):

```bash
cd deploy && docker compose -f compose.yml -f test/compose.test.yml up -d --build
```

Sebelum menambah dependensi atau profil perangkat, baca `docs/CLEAN-ROOM.md`.
