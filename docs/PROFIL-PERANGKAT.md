# Profil perangkat & sensor

Collector mendeteksi profil tiap perangkat (disimpan, dideteksi ulang tiap 24 jam atau saat sysObjectID berubah),
lalu membaca sensornya pada setiap putaran SNMP. Semua akses hanya baca (GET/WALK). OID yang tidak dijawab
perangkat dilewati tanpa galat. Kode: `collector/internal/profiles/`.

| Profil | Deteksi | Sumber MIB | Yang dibaca |
|---|---|---|---|
| `host-resources` | semua perangkat | HOST-RESOURCES-MIB (RFC 2790), UCD-SNMP-MIB | CPU (rata-rata hrProcessorLoad), memori (UCD bila ada, selain itu hrStorageRam), disk tetap |
| `entity-sensor` | semua perangkat | ENTITY-SENSOR-MIB (RFC 3433), ENTITY-MIB | suhu, tegangan, kipas, daya, arus |
| `mikrotik` | sysObjectID `.1.3.6.1.4.1.14988` atau probe `mtxrHlVoltage` | MIKROTIK-MIB | suhu board/CPU, tegangan input, gauge RouterOS 7; wireless station (sinyal, TX/RX rate, frekuensi), AP (klien, CCQ, noise, frekuensi), klien terlemah; modem LTE (mtxrLTEModemTable: RSRP, RSRQ, SINR, RSSI per interface) |
| `ubiquiti-airmax` | sysObjectID `.1.3.6.1.4.1.41112` atau probe `ubntWlStatSignal.1` | UBNT-AirMAX-MIB | sinyal, CCQ, noise, TX/RX rate, station, airMAX quality & capacity, frekuensi, daya TX, jarak |
| `cisco` | sysObjectID `.1.3.6.1.4.1.9.1` / `.9.12` | CISCO-PROCESS-MIB, CISCO-MEMORY-POOL-MIB, CISCO-ENVMON-MIB | CPU 5 menit, memori processor pool, suhu |
| `ups-mib` | probe `upsBatteryStatus` | UPS-MIB (RFC 1628) | sumber output (normal/baterai/bypass), status & muatan baterai, perkiraan cadangan, beban, tegangan input, suhu baterai |
| `apc` | sysObjectID `.1.3.6.1.4.1.318` atau probe `upsAdvBatteryCapacity` | PowerNet-MIB (APC) | status output, muatan, cadangan, beban, tegangan input, suhu baterai |

Hikvision NVR/kamera belum punya profil khusus: dipantau lewat ping, uptime, port, dan profil generik bila
perangkat menjawab HOST-RESOURCES. Profil khusus dibuat setelah ada `snmpwalk` dari perangkat lapangan.

## Ambang bawaan

| Kelas | Peringatan | Kritis |
|---|---|---|
| CPU | ≥ 80% | ≥ 95% |
| Memori, disk | ≥ 85% | ≥ 95% |
| Suhu (CPU) | ≥ 60 °C (75 °C) | ≥ 75 °C (90 °C) |
| Sinyal radio | ≤ −70 dBm | ≤ −78 dBm |
| CCQ, airMAX quality | ≤ 70% | ≤ 50% |
| Muatan baterai UPS | ≤ 50% | ≤ 20% |
| Perkiraan cadangan UPS | ≤ 15 menit | ≤ 5 menit |
| Beban UPS | ≥ 80% | ≥ 95% |
| UPS di baterai / output mati | — | kritis |

Sensor di luar ambang menjadi problem jenis `sensor` (peringatan → Warning, kritis → High) dan ikut aturan
notifikasi, maintenance, serta korelasi perangkat induk.

## Verifikasi dengan perangkat asli (wajib sebelum dipakai produksi)

Profil vendor ditulis dari dokumen MIB; skala dan satuan bisa berbeda antarversi firmware. Untuk setiap model
perangkat di tambang, jalankan dari server ANTAREJA lalu kirimkan hasilnya ke tim pengembang:

```bash
snmpwalk -v2c -c COMMUNITY -On IP-PERANGKAT .1.3.6.1.2.1.1 > sistem.txt
```

```bash
snmpwalk -v2c -c COMMUNITY -On IP-PERANGKAT .1.3.6.1.4.1 > vendor.txt
```

```bash
snmpwalk -v2c -c COMMUNITY -On IP-PERANGKAT .1.3.6.1.2.1.25 > host-resources.txt
```

Untuk UPS tambahkan `.1.3.6.1.2.1.33`. Bandingkan nilai dengan tampilan perangkat (Winbox, airOS, panel UPS):
bila berbeda, perbaiki skala di berkas profil dan catat model + versi firmware di komentar berkas.

## Modem 4G/LTE

Dibaca dari `mtxrLTEModemTable` MIKROTIK-MIB (`.1.3.6.1.4.1.14988.1.1.16.1.1`, kolom 2 RSSI, 3 RSRQ, 4 RSRP, 7 SINR,
indeks = ifIndex interface LTE, label dari ifName). Nilai 0 dilewati (modem belum terdaftar ke jaringan). Ambang
bawaan: RSRP -105 / -115 dBm, RSRQ -15 / -20 dB, SINR 3 / 0 dB; RSRP dan SINR bisa diatur per perangkat.

Belum didukung: modem Teltonika dan merek lain (MIB-nya belum bisa diverifikasi). Untuk tahu kapan jalur cadangan
LTE dipakai, buat trigger trafik pada port LTE (mis. masuk di atas 1 Mbps selama 5 menit).

## Versi dan jam perangkat

Kode: `collector/internal/snmp/inventory.go`. Hanya GET/WALK.

- **Versi, model, serial** dibaca saat collector mulai lalu tiap 24 jam:
  MikroTik `mtxrLicVersion` (.1.3.6.1.4.1.14988.1.1.4.4.0), `mtxrSerialNumber` (.1.1.7.3.0), `mtxrBoardName`
  (.1.1.7.9.0); lainnya entri chassis ENTITY-MIB (`entPhysicalClass` 3 atau 11; kolom 10 SoftwareRev, 9
  FirmwareRev, 11 SerialNum, 13 ModelName); bila kosong `dot11manufacturerProductVersion` (.1.2.840.10036.3.1.2.1.4);
  terakhir pola "Version x" di sysDescr (Cisco IOS). Versi berubah dicatat sebagai kejadian `firmware`.
- **Selisih jam**: `hrSystemDate` (.1.3.6.1.2.1.25.1.2.0, DateAndTime) dibanding jam collector di titik tengah
  permintaan. Bila perangkat tidak menyertakan zona waktu (8 oktet), selisih kelipatan 15 menit dibuang karena
  perangkat bisa memakai WIB, WITA, atau WIT. Sensor `clock` kelas `clock_skew`: peringatan 60 detik, kritis 300 detik.

## BGP dan OSPF

Kode: `collector/internal/snmp/routing.go`. Dibaca dari router kategori Core, Distribution, Firewall, atau MikroTik.

- BGP4-MIB (RFC 4273) `bgpPeerTable` .1.3.6.1.2.1.15.3.1: kolom 2 state (6 = established), 3 admin status
  (1 = stop), 9 remote AS, 16 FsmEstablishedTime; `bgpLocalAs` .1.3.6.1.2.1.15.2.0. Hanya peer IPv4 (indeks alamat).
- OSPF-MIB (RFC 4750) `ospfNbrTable` .1.3.6.1.2.1.14.10.1: kolom 3 router ID, 6 state (8 = full, 4 = 2-way).
- Walk yang berhasil tetapi tidak lagi memuat peer lama menandai peer itu hilang (bukan dihapus).

## VLAN dan STP

Kode: `collector/internal/snmp/l2.go`. Dibaca dari switch kategori Core, Distribution, dan Access.

- Q-BRIDGE-MIB (RFC 4363): `dot1qVlanStaticName` .1.3.6.1.2.1.17.7.1.4.3.1.1 (indeks VLAN ID), `dot1qPvid`
  .1.3.6.1.2.1.17.7.1.4.5.1.1 (indeks base port, dipetakan ke ifIndex lewat `dot1dBasePortIfIndex`).
- Bila Q-BRIDGE kosong: CISCO-VTP-MIB `vtpVlanName` .1.3.6.1.4.1.9.9.46.1.3.1.1.4 dan `vtpVlanState` (.2, hanya
  operational); VLAN 1002 sampai 1005 dilewati.
- BRIDGE-MIB (RFC 4188): `dot1dStpTimeSinceTopologyChange` .2.3, `dot1dStpTopChanges` .2.4, `dot1dStpDesignatedRoot`
  .2.5, `dot1dStpRootPort` .2.7, `dot1dStpPortState` .2.15.1.3 (di bawah .1.3.6.1.2.1.17).
