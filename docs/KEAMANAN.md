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
| Verifikasi dua langkah | TOTP (RFC 6238, 30 detik, toleransi 1 langkah); rahasia terenkripsi kunci aplikasi; kode yang sudah dipakai ditolak (langkah terakhir disimpan, baris dikunci saat cek); 5 percobaan salah per 5 menit; 10 kode cadangan disimpan sebagai hash bcrypt, sekali pakai. Langkah kedua kedaluwarsa 5 menit setelah password benar, dan belum ada sesi login sebelum kode benar. Kebijakan wajib (admin/semua) ditegakkan di setiap permintaan; admin hanya bisa mewajibkan bila dirinya sudah memakai 2FA |
| LDAP / AD | Opsional. LDAPS atau StartTLS dengan verifikasi sertifikat (bisa dimatikan untuk CA internal yang belum dipasang); tanpa enkripsi harus dikonfirmasi eksplisit. Username di-escape (`ldap_escape`) sebelum masuk filter. Password akun layanan terenkripsi. Akun lokal tidak pernah dicek ke LDAP dan sebaliknya, jadi admin lokal tetap bisa masuk saat direktori mati. Akun yang dinonaktifkan di ANTAREJA tetap ditolak walau aktif di direktori |
| Password | Min. 10 karakter, huruf + angka; ganti password wajib password lama |
| Akun nonaktif | Langsung keluar pada permintaan berikutnya, termasuk sesi "Ingat saya" |
| Ganti password | Semua sesi lain akun itu otomatis keluar (`AuthenticateSession`); juga saat admin mereset password pengguna |
| Admin terakhir | Tidak bisa dinonaktifkan/diturunkan bila tidak ada admin aktif lain |
| Cookie sesi | `Secure`, `HttpOnly`, `SameSite=Lax`; seluruh web dipaksa HTTPS |
| CSRF | Semua POST/PUT/DELETE web memakai token Laravel |
| Jejak audit | Perubahan perangkat, pengguna, kanal, ambang, backup, posisi peta, tautan status, dll. |

## 3. Header HTTP

`Content-Security-Policy` (**skrip hanya dari server sendiri — tanpa skrip inline maupun atribut `on…`**; koneksi/frame/form
dibatasi ke server sendiri, `object-src 'none'`, `frame-ancestors 'self'`), `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: same-origin`, `Permissions-Policy` (kamera/mikrofon/lokasi dimatikan),
`Cross-Origin-Opener-Policy`, `Strict-Transport-Security`. Pratinjau laporan memakai CSP `sandbox` tersendiri.

## 4. Permukaan yang terbuka ke jaringan

| Port | Layanan | Catatan |
|---|---|---|
| 80/443 (bisa diganti di `.env`) | Web (HTTPS; HTTP dialihkan) | Satu-satunya pintu untuk pengguna |
| 514 UDP/TCP | Penerima syslog | Hanya menerima & menyimpan teks; batas laju per IP |
| 162 UDP | Penerima SNMP trap v1/v2c | Hanya menerima & menyimpan; tidak menjawab (inform dan v3 diabaikan); batas 50 trap per 10 detik per IP; community tidak disimpan |
| 443 (keluar dari site) | Collector site ke web pusat | Hanya koneksi keluar dari site; site tidak membuka port apa pun |
| — | API collector `:9274`, PostgreSQL, VictoriaMetrics, go2rtc | **Tidak** dipublikasikan; hanya jaringan Docker. Token API = HMAC dari `ANTAREJA_APP_KEY` |

Video CCTV: nginx hanya membuka `/live/ws` & `/live/frame.jpeg` setelah login dicek Laravel; API go2rtc lain
(berisi URL RTSP berkredensial) tidak bisa diakses dari browser.

## 5. Tautan status (tanpa login)

Token acak 40 karakter, disimpan sebagai SHA-256 dan hanya ditampilkan sekali; bisa kedaluwarsa & dicabut;
throttle 60/menit; `noindex`, `no-referrer`, `no-store`; tidak menampilkan IP/host/konfigurasi; URL `/status/`
tidak dicatat di access log nginx. Siapa pun yang memegang tautan bisa membukanya — cabut bila bocor.

## 5a. API baca-saja

- `/api/v1` hanya menerima GET; metode lain dijawab 405 sebelum menyentuh data.
- Token `ant_` acak 44 karakter, disimpan sebagai hash SHA-256, tampil sekali saat dibuat. Bisa diberi masa berlaku
  dan dibatasi ke IP/CIDR; token salah, dicabut, kedaluwarsa, atau dari IP lain dijawab 401 yang sama.
- Kolom keluaran ditulis eksplisit per endpoint; kolom `*_enc` (kredensial) tidak pernah dipilih. Dibatasi 120 permintaan per menit.
- Pembuatan dan pencabutan token tercatat di jejak audit; waktu dan IP pemakaian terakhir tampil di daftar token.

## 5b. Salinan backup ke luar server

- Password/private key SFTP dan secret key S3 disimpan terenkripsi (kunci aplikasi), tidak pernah dikirim ke browser.
- SFTP: sidik jari host key server dikunci pada koneksi pertama; bila berubah, salinan ditolak sampai admin mereset.
- S3: tanda tangan AWS SigV4; isi diverifikasi Content-MD5 (oleh server) dan ETag (oleh collector).
- Collector hanya membaca folder backup (mount read-only). Akun tujuan cukup diberi hak tulis dan hapus di satu folder.
- Berkas backup berisi seluruh database, termasuk kredensial perangkat yang terenkripsi; perlakukan tujuan salinan sebagai rahasia.

## 5c. Collector site

- Token `acs_` acak 48 karakter per collector, disimpan sebagai hash SHA-256, tampil sekali; bisa diganti (token lama
  langsung ditolak) dan collector bisa dinonaktifkan. Pembuatan, ganti token, dan hapus tercatat di jejak audit.
- `/api/site/v1` hanya `GET config` dan `POST results`; batas 600 permintaan/menit; jawaban `no-store`.
- Konfigurasi hanya berisi perangkat aktif milik collector itu, termasuk kredensial SNMP-nya (dikirim lewat HTTPS,
  disimpan di memori site saja; salinan daftar perangkat di disk tanpa kredensial, folder antrean `0700`, berkas `0600`).
- Hasil diteruskan web ke collector pusat lewat jaringan Docker internal. Collector pusat hanya menerapkan sampel untuk
  perangkat aktif yang memang milik collector itu; sampel ganda atau lebih lama dari yang tersimpan dilewati.
- Collector site memverifikasi sertifikat pusat (CA sistem atau berkas CA kantor); tidak ada opsi untuk mematikannya.
- Mesin site yang dicuri membawa token dan (selama berjalan) kredensial SNMP perangkat site itu: ganti token dan
  kredensial SNMP perangkat site tersebut.

## 6. Ekspor konfigurasi

Berkas ekspor tanpa rahasia hanya berisi nama, host/IP, dan pengaturan — tetap perlakukan sebagai dokumen internal.
Bila rahasia disertakan, bagian `secrets` dienkripsi AES-256-GCM dengan kunci PBKDF2-SHA256 (200.000 iterasi) dari
frasa sandi ekspor (min. 12 karakter), terpisah dari `ANTAREJA_APP_KEY`. Frasa sandi tidak disimpan di mana pun; simpan
terpisah dari berkasnya. Impor & ekspor khusus admin dan tercatat di jejak audit.

## 6a. Cek layanan

- Hanya engineer dan admin yang bisa membuat cek; tiap pembuatan, perubahan, dan penghapusan tercatat di jejak audit.
- HTTP hanya `http://` dan `https://`, metode GET, tanpa kredensial, tanpa cookie, tanpa mengikuti pengalihan, tanpa
  proxy, dan hanya membaca 64 KB pertama. TCP hanya membuka lalu menutup koneksi. DNS hanya bertanya.
- *Uji sekarang* hanya menjalankan cek yang sudah tersimpan, bukan alamat bebas.
- Verifikasi sertifikat HTTPS bisa dimatikan per cek untuk perangkat dengan sertifikat bawaan; tetap aktif secara bawaan.

## 7. Batasan yang diketahui

- **CSP masih mengizinkan gaya (CSS) inline** (atribut `style` di template & Leaflet). Skrip inline sudah tidak diizinkan.
  Aturan pengembangan: jangan menulis `<script>` inline atau atribut `onclick`/`onsubmit`/`onchange`; pakai atribut
  `data-…` dan penangan di `resources/js/app.js` (mis. `data-confirm`, `data-autosubmit`, `data-print`).
- **Syslog UDP bisa dipalsukan.** IP pengirim UDP tidak terautentikasi; log yang tampil "dari perangkat X" bisa dikirim
  pihak lain di jaringan. Perlakukan log sebagai petunjuk, bukan bukti. Batasi port 514 di firewall ke subnet manajemen.
- **Gambar peta diambil browser dari server tile luar** (bawaan OpenStreetMap/OpenTopoMap): server tersebut melihat IP
  publik kantor dan area peta yang dibuka. Pakai server tile internal bila ini tidak diinginkan.
- **Sertifikat HTTPS bawaan self-signed.** Ganti dengan sertifikat kantor; HSTS baru efektif dengan sertifikat tepercaya.
- **SSH host key trust-on-first-use.** Backup pertama mempercayai key yang ditemui; pastikan backup pertama dilakukan
  dari jaringan manajemen yang tepercaya.
- **Discovery** bisa memindai subnet mana pun yang diisi engineer (maks. /22); gunakan hanya untuk jaringan sendiri.

## 8. Daftar periksa pemasangan

1. `deploy/.env`: `DB_PASSWORD` & `ANTAREJA_APP_KEY` acak; simpan cadangan `ANTAREJA_APP_KEY` di tempat aman
   (tanpa kunci ini semua kredensial terenkripsi tidak bisa dibuka).
2. Ganti sertifikat HTTPS dengan sertifikat kantor.
3. Firewall VM: buka port web (bawaan 80/443) hanya untuk jaringan kantor/NOC, 514 dan 162/UDP hanya dari subnet manajemen perangkat.
4. Buat akun per orang (bukan akun bersama); viewer untuk layar NOC & pemantau. Wajibkan verifikasi dua langkah
   minimal untuk admin (*Pengguna → Kebijakan login*).
5. Arahkan `BACKUP_DIR` ke NAS; uji `./antareja.sh restore` sekali.
6. Community SNMP read-only khusus NMS (bukan `public`), user SSH backup dengan hak baca saja.
