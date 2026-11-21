# Aturan Clean-Room ANTAREJA NMS

Tujuan: seluruh kode ANTAREJA NMS adalah **ciptaan asli** tim, sehingga dapat dicatatkan hak ciptanya
dan dilisensikan tertutup (proprietary). Aturan ini wajib dipatuhi setiap kontributor.

## 1. Sumber yang DILARANG dibuka/disalin saat menulis kode

- Kode sumber **LibreNMS**, **Observium**, **Zabbix**, **Cacti**, **SmokePing**, **Nagios/Icinga**,
  **PRTG**, atau NMS lain — termasuk file definisi perangkat (YAML/JSON/OS definitions, sensor
  definitions, template) dan skema basis datanya.
- Repositori `antareja-nms` (fork LibreNMS) — kode di sana berlisensi GPLv3. **Desain tampilan dan
  ide fitur** boleh dijadikan acuan (ide tidak dilindungi hak cipta), tetapi **kode tidak disalin**:
  semua ditulis ulang dari nol.
- Potongan kode dari forum/Stack Overflow tanpa lisensi permisif yang jelas.

## 2. Sumber yang BOLEH dipakai

- Standar terbuka: RFC SNMP (RFC 3411–3418), RFC 2863 (IF-MIB), RFC 2790 (HOST-RESOURCES-MIB),
  IEEE 802.1AB (LLDP-MIB), RFC 3433 (ENTITY-SENSOR-MIB), RFC 792 (ICMP), dsb.
- File MIB dan dokumentasi resmi vendor (MikroTik, Cisco, Ubiquiti, Hikvision, Ruijie, dll.).
- Hasil pengamatan langsung pada perangkat milik sendiri (`snmpwalk` ke perangkat tambang).
- Pustaka berlisensi permisif yang terdaftar di `THIRD_PARTY_LICENSES.md`.

## 3. Prosedur

1. Dependensi baru → cek lisensi → catat di `THIRD_PARTY_LICENSES.md` di commit yang sama.
2. Profil perangkat baru (`profiles/`) → cantumkan sumbernya di header file
   (nama MIB + versi, atau "snmpwalk perangkat X firmware Y, tanggal").
3. Setiap commit memakai identitas Git yang jelas. Riwayat Git adalah bukti kronologi penciptaan —
   jangan di-*squash* atau di-*force-push* ke cabang `main`.
4. Keputusan desain penting dicatat di `docs/` (siapa, kapan, alasan).

## 4. Catatan pencatatan hak cipta (DJKI)

- Program komputer dilindungi otomatis oleh UU No. 28/2014; pencatatan di DJKI
  (e-hakcipta.dgip.go.id) berfungsi sebagai bukti.
- Lampiran yang lazim: uraian ciptaan, contoh tampilan, potongan kode sumber, surat pernyataan
  kepemilikan, dan (bila pemegang hak adalah perusahaan) surat pengalihan hak / akta badan hukum.
- Pastikan dengan bagian legal/konsultan HKI: siapa pemegang hak (perusahaan atau perorangan, sesuai
  perjanjian kerja) dan cara mencantumkan kontribusi alat bantu (termasuk AI) dalam pengembangan.
