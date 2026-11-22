@extends('layouts.app')
@section('title', 'Panduan')
@section('content')
<div class="page-head">
    @can('admin')<div class="actions" style="order:2"><form method="post" action="{{ route('onboarding.show') }}">@csrf<button class="btn sm">Tampilkan langkah awal di Ringkasan</button></form></div>@endcan
    <div><h1>Panduan operator</h1><p>Arti status & warna, cara menangani problem, dan istilah yang dipakai di ANTAREJA. Angka di halaman ini adalah nilai bawaan; admin bisa mengubah ambang problem di <i>Administrasi → Notifikasi → Ambang problem</i>, dan ambang khusus per perangkat di form perangkat.</p></div>
</div>

<div class="help">
<nav class="card help-toc" aria-label="Isi panduan"><div class="bd">
    <b>Isi</b>
    <a href="#status">Status perangkat & warna</a>
    <a href="#problem">Problem & severity</a>
    <a href="#tangani">Saat ada perangkat down</a>
    <a href="#ack">ACK, eskalasi & notifikasi</a>
    <a href="#insiden">Insiden</a>
    <a href="#layanan">SLA layanan</a>
    <a href="#maintenance">Maintenance & perangkat induk</a>
    <a href="#noc">Layar NOC</a>
    <a href="#sensor">Ambang sensor bawaan</a>
    <a href="#istilah">Istilah</a>
    <a href="#tanya">Tanya jawab</a>
</div></nav>

<div class="help-body stack">
<section class="card" id="status"><div class="hd"><h2>Status perangkat & warna</h2></div><div class="bd">
    <table class="t help-t"><tbody>
        <tr><td><span class="badge ok">Up</span></td><td>Menjawab ping (atau SNMP bila ping dimatikan).</td></tr>
        <tr><td><span class="badge crit">Down</span></td><td>Ping gagal 100% pada <b>2 putaran berturut-turut</b> (± 2 menit). Pulih pada putaran pertama yang berhasil; kejadian <i>Pulih</i> mencatat lama down.</td></tr>
        <tr><td><span class="badge warn">Ada problem</span></td><td>Perangkat hidup tetapi ada problem terbuka (loss, latensi, sensor, port, SNMP, dll.).</td></tr>
        <tr><td><span class="badge info">Maintenance</span></td><td>Sedang dalam jadwal maintenance: problem tetap dicatat, tetapi tidak dinotifikasi.</td></tr>
        <tr><td><span class="badge">Menunggu</span></td><td>Baru ditambahkan / belum ada hasil pemeriksaan, atau perangkat nonaktif.</td></tr>
    </tbody></table>
    <p class="muted">Di peta topologi, perangkat <b>terdampak induk</b> (warna lebih gelap) berarti perangkat induknya (uplink) sedang down — kemungkinan besar ikut terputus, bukan rusak sendiri.</p>
    <p class="muted" style="margin-bottom:0"><b>Reboot</b> dideteksi dari uptime SNMP yang turun. <b>Flapping</b> = down ≥ 3 kali dalam 15 menit (link tidak stabil, mis. radio terganggu cuaca / power naik-turun).</p>
</div></section>

<section class="card" id="problem"><div class="hd"><h2>Problem & severity</h2></div><div class="bd">
    <p style="margin-top:0">Problem dinilai tiap menit dan <b>selesai otomatis</b> saat kondisinya hilang. Satu problem per perangkat per jenis (per port / per sensor).</p>
    <table class="t help-t">
        <thead><tr><th>Problem</th><th>Kapan muncul (bawaan)</th><th>Severity</th></tr></thead>
        <tbody>
        <tr><td>Down</td><td>Ping gagal 2 putaran</td><td><span class="badge crit plain">Disaster</span> untuk kategori Core, Distribution, Firewall · <span class="badge crit plain">High</span> lainnya</td></tr>
        <tr><td>Packet loss</td><td>Rata-rata loss 5 menit &gt; 20%</td><td><span class="badge warn plain">Average</span> · <span class="badge crit plain">High</span> bila ≥ 50%</td></tr>
        <tr><td>Latensi tinggi</td><td>Rata-rata RTT 5 menit &gt; 150 ms</td><td><span class="badge warn plain">Warning</span></td></tr>
        <tr><td>SNMP tidak terbaca</td><td>SNMP gagal ≥ 2 putaran sementara ping normal</td><td><span class="badge warn plain">Warning</span></td></tr>
        <tr><td>Port putus</td><td>Port yang ditandai <i>Pantau</i> aktif tetapi link down</td><td><span class="badge crit plain">High</span></td></tr>
        <tr><td>Port penuh</td><td>Port <i>Pantau</i> terpakai ≥ 90%</td><td><span class="badge warn plain">Average</span></td></tr>
        <tr><td>Sensor</td><td>Di luar ambang (lihat <a href="#sensor">tabel</a>)</td><td><span class="badge warn plain">Warning</span> · <span class="badge crit plain">High</span> bila kritis</td></tr>
        <tr><td>Kamera CCTV</td><td>Gambar gelap / tertutup / beku / tanpa sinyal</td><td><span class="badge warn plain">Warning</span> · <span class="badge warn plain">Average</span> untuk tanpa sinyal</td></tr>
        <tr><td>Konflik IP</td><td>Satu IP bergantian di 2 MAC ≥ 3× dalam 6 jam</td><td><span class="badge warn plain">Average</span></td></tr>
        </tbody>
    </table>
    <p class="muted" style="margin-bottom:0">Urutan severity: Info &lt; Warning &lt; Average &lt; High &lt; Disaster. Alarm merah Layar NOC & bunyi hanya untuk High/Disaster yang belum di-ACK.</p>
</div></section>

<section class="card" id="tangani"><div class="hd"><h2>Saat ada perangkat down</h2></div><div class="bd">
    <ol class="help-steps">
        <li><b>Cek induknya dulu.</b> Bila problem bertanda <i>terdampak: induk … down</i>, tangani perangkat induk (uplink/radio/switch di atasnya) — perangkat di bawahnya biasanya ikut pulih.</li>
        <li><b>Lihat detail perangkat:</b> kejadian terakhir (reboot? flapping?), grafik latensi/loss sebelum down, log syslog terbaru, sensor (daya UPS, suhu, sinyal radio).</li>
        <li><b>Cek apakah satu site sekaligus</b> (dinding status / peta lokasi): bila semua perangkat satu site down, kemungkinan listrik site atau link backhaul.</li>
        <li><b>ACK problem</b> dengan catatan singkat (siapa menangani, dugaan penyebab) agar eskalasi tidak terkirim dan rekan tahu sudah ada yang menangani.</li>
        <li>Bila perbaikan terjadwal (ganti perangkat, pemadaman), buat <b>Maintenance</b> agar tidak membanjiri notifikasi & SLA tidak terhitung down.</li>
        <li>Setelah pulih, problem selesai sendiri. Bila perangkat diganti, cek <i>Backup konfigurasi</i> (host key berubah → <i>Reset host key</i>).</li>
    </ol>
</div></section>

<section class="card" id="ack"><div class="hd"><h2>ACK, eskalasi & notifikasi</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>ACK</b> (engineer/admin): tanda "sudah ditangani" + catatan. Problem tetap terbuka sampai kondisinya hilang. ACK bisa dibatalkan.</li>
        <li><b>Notifikasi</b> dikirim per kanal (Telegram, email, webhook) sesuai severity minimal & site kanal tersebut, saat problem muncul dan (opsional) saat pulih. Banyak problem sekaligus digabung menjadi satu pesan ringkasan.</li>
        <li><b>Eskalasi</b>: kanal dengan jeda (mis. 30 menit, untuk supervisor) hanya dikirimi problem yang <b>belum di-ACK</b> selama jeda itu. Jadi: ACK cepat = atasan tidak terganggu.</li>
        <li>Problem yang <b>ditekan</b> (maintenance / induk down) tetap tampil tetapi tidak dinotifikasi.</li>
        <li><b>Masalah sistem ANTAREJA</b> (backup gagal, disk hampir penuh, notifikasi gagal) juga dikirim ke kanal notifikasi; lihat <i>Kesehatan sistem</i> (admin).</li>
    </ul>
</div></section>

<section class="card" id="insiden"><div class="hd"><h2>Insiden</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Problem</b> = satu kondisi di satu perangkat. <b>Insiden</b> = satu gangguan nyata yang bisa berisi banyak problem (mis. PLN padam di satu pit: core, radio, dan kamera down sekaligus).</li>
        <li><b>Dibuat otomatis</b> bila ada problem <b>Disaster</b>, atau ≥ 3 problem <b>High</b> di satu site yang muncul dalam 10 menit. Problem baru di site yang sama dalam 60 menit, dan perangkat yang <i>terdampak induk</i>, otomatis digabung ke insiden yang sama.</li>
        <li><b>Dibuat manual</b> (engineer/admin): di halaman <i>Problem</i>, centang problem yang berasal dari gangguan yang sama → <b>Buat insiden</b>.</li>
        <li><b>Alur</b>: <span class="badge crit">Terbuka</span> → <span class="badge warn">Ditangani</span> (tekan <i>Saya tangani</i> atau tugaskan engineer) → <span class="badge ok">Pulih</span> (otomatis saat semua problem selesai) → <b>Ditutup</b> setelah <i>penyebab</i> dan <i>tindakan</i> diisi. Bila gangguan muncul lagi ≤ 30 menit setelah pulih, insiden dibuka kembali.</li>
        <li>Tulis temuan lapangan di <b>catatan</b>: semuanya masuk kronologi beserta nama dan jam, berguna untuk laporan ke manajemen. <b>MTTR</b> = rata-rata lama dari mulai gangguan sampai pulih.</li>
    </ul>
</div></section>

<section class="card" id="layanan"><div class="hd"><h2>SLA layanan</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Layanan</b> = sesuatu yang dipakai orang, mis. <i>CCTV Pit A</i> atau <i>Internet kantor</i>. Disusun dari <b>komponen</b>; tiap komponen berisi perangkat dan syarat <b>minimal hidup</b>.</li>
        <li>Jalur seri (Core → Radio PTP → Switch): semua wajib hidup — isi minimal = jumlah perangkat, atau satu komponen per perangkat. Perangkat cadangan (ISP 1 &amp; ISP 2): isi minimal 1.</li>
        <li>Status: <span class="badge ok">Normal</span> semua hidup · <span class="badge warn">Tanpa cadangan</span> layanan jalan tetapi ada perangkat down (segera perbaiki sebelum cadangannya ikut mati) · <span class="badge crit">Terganggu</span> layanan tidak jalan.</li>
        <li>Ketersediaan dihitung dari riwayat down perangkat (gangguan saat maintenance tidak dihitung), mulai sejak perangkat ditambahkan. <b>Sisa jatah downtime</b> = berapa lama lagi layanan boleh mati bulan ini agar tetap memenuhi target.</li>
    </ul>
</div></section>

<section class="card" id="maintenance"><div class="hd"><h2>Maintenance & perangkat induk</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Maintenance</b> bisa untuk semua perangkat, satu site, atau perangkat terpilih, dengan jam mulai–selesai. Selama itu problem tidak dinotifikasi dan downtime tidak dihitung di SLA. Akhiri lebih cepat dengan tombol <i>Akhiri</i>.</li>
        <li><b>Perangkat induk</b> (di form perangkat) = perangkat di atasnya pada jalur jaringan (mis. kamera → NVR → switch → radio PTP → core). Isi dengan benar agar korelasi bekerja: saat induk down, ratusan perangkat di bawahnya tidak masing-masing mengirim alarm.</li>
    </ul>
</div></section>

<section class="card" id="noc"><div class="hd"><h2>Layar NOC</h2></div><div class="bd">
    <ul class="help-list">
        <li>Login dengan akun viewer + centang <i>Ingat saya</i>, buka <i>Layar NOC</i>, tekan <kbd>F</kbd> untuk layar penuh.</li>
        <li>Klik tombol <b>SUARA</b> sekali setelah membuka (browser mewajibkan satu klik sebelum boleh berbunyi).</li>
        <li>Pilih <b>OTOMATIS</b> agar tampilan bergantian: dinding status → topologi → peta lokasi (tiap 30 detik).</li>
        <li>Indikator <b>LIVE</b> menguning bila layar gagal mengambil data. Alarm <b>"COLLECTOR TIDAK MELAPOR — DATA USANG"</b> berarti mesin pemantau ANTAREJA berhenti: status di layar sudah tidak bisa dipercaya — hubungi admin.</li>
    </ul>
</div></section>

<section class="card" id="sensor"><div class="hd"><h2>Ambang sensor bawaan</h2></div><div class="bd flush">
    <table class="t">
        <thead><tr><th>Sensor</th><th class="num">Peringatan</th><th class="num">Kritis</th><th>Keterangan</th></tr></thead>
        <tbody>
        <tr><td>CPU</td><td class="num">≥ 80%</td><td class="num">≥ 95%</td><td></td></tr>
        <tr><td>Memori</td><td class="num">≥ 85%</td><td class="num">≥ 95%</td><td></td></tr>
        <tr><td>Disk</td><td class="num">≥ 85%</td><td class="num">≥ 95%</td><td></td></tr>
        <tr><td>Suhu</td><td class="num">≥ 60 °C</td><td class="num">≥ 75 °C</td><td>Suhu CPU: 75 / 90 °C</td></tr>
        <tr><td>Sinyal radio</td><td class="num">≤ −70 dBm</td><td class="num">≤ −78 dBm</td><td>Makin negatif makin lemah</td></tr>
        <tr><td>CCQ / Quality (radio)</td><td class="num">≤ 70%</td><td class="num">≤ 50%</td><td>Kualitas link nirkabel</td></tr>
        <tr><td>Baterai UPS</td><td class="num">≤ 50%</td><td class="num">≤ 20%</td><td>Muatan</td></tr>
        <tr><td>Waktu cadangan UPS</td><td class="num">≤ 15 menit</td><td class="num">≤ 5 menit</td><td></td></tr>
        <tr><td>Beban UPS</td><td class="num">≥ 80%</td><td class="num">≥ 95%</td><td></td></tr>
        </tbody>
    </table>
    <p class="muted" style="padding:0 16px">Ambang bisa diatur khusus per perangkat (mis. radio PTP jarak jauh dengan sinyal normal −74 dBm) di form perangkat → <i>Ambang khusus</i>.</p>
</div></section>

<section class="card" id="istilah"><div class="hd"><h2>Istilah</h2></div><div class="bd">
    <dl class="facts help-terms">
        <dt>RTT / latensi</dt><dd>Waktu bolak-balik ping (ms). Makin kecil makin baik; radio/VSAT wajar lebih tinggi dari kabel.</dd>
        <dt>Packet loss</dt><dd>Persentase ping yang tidak berbalas. Loss kecil yang terus-menerus sering tanda link radio/kabel bermasalah.</dd>
        <dt>Jitter</dt><dd>Variasi latensi antarpaket. Penting untuk CCTV & suara: jitter tinggi = video patah-patah.</dd>
        <dt>p95</dt><dd>95% waktu latensi di bawah nilai ini (mengabaikan lonjakan sesaat).</dd>
        <dt>SNMP</dt><dd>Protokol baca data perangkat (port, trafik, sensor). ANTAREJA hanya membaca, tidak pernah mengubah.</dd>
        <dt>Community / SNMPv3</dt><dd>"Kata sandi" SNMP. v3 lebih aman (terenkripsi).</dd>
        <dt>dBm</dt><dd>Satuan kekuatan sinyal radio (selalu negatif): −55 kuat, −70 cukup, −80 lemah.</dd>
        <dt>CCQ</dt><dd>Client Connection Quality (MikroTik): efisiensi link nirkabel dalam %.</dd>
        <dt>Uptime</dt><dd>Lama perangkat menyala sejak boot terakhir; turun tiba-tiba = reboot.</dd>
        <dt>Syslog</dt><dd>Pesan log yang dikirim perangkat ke ANTAREJA (halaman Log).</dd>
        <dt>SLA / ketersediaan</dt><dd>Persentase waktu perangkat up dalam periode; jam maintenance tidak dihitung down.</dd>
        <dt>ACK</dt><dd>Acknowledge — menandai problem sudah ditangani.</dd>
    </dl>
</div></section>

<section class="card" id="tanya"><div class="hd"><h2>Tanya jawab</h2></div><div class="bd">
    <dl class="help-faq">
        <dt>Perangkat menyala tetapi tercatat down?</dt>
        <dd>Firewall perangkat mungkin memblok ping dari server ANTAREJA. Izinkan ICMP dari IP server, atau matikan ping di form perangkat (status lalu dinilai dari SNMP).</dd>
        <dt>Kenapa tidak dapat notifikasi?</dt>
        <dd>Cek severity minimal & site kanal, apakah problem ditekan (maintenance / induk down), atau sudah di-ACK (eskalasi tidak dikirim). Admin bisa menekan <i>Kirim uji</i> di Notifikasi.</dd>
        <dt>Bisakah ANTAREJA mengubah konfigurasi perangkat?</dt>
        <dd>Tidak. ANTAREJA hanya membaca (ping, SNMP GET, SSH perintah tampil untuk backup konfigurasi).</dd>
        <dt>Data grafik disimpan berapa lama?</dt>
        <dd>Bawaan 400 hari. Log syslog bawaan 14 hari (diatur admin di halaman Log).</dd>
        <dt>Lupa password?</dt>
        <dd>Minta admin mereset di <i>Pengguna</i>. Setelah password diganti, sesi di perangkat lain otomatis keluar.</dd>
    </dl>
</div></section>
</div>
</div>
@endsection
