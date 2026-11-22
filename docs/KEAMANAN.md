# Keamanan ANTAREJA NMS

Ringkasan kontrol keamanan yang sudah diterapkan, batasan yang diketahui, dan daftar periksa sebelum dipasang.
Perbarui dokumen ini setiap ada fitur yang menyentuh kredensial, perangkat, atau akses dari luar.

## 1. Prinsip

- **Hanya membaca perangkat.** Collector memakai ICMP, SNMP GET/GETBULK, dan SSH dengan perintah tampil yang
  dipatok (`/export hide-sensitive`, `show running-config`). Tidak ada SNMP SET, tidak ada perintah konfigurasi,
  dan pengguna tidak bisa memasukkan perintah sendiri. Fitur yang mengubah perangkat tidak ada; bila kelak dibuat,
  wajib persetujuan engineer.
- **Kredensial tidak pernah ke browser.** Community/USM SNMP, password SSH, RTSP kamera, dan konfigurasi kanal notifikasi
  disimpan terenkripsi (AES-256-CBC + HMAC, kunci `ANTAREJA_APP_KEY`). Form hanya menampilkan "tersimpan".
  Isi konfigurasi perangkat & diff juga terenkripsi di database.
- **Tanpa kredensial di kode.** Semua rahasia dari environment (`deploy/.env`), tidak ada nilai bawaan.

## 2. Akses & sesi

| Kontrol | Penerapan |
|---|---|
| Peran | `admin` (semua), `engineer` (kelola perangkat, site, maintenance, CCTV, IPAM, discovery, backup konfigurasi), `viewer` (lihat saja). Dicek per rute (`can:manage-devices`, `can:admin`), bukan hanya disembunyikan di menu |
| Setup awal | Hanya bila belum ada akun; diperiksa ulang di dalam transaksi |
| Login | Maks. 5 percobaan / menit per username+IP; login gagal dicatat di jejak audit |
| Password | Min. 10 karakter, huruf + angka; ganti password wajib password lama |
| Akun nonaktif | Langsung keluar pada permintaan berikutnya, termasuk sesi "Ingat saya" |
| Ganti password | Semua sesi lain akun itu otomatis keluar (`AuthenticateSession`); juga saat admin mereset password pengguna |
| Admin terakhir | Tidak bisa dinonaktifkan/diturunkan bila tidak ada admin aktif lain |
| Cookie sesi | `Secure`, `HttpOnly`, `SameSite=Lax`; seluruh web dipaksa HTTPS |
| CSRF | Semua POST/PUT/DELETE web memakai token Laravel |
| Jejak audit | Perubahan perangkat, pengguna, kanal, ambang, backup, posisi peta, tautan status, dll. |

## 3. Header HTTP

`Content-Security-Policy` (asal skrip/koneksi/frame/form dibatasi ke server sendiri, `object-src 'none'`,
`frame-ancestors 'self'`), `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: same-origin`, `Permissions-Policy` (kamera/mikrofon/lokasi dimatikan),
`Cross-Origin-Opener-Policy`, `Strict-Transport-Security`. Pratinjau laporan memakai CSP `sandbox` tersendiri.

## 4. Permukaan yang terbuka ke jaringan

| Port | Layanan | Catatan |
|---|---|---|
| 8443/8080 | Web (HTTPS; HTTP dialihkan) | Satu-satunya pintu untuk pengguna |
| 514 UDP/TCP | Penerima syslog | Hanya menerima & menyimpan teks; batas laju per IP |
| — | API collector `:9274`, PostgreSQL, VictoriaMetrics, go2rtc | **Tidak** dipublikasikan; hanya jaringan Docker. Token API = HMAC dari `ANTAREJA_APP_KEY` |

Video CCTV: nginx hanya membuka `/live/ws` & `/live/frame.jpeg` setelah login dicek Laravel; API go2rtc lain
(berisi URL RTSP berkredensial) tidak bisa diakses dari browser.

## 5. Tautan status (tanpa login)

Token acak 40 karakter, disimpan sebagai SHA-256 dan hanya ditampilkan sekali; bisa kedaluwarsa & dicabut;
throttle 60/menit; `noindex`, `no-referrer`, `no-store`; tidak menampilkan IP/host/konfigurasi; URL `/status/`
tidak dicatat di access log nginx. Siapa pun yang memegang tautan bisa membukanya — cabut bila bocor.

## 6. Batasan yang diketahui

- **CSP masih mengizinkan skrip inline** (`'unsafe-inline'`) karena beberapa template memakai atribut `onsubmit`
  dan blok skrip kecil. Semua keluaran template di-escape; perlindungan utama terhadap XSS tetap escaping.
  Perbaikan lanjutan: pindahkan seluruh skrip inline ke `app.js` lalu hapus `'unsafe-inline'`.
- **Syslog UDP bisa dipalsukan.** IP pengirim UDP tidak terautentikasi; log yang tampil "dari perangkat X" bisa dikirim
  pihak lain di jaringan. Perlakukan log sebagai petunjuk, bukan bukti. Batasi port 514 di firewall ke subnet manajemen.
- **Gambar peta diambil browser dari server tile luar** (bawaan OpenStreetMap/OpenTopoMap): server tersebut melihat IP
  publik kantor dan area peta yang dibuka. Pakai server tile internal bila ini tidak diinginkan.
- **Sertifikat HTTPS bawaan self-signed.** Ganti dengan sertifikat kantor; HSTS baru efektif dengan sertifikat tepercaya.
- **SSH host key trust-on-first-use.** Backup pertama mempercayai key yang ditemui; pastikan backup pertama dilakukan
  dari jaringan manajemen yang tepercaya.
- **Discovery** bisa memindai subnet mana pun yang diisi engineer (maks. /22); gunakan hanya untuk jaringan sendiri.

## 7. Daftar periksa pemasangan

1. `deploy/.env`: `DB_PASSWORD` & `ANTAREJA_APP_KEY` acak; simpan cadangan `ANTAREJA_APP_KEY` di tempat aman
   (tanpa kunci ini semua kredensial terenkripsi tidak bisa dibuka).
2. Ganti sertifikat HTTPS dengan sertifikat kantor.
3. Firewall VM: buka 8443/8080 hanya untuk jaringan kantor/NOC, 514 hanya dari subnet manajemen perangkat.
4. Buat akun per orang (bukan akun bersama); viewer untuk layar NOC & pemantau.
5. Arahkan `BACKUP_DIR` ke NAS; uji `./antareja.sh restore` sekali.
6. Community SNMP read-only khusus NMS (bukan `public`), user SSH backup dengan hak baca saja.
