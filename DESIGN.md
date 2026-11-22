# Design: ANTAREJA NMS

Sistem yang dikunci. Setiap layar ANTAREJA, termasuk fitur baru, memakai sistem ini. Ubah dengan sengaja; berkas ini adalah aturannya.

## System
- Genre: modern-minimal (alat kerja NOC, layar aplikasi di balik login)
- Shape: app screen. Sidebar (lebih dari 7 tujuan, jadi menu di bawah 60rem) dan bilah atas sticky
- Theme: brand. Merah ANTAREJA hanya di logo dan penanda menu aktif; teal untuk tindakan dan pilihan; status memakai warnanya sendiri
- Axes: light/grotesk-sans/chromatic-teal · posture: settled
- Signature: antrean problem (yang rusak sekarang, urut severity dan lama), pembuka Ringkasan bawaan

## Tokens
`web/resources/css/tokens.css` adalah sumber kebenaran. Nama lama (`--bg`, `--accent`, `--crit`, …) dialiaskan ke token ini untuk view dan JavaScript lama.
```css
:root {
  --color-paper: #f7f8f9;  --color-paper-2: #eef1f3;  --color-paper-3: #e3e8ec;  --color-surface: #ffffff;
  --color-ink: #121c25;  --color-ink-2: #3f4c58;  --color-muted: #5d6a76;  --color-rule: #dde3e8;  --color-field: #7d8995;
  --color-accent: #0b6b63;  --color-accent-ink: #ffffff;  --color-focus: #0b6b63;  --color-brand: #c8102e;
  --color-ok / --color-warn / --color-crit / --color-info / --color-off  (+ varian -soft)
  --color-side: #0f1720  (pita sidebar, fokus #7fd6cc)
  --font-display: "IBM Plex Sans";  --font-body: "IBM Plex Sans";  --font-outlier: "IBM Plex Mono" (alamat IP, MAC, konfigurasi);
  --radius-card: 6px;  --radius-ctl: 6px;  --radius-pill: 999px;  bayangan hanya untuk lapisan mengambang (--shadow-pop)
}
```
Tema gelap mendefinisikan ulang token yang sama di `:root[data-theme="dark"]`. Skala spasi 4px, skala huruf 1.2, dua ketebalan (400, 600).

## Komposisi layar
- Area halaman adalah panel datar: judul `h2` dan garis di bawahnya, tanpa kotak, tanpa bayangan.
- Angka ringkas memakai baris angka (`.kpis` > `.kpi`): angka besar lalu label, dipisah garis tipis. Bukan kartu.
- Tabel: kepala dalam huruf biasa, garis horizontal saja, selebar panel. Kolom nama tetap terlihat di HP.
- Meta baris disusun dengan jarak (`.meta`), bukan titik tengah. Nilai kosong ditulis dengan kata ("tidak ada", "belum ada"), bukan tanda pisah.
- Tautan diberi label kata kerja ("Semua kejadian", "Lihat jadwal"), tanpa panah.

## Controls
- Primary: isian teal · radius 6px · tinggi 36px (32px untuk `.sm` di tabel). Satu tombol utama per layar.
- Secondary: garis tepi, radius dan tinggi sama. Hapus memakai `.danger` (garis merah), bukan isian.
- Field: tepi `--color-field` (3:1), fokus garis 2px `--color-focus`. Nonaktif: redup, `cursor: not-allowed`, atribut `disabled`.

## Motion
- Karakter: tenang. Hanya warna dan latar yang bergerak (120ms); titik status kritis berdenyut; semua berhenti di `prefers-reduced-motion`.

## Exports
`web/resources/css/tokens.css` di proyek ini. Layar NOC (`noc.css`) adalah tampilan TV dinding dengan gaya sendiri (huruf kapital sebagai suara tampilan).
