# Uji beban

Tujuan: memastikan satu VM ANTAREJA sanggup memantau ratusan sampai seribu perangkat, dan menemukan hambatan
sebelum dipakai di lapangan.

## Hasil (24 November 2026)

Lingkungan: 1 VM, 4 vCPU, 16 GB RAM, Docker Compose standar. Interval uji ping 20 detik dan SNMP 2 menit
(lebih rapat dari bawaan 1 menit dan 5 menit, supaya beban lebih berat).

Beban: **1.012 perangkat** (1.000 tiruan + perangkat uji lain), tiap perangkat tiruan menjawab SNMP v2c dengan
24 port (counter 64-bit terus naik), 1 alamat IP, dan 10 MAC di tabel Q-BRIDGE. Total 24.000 port, 10.000 MAC,
103.000 seri metrik di VictoriaMetrics. Pohon induk: 10 core, 90 distribusi, 900 akses di 10 site.

| Ukuran | Hasil |
| --- | --- |
| Putaran ping 1.012 perangkat (3 paket) | 2,6 sampai 2,7 detik |
| Putaran SNMP 1.002 perangkat (system, ifTable/ifXTable, IP, FDB, sensor) | 46,5 detik (bawaan interval 5 menit) |
| Puncak CPU collector | 108% (dari 400%) |
| Memori collector | 44 MB |
| Puncak CPU PostgreSQL | 96% (sebelum perbaikan indeks: 211%) |
| Memori PostgreSQL / VictoriaMetrics | 209 MB / 97 MB |
| Ukuran database setelah pengisian | 193 MB |

Waktu jawab server web (1.012 perangkat, login admin):

| Halaman | Server | Total di browser |
| --- | --- | --- |
| Ringkasan | 92 ms | 148 ms |
| Perangkat (daftar) | 129 ms | 260 ms |
| Problem | 92 ms | 168 ms |
| Dampak | 83 ms | 153 ms |
| Layar NOC data (tiap 15 detik) | 417 ms | 592 ms |
| Topologi data | 192 ms | 268 ms |
| Peta data | 300 ms | 321 ms |
| Kualitas jaringan | 438 ms | 910 ms |
| IPAM / cari MAC | 125 / 102 ms | 280 / 180 ms |

## Hambatan yang ditemukan dan diperbaiki

- **Pencocokan ulang tetangga LLDP/CDP** (`RematchNeighbors`, tiap akhir putaran SNMP) memeriksa semua perangkat dan
  semua interface untuk setiap tetangga yang belum dikenal, sekitar 20 detik per putaran pada 24.000 port dan
  membuat PostgreSQL memakai 2 core. Ditulis ulang menjadi pencarian bertahap lewat indeks (migrasi 0037):
  puncak CPU database turun dari 211% ke 96%.

## Batasan uji ini

- Perangkat tiruan menjawab dari loopback (latensi mendekati nol). Di lapangan, putaran SNMP lebih lama karena
  latensi radio/VSAT dan perangkat yang lambat; perangkat yang tidak menjawab memakan batas waktu SNMP
  (bawaan 3 detik x 2 percobaan) tetapi dikerjakan paralel, jadi tidak menahan yang lain.
- Tidak menguji syslog dan trap bervolume tinggi sekaligus (keduanya punya batas laju per pengirim).
- Ukuran database dan VictoriaMetrics akan tumbuh dengan riwayat; lihat retensi di README.

## Mengulang uji

Simulator ada di `collector/cmd/antareja-loadsim` (hanya untuk uji, tidak ikut image produksi).

```bash
cd collector && CGO_ENABLED=0 go build -o /tmp/loadsim ./cmd/antareja-loadsim
docker run -d --name loadsim --network container:antareja-core-collector-1 -v /tmp/loadsim:/loadsim:ro \
  --entrypoint /loadsim antareja-collector:0.5.0 -n 1000 -port 20000 -ifaces 24 -macs 10 -community uji
```

Lalu tambahkan perangkat dengan host `127.(1+i/250).(i%250).(1+i%200)`, port SNMP `20000+i`, community `uji`
(lihat skrip SQL contoh di riwayat commit uji beban). Hapus perangkat uji dan container `loadsim` setelah selesai.
