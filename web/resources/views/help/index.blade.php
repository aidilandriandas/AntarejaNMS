@extends('layouts.app')
@section('title', 'Panduan')
@section('content')
<div class="page-head">
    @can('admin')<div class="actions" style="order:2"><form method="post" action="{{ route('onboarding.show') }}">@csrf<button class="btn sm">Tampilkan langkah awal di Ringkasan</button></form></div>@endcan
    <div><h1>Panduan operator</h1><p>Arti status & warna, cara menangani problem, dan istilah yang dipakai di ANTAREJA. Angka di halaman ini adalah nilai bawaan; admin bisa mengubah ambang problem di <i>Administrasi, Notifikasi, bagian Ambang problem</i>, dan ambang khusus per perangkat di form perangkat.</p></div>
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
    <a href="#trigger">Trigger</a>
    <a href="#kapasitas">Kapasitas port</a>
    <a href="#maintenance">Maintenance & perangkat induk</a>
    <a href="#piket">Jadwal piket</a>
    <a href="#anomali">Anomali</a>
    <a href="#port">Error & naik-turun port</a>
    <a href="#trap">SNMP trap</a>
    <a href="#topologi">Sinkron LLDP & diagnosa SNMP</a>
    <a href="#api">Integrasi lewat API</a>
    <a href="#diag">Alat diagnosa & down bulanan</a>
    <a href="#daya">Daya menara (Modbus)</a>
    <a href="#runbook">Runbook</a>
    <a href="#flow">Flow trafik</a>
    <a href="#cek">Cek layanan</a>
    <a href="#dampak">Peta dampak</a>
    <a href="#site">Collector site</a>
    <a href="#akun">2FA & login kantor</a>
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
    <p class="muted">Di peta topologi, perangkat <b>terdampak induk</b> (warna lebih gelap) berarti perangkat induknya (uplink) sedang down, kemungkinan besar ikut terputus, bukan rusak sendiri.</p>
    <p class="muted" style="margin-bottom:0"><b>Reboot</b> dideteksi dari uptime SNMP yang turun. <b>Flapping</b> = down ≥ 3 kali dalam 15 menit (link tidak stabil, mis. radio terganggu cuaca / power naik-turun).</p>
</div></section>

<section class="card" id="problem"><div class="hd"><h2>Problem & severity</h2></div><div class="bd">
    <p style="margin-top:0">Problem dinilai tiap menit dan <b>selesai otomatis</b> saat kondisinya hilang. Satu problem per perangkat per jenis (per port / per sensor).</p>
    <table class="t help-t">
        <thead><tr><th>Problem</th><th>Kapan muncul (bawaan)</th><th>Severity</th></tr></thead>
        <tbody>
        <tr><td>Down</td><td>Ping gagal 2 putaran</td><td><span class="badge crit plain">Disaster</span> untuk kategori Core, Distribution, Firewall; <span class="badge crit plain">High</span> lainnya</td></tr>
        <tr><td>Packet loss</td><td>Rata-rata loss 5 menit &gt; 20%</td><td><span class="badge warn plain">Average</span>; <span class="badge crit plain">High</span> bila ≥ 50%</td></tr>
        <tr><td>Latensi tinggi</td><td>Rata-rata RTT 5 menit &gt; 150 ms</td><td><span class="badge warn plain">Warning</span></td></tr>
        <tr><td>SNMP tidak terbaca</td><td>SNMP gagal ≥ 2 putaran sementara ping normal</td><td><span class="badge warn plain">Warning</span></td></tr>
        <tr><td>Port putus</td><td>Port yang ditandai <i>Pantau</i> aktif tetapi link down</td><td><span class="badge crit plain">High</span></td></tr>
        <tr><td>Port penuh</td><td>Port <i>Pantau</i> terpakai ≥ 90%</td><td><span class="badge warn plain">Average</span></td></tr>
        <tr><td>Sensor</td><td>Di luar ambang (lihat <a href="#sensor">tabel</a>)</td><td><span class="badge warn plain">Warning</span>; <span class="badge crit plain">High</span> bila kritis</td></tr>
        <tr><td>Kamera CCTV</td><td>Gambar gelap / tertutup / beku / tanpa sinyal</td><td><span class="badge warn plain">Warning</span>; <span class="badge warn plain">Average</span> untuk tanpa sinyal</td></tr>
        <tr><td>Konflik IP</td><td>Satu IP bergantian di 2 MAC ≥ 3× dalam 6 jam</td><td><span class="badge warn plain">Average</span></td></tr>
        <tr><td>Port naik-turun</td><td>Port infrastruktur berubah status ≥ 4 kali dalam 15 menit (lihat <a href="#port">Port</a>)</td><td><span class="badge warn plain">Average</span></td></tr>
        <tr><td>Anomali</td><td>Latensi atau trafik menyimpang jauh dari jam yang sama 14 hari terakhir (lihat <a href="#anomali">Anomali</a>)</td><td><span class="badge warn plain">Warning</span> (bisa diubah admin)</td></tr>
        <tr><td>Trigger custom</td><td>Aturan buatan engineer (lihat <a href="#trigger">Trigger</a>)</td><td>sesuai trigger</td></tr>
        </tbody>
    </table>
    <p class="muted" style="margin-bottom:0">Urutan severity: Info &lt; Warning &lt; Average &lt; High &lt; Disaster. Alarm merah Layar NOC & bunyi hanya untuk High/Disaster yang belum di-ACK.</p>
</div></section>

<section class="card" id="tangani"><div class="hd"><h2>Saat ada perangkat down</h2></div><div class="bd">
    <ol class="help-steps">
        <li><b>Cek induknya dulu.</b> Bila problem bertanda <i>terdampak: induk NAMA down</i>, tangani perangkat induk (uplink/radio/switch di atasnya), perangkat di bawahnya biasanya ikut pulih.</li>
        <li><b>Lihat detail perangkat:</b> kejadian terakhir (reboot? flapping?), grafik latensi/loss sebelum down, log syslog terbaru, sensor (daya UPS, suhu, sinyal radio).</li>
        <li><b>Cek apakah satu site sekaligus</b> (dinding status / peta lokasi): bila semua perangkat satu site down, kemungkinan listrik site atau link backhaul.</li>
        <li><b>ACK problem</b> dengan catatan singkat (siapa menangani, dugaan penyebab) agar eskalasi tidak terkirim dan rekan tahu sudah ada yang menangani.</li>
        <li>Bila perbaikan terjadwal (ganti perangkat, pemadaman), buat <b>Maintenance</b> agar tidak membanjiri notifikasi & SLA tidak terhitung down.</li>
        <li>Setelah pulih, problem selesai sendiri. Bila perangkat diganti, cek <i>Backup konfigurasi</i> (host key berubah: tekan <i>Reset host key</i>).</li>
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
        <li><b>Dibuat manual</b> (engineer/admin): di halaman <i>Problem</i>, centang problem yang berasal dari gangguan yang sama, lalu tekan <b>Buat insiden</b>.</li>
        <li><b>Alur</b>: <span class="badge crit">Terbuka</span>, lalu <span class="badge warn">Ditangani</span> (tekan <i>Saya tangani</i> atau tugaskan engineer), lalu <span class="badge ok">Pulih</span> (otomatis saat semua problem selesai), lalu <b>Ditutup</b> setelah <i>penyebab</i> dan <i>tindakan</i> diisi. Bila gangguan muncul lagi ≤ 30 menit setelah pulih, insiden dibuka kembali.</li>
        <li>Tulis temuan lapangan di <b>catatan</b>: semuanya masuk kronologi beserta nama dan jam, berguna untuk laporan ke manajemen. <b>MTTR</b> = rata-rata lama dari mulai gangguan sampai pulih.</li>
    </ul>
</div></section>

<section class="card" id="layanan"><div class="hd"><h2>SLA layanan</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Layanan</b> = sesuatu yang dipakai orang, mis. <i>CCTV Pit A</i> atau <i>Internet kantor</i>. Disusun dari <b>komponen</b>; tiap komponen berisi perangkat dan syarat <b>minimal hidup</b>.</li>
        <li>Jalur seri (Core, Radio PTP, lalu Switch): semua wajib hidup, isi minimal = jumlah perangkat, atau satu komponen per perangkat. Perangkat cadangan (ISP 1 &amp; ISP 2): isi minimal 1.</li>
        <li>Status: <span class="badge ok">Normal</span> semua hidup; <span class="badge warn">Tanpa cadangan</span> layanan jalan tetapi ada perangkat down (segera perbaiki sebelum cadangannya ikut mati); <span class="badge crit">Terganggu</span> layanan tidak jalan.</li>
        <li>Ketersediaan dihitung dari riwayat down perangkat (gangguan saat maintenance tidak dihitung), mulai sejak perangkat ditambahkan. <b>Sisa jatah downtime</b> = berapa lama lagi layanan boleh mati bulan ini agar tetap memenuhi target.</li>
    </ul>
</div></section>

<section class="card" id="trigger"><div class="hd"><h2>Trigger</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Trigger</b> adalah aturan problem buatan sendiri, untuk kondisi yang tidak ditangkap ambang bawaan. Contoh: trafik masuk port uplink kurang dari 0,01 Mbps selama 10 menit di jam kerja (link putus tetapi port masih up), atau suhu panel paling sedikit 60 °C selama 5 menit.</li>
        <li><b>Bertahan selama</b>: problem baru dibuat bila kondisinya terus terpenuhi selama itu, supaya lonjakan sesaat tidak membunyikan alarm. Isi 0 untuk langsung.</li>
        <li><b>Saring nama</b>: untuk trafik port dan sensor, hanya port atau sensor yang namanya berisi teks itu. Trafik port dibaca tiap putaran SNMP (bawaan 5 menit), jadi durasi di bawah itu tidak lebih cepat.</li>
        <li><b>Jadwal aktif</b>: di luar hari dan jam ini trigger tidak dinilai dan problem-nya selesai sendiri.</li>
        <li>Problem dari trigger diperlakukan sama dengan problem lain: ikut maintenance, induk down, notifikasi, eskalasi, dan insiden. Trigger yang dimatikan atau dihapus menutup problem-nya pada putaran berikutnya.</li>
    </ul>
</div></section>

<section class="card" id="kapasitas"><div class="hd"><h2>Kapasitas port</h2></div><div class="bd">
    <ul class="help-list">
        <li>Hanya port yang ditandai <b>pantau</b> (halaman perangkat, bagian tabel port). Tandai uplink, link PTP, dan port ke ISP.</li>
        <li><b>Puncak harian</b> = persentil 95 trafik hari itu (5% waktu tersibuk diabaikan agar lonjakan sesaat tidak dihitung). Arah masuk atau keluar, mana yang lebih tinggi.</li>
        <li><b>Perkiraan penuh</b> = kapan garis tren puncak harian mencapai batas (bawaan 80% kecepatan port). Butuh minimal 7 hari data; makin panjang datanya makin bisa dipercaya. "Tren lemah" berarti trafik naik-turun tidak beraturan, perkiraannya kurang bisa diandalkan.</li>
        <li>Pastikan kecepatan port benar (radio/PTP kadang melaporkan kecepatan Ethernet, bukan kapasitas radio sebenarnya).</li>
    </ul>
</div></section>

<section class="card" id="maintenance"><div class="hd"><h2>Maintenance & perangkat induk</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Maintenance</b> bisa untuk semua perangkat, satu site, atau perangkat terpilih, dengan jam mulai dan selesai. Selama itu problem tidak dinotifikasi dan downtime tidak dihitung di SLA. Akhiri lebih cepat dengan tombol <i>Akhiri</i>.</li>
        <li><b>Perangkat induk</b> (di form perangkat) = perangkat di atasnya pada jalur jaringan (mis. urutan kamera, NVR, switch, radio PTP, core). Isi dengan benar agar korelasi bekerja: saat induk down, ratusan perangkat di bawahnya tidak masing-masing mengirim alarm.</li>
    </ul>
</div></section>

<section class="card" id="piket"><div class="hd"><h2>Jadwal piket</h2></div><div class="bd">
    <ul class="help-list">
        <li>Setiap engineer mengisi <b>email</b> dan <b>chat ID Telegram</b> di <i>Profil</i>. Untuk Telegram: kirim /start ke bot notifikasi kantor dulu, karena bot tidak bisa memulai obrolan.</li>
        <li>Di <i>Jadwal piket</i>, pakai <b>Buat rotasi</b> untuk mengisi seminggu atau sebulan sekaligus, mis. giliran 12 jam mulai 07.00 untuk Budi, Sari, Andi bergantian. Tukar giliran: tambah giliran pengganti, lalu hapus yang lama.</li>
        <li>Admin mencentang <b>Kirim ke yang sedang piket</b> di kanal email atau Telegram. Email dikirim ke semua yang sedang piket; Telegram ke orang piket pertama yang punya chat ID. Tujuan yang diisi di kanal tetap dipakai sebagai cadangan bila tidak ada yang piket.</li>
        <li>Kanal Telegram dengan <b>Tombol ACK</b>: tiap pesan problem baru membawa tombol <i>ACK, saya tangani</i>. Engineer yang chat ID-nya terisi di Profil cukup menekannya dari HP; eskalasi berhenti dan nama penekan tampil di tombol. Viewer dan akun Telegram yang belum terdaftar ditolak.</li>
        <li>Supaya supervisor tetap tahu, biarkan kanal grup NOC atau kanal eskalasi (jeda 30 menit) seperti biasa; kanal piket berjalan di sampingnya.</li>
    </ul>
</div></section>

<section class="card" id="anomali"><div class="hd"><h2>Anomali</h2></div><div class="bd">
    <ul class="help-list">
        <li>Anomali berbeda dari ambang tetap: patokannya kebiasaan perangkat itu sendiri pada jam yang sama. Trafik 80 Mbps siang hari bisa wajar, tetapi 80 Mbps jam 3 pagi di port yang biasanya 5 Mbps akan tercatat.</li>
        <li>Yang paling berguna di tambang: <b>trafik anjlok</b> di port kamera atau NVR (streaming berhenti padahal perangkat masih bisa di-ping), dan <b>latensi naik</b> di radio PTP sebelum link benar-benar putus.</li>
        <li>Bila anomali memang wajar (backup malam, pindah tiang kamera), tekan <b>Wajar</b> dan pilih berapa lama diabaikan. Untuk pola yang selalu berulang, lebih tepat pakai Maintenance.</li>
        <li>Perangkat baru belum dinilai sampai punya data 7 hari di jam yang sama.</li>
    </ul>
</div></section>

<section class="card" id="port"><div class="hd"><h2>Error & naik-turun port</h2></div><div class="bd">
    <ul class="help-list">
        <li>Kolom <b>Error / discard</b> di daftar port perangkat menunjukkan laju per menit (masuk + keluar) dari putaran SNMP terakhir. Error yang terus naik di port radio atau fiber biasanya tanda konektor kotor, kabel rusak, atau sinyal radio melemah, sebelum link benar-benar putus.</li>
        <li>Untuk menjadikannya problem, buat <b>Trigger</b> dengan metrik <i>Error port</i> atau <i>Discard port</i>, mis. error &gt; 30 per menit selama 10 menit, saring nama port <code>wlan</code>.</li>
        <li><b>Port naik-turun</b>: port yang dipantau, punya tetangga LLDP/CDP, atau punya sambungan manual dan berubah status berkali-kali dalam waktu singkat. Riwayatnya ada di halaman port, bagian <i>Riwayat putus-sambung</i>. Ambangnya diubah admin di halaman Notifikasi.</li>
        <li>Supaya putus-sambung cepat (lebih singkat dari satu putaran polling) ikut terhitung, arahkan <b>SNMP trap</b> perangkat ke ANTAREJA.</li>
    </ul>
</div></section>

<section class="card" id="trap"><div class="hd"><h2>SNMP trap</h2></div><div class="bd">
    <ul class="help-list">
        <li>Perangkat mengirim trap ke IP server ANTAREJA port UDP 162 (v1 atau v2c). Contoh perintah MikroTik dan Cisco ada di halaman <i>Trap</i>.</li>
        <li>Trap port putus/tersambung, restart, dan UPS pindah ke baterai membuat ANTAREJA langsung membaca ulang perangkat itu, jadi status di NMS tidak menunggu putaran polling. Trap tidak membuat problem sendiri; problem tetap dari hasil pembacaan.</li>
        <li>Trap dari IP yang belum terdaftar tetap disimpan dan ditandai <i>belum terdaftar</i>. Bila banyak, daftarkan perangkatnya atau tutup port 162 di firewall untuk subnet itu.</li>
    </ul>
</div></section>

<section class="card" id="topologi"><div class="hd"><h2>Sinkron LLDP & diagnosa SNMP</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Sinkron LLDP sekarang</b> (halaman Topologi) membaca ulang port dan tetangga LLDP/CDP semua perangkat SNMP tanpa menunggu putaran berikutnya. Pakai setelah memasang atau memindah kabel.</li>
        <li><b>Diagnosa SNMP</b> (halaman perangkat) mengunduh berkas JSON hasil baca SNMP, tanpa kredensial. Kirimkan berkas itu bila sensor perangkat belum terbaca (mis. NVR atau kamera), supaya profilnya bisa dibuat dari data asli. Periksa isinya sebelum dikirim ke luar perusahaan.</li>
    </ul>
</div></section>

<section class="card" id="api"><div class="hd"><h2>Integrasi lewat API</h2></div><div class="bd">
    <ul class="help-list">
        <li>Sistem lain (SOC, dashboard manajemen) bisa membaca data ANTAREJA lewat <code>/api/v1</code> dengan token dari menu <i>Token API</i> (admin). API ini hanya membaca dan tidak pernah mengirim kredensial.</li>
        <li>Batasi token ke IP server pemakainya, beri masa berlaku, dan cabut bila tidak dipakai lagi. Daftar token menunjukkan kapan dan dari IP mana token terakhir dipakai.</li>
    </ul>
</div></section>

<section class="card" id="diag"><div class="hd"><h2>Alat diagnosa & down bulanan</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Alat diagnosa</b> di halaman perangkat menjalankan ping, traceroute, atau cek port TCP dari server ANTAREJA ke perangkat itu. Bila traceroute berhenti sebelum tujuan, hop terakhir yang menjawab adalah titik yang perlu diperiksa (radio, switch, atau router di lokasi itu). Cek port berguna untuk kamera (554 RTSP), web perangkat (80/443), atau SSH (22).</li>
        <li><b>Down bulanan</b> (menu Laporan) menunjukkan persen waktu down tiap perangkat per bulan, dengan rumus yang sama seperti SLA perangkat. Waktu di dalam maintenance tidak dihitung down. Angka bulan berjalan masih sementara dan dikunci setelah bulan berganti.</li>
    </ul>
</div></section>

<section class="card" id="daya"><div class="hd"><h2>Daya menara (Modbus)</h2></div><div class="bd">
    <ul class="help-list">
        <li>Menara dan panel yang memakai solar controller, baterai, atau genset bisa dibaca lewat Modbus: tegangan baterai, arus panel surya, kapasitas baterai, bahan bakar. Nilainya ada di halaman <b>Sensor</b> tab <i>Daya &amp; UPS</i> dan di halaman perangkat.</li>
        <li>Tegangan baterai yang terus turun di malam hari adalah tanda menara akan mati sebelum pagi. Laporkan ke engineer sebelum perangkatnya down.</li>
        <li>Perangkat menara down padahal radio tidak bermasalah? Cek dulu tegangan baterai dan arus panel surya terakhir sebelum down.</li>
    </ul>
</div></section>

<section class="card" id="runbook"><div class="hd"><h2>Runbook</h2></div><div class="bd">
    <ul class="help-list">
        <li>Problem yang punya langkah penanganan menampilkan tautan <b>Runbook</b>. Ikuti langkahnya dari atas; lima langkah pertama juga ada di pesan Telegram.</li>
        <li>Runbook ditulis engineer di menu <i>Runbook</i> dan bisa berlaku untuk jenis problem, kategori perangkat, site, atau satu perangkat. Bila beberapa cocok, yang paling spesifik dipakai.</li>
        <li>Langkah yang salah atau sudah tidak berlaku? Beri tahu engineer supaya diperbarui. Jangan menulis password di runbook.</li>
    </ul>
</div></section>

<section class="card" id="flow"><div class="hd"><h2>Flow trafik</h2></div><div class="bd">
    <ul class="help-list">
        <li>Bila link (mis. VSAT atau radio) penuh, buka <b>Flow trafik</b>, pilih router dan port link itu, arah <i>Keluar</i> atau <i>Masuk</i>. Daftar <i>Sumber terbesar</i> dan <i>Aplikasi</i> menunjukkan siapa dan apa yang memakai bandwidth.</li>
        <li>Data per 5 menit; trafik 5 menit terakhir baru muncul setelah ringkasannya ditutup.</li>
        <li>Hanya router yang dikonfigurasi mengirim flow yang punya data di sini.</li>
    </ul>
</div></section>

<section class="card" id="cek"><div class="hd"><h2>Cek layanan</h2></div><div class="bd">
    <ul class="help-list">
        <li>Perangkat bisa menjawab ping padahal aplikasinya mati. <b>Cek layanan</b> memeriksa aplikasinya langsung: halaman web (misalnya halaman login FMS), port TCP (misalnya database), atau server DNS.</li>
        <li><b>Gagal</b> dua kali berturut-turut menjadi problem. <b>Lambat</b> (di atas ambang) dan <b>sertifikat HTTPS hampir habis</b> menjadi Warning.</li>
        <li>Bila perangkatnya sendiri down atau sedang maintenance, problem cek ditandai terdampak dan tidak membanjiri notifikasi.</li>
        <li>Pesan galat menjelaskan penyebabnya, misalnya <i>koneksi ditolak</i> (layanan tidak berjalan), <i>kode HTTP 503</i> (aplikasi bermasalah), atau <i>sertifikat ditolak</i>.</li>
    </ul>
</div></section>

<section class="card" id="dampak"><div class="hd"><h2>Peta dampak</h2></div><div class="bd">
    <ul class="help-list">
        <li>Menu <b>Dampak</b> menampilkan tiap perangkat down yang menjadi <i>sumber</i> gangguan (induknya sendiri tidak down) dan apa saja yang ikut terganggu: perangkat di bawahnya, kamera, layanan, dan site yang putus total. Tangani sumbernya dulu.</li>
        <li>Perhitungan memakai isian <i>perangkat induk</i>. Perangkat yang induknya belum diisi tidak ikut terhitung, jadi minta engineer melengkapinya.</li>
        <li><b>Simulasi</b>: pilih satu atau beberapa perangkat untuk melihat dampaknya bila mati, mis. sebelum mematikan switch untuk maintenance.</li>
        <li>Ringkasan dampak juga muncul di daftar Problem dan di pesan Telegram atau email untuk problem down.</li>
    </ul>
</div></section>

<section class="card" id="site"><div class="hd"><h2>Collector site</h2></div><div class="bd">
    <ul class="help-list">
        <li>Perangkat di site yang jalurnya ke pusat bisa putus (radio, VSAT) dipantau oleh <b>collector site</b> yang dipasang di site itu. Di halaman perangkat tertulis <i>Dipantau dari collector site ...</i>.</li>
        <li>Bila jalur ke pusat putus, status perangkat site <b>tidak</b> berubah menjadi down. Yang muncul adalah peringatan sistem <i>Collector site tidak melapor</i>. Angka di layar adalah hasil terakhir yang diterima.</li>
        <li>Selama putus, collector site tetap mengukur dan menyimpan hasilnya. Begitu jalur pulih, semua hasil dikirim dan kejadian down atau up yang terjadi selama putus muncul dengan jam aslinya.</li>
        <li>Tombol <i>Periksa sekarang</i> tidak berlaku untuk perangkat ini; pemeriksaan berjalan dari site setiap menit.</li>
    </ul>
</div></section>

<section class="card" id="akun"><div class="hd"><h2>2FA & login kantor</h2></div><div class="bd">
    <ul class="help-list">
        <li><b>Verifikasi dua langkah</b>: di Profil, klik <i>Aktifkan</i>, pindai kode QR dengan aplikasi autentikator di HP, lalu masukkan 6 digit kodenya. Setelah itu setiap login meminta kode dari aplikasi.</li>
        <li>Simpan <b>kode cadangan</b> yang tampil saat aktivasi. Bila HP hilang, satu kode cadangan bisa dipakai sekali untuk masuk. Bila semuanya hilang, minta admin mereset 2FA akun Anda.</li>
        <li>Kode ditolak padahal benar? Jam HP kemungkinan tidak tepat. Aktifkan waktu otomatis di pengaturan HP.</li>
        <li>Bila admin mengaktifkan <b>login kantor (LDAP)</b>, masuk dengan username dan password akun kantor. Peran mengikuti grup di direktori kantor; password diganti di sana, bukan di ANTAREJA.</li>
    </ul>
</div></section>

<section class="card" id="noc"><div class="hd"><h2>Layar NOC</h2></div><div class="bd">
    <ul class="help-list">
        <li>Login dengan akun viewer + centang <i>Ingat saya</i>, buka <i>Layar NOC</i>, tekan <kbd>F</kbd> untuk layar penuh.</li>
        <li>Klik tombol <b>SUARA</b> sekali setelah membuka (browser mewajibkan satu klik sebelum boleh berbunyi).</li>
        <li>Pilih <b>OTOMATIS</b> agar tampilan bergantian: dinding status, topologi, lalu peta lokasi (tiap 30 detik).</li>
        <li>Indikator <b>LIVE</b> menguning bila layar gagal mengambil data. Alarm <b>"COLLECTOR TIDAK MELAPOR, DATA USANG"</b> berarti mesin pemantau ANTAREJA berhenti: status di layar sudah tidak bisa dipercaya, hubungi admin.</li>
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
        <tr><td>RSRP LTE</td><td class="num">≤ -105 dBm</td><td class="num">≤ -115 dBm</td><td>Kuat sinyal modem 4G</td></tr>
        <tr><td>RSRQ LTE</td><td class="num">≤ -15 dB</td><td class="num">≤ -20 dB</td><td>Kualitas sinyal modem 4G</td></tr>
        <tr><td>SINR LTE</td><td class="num">≤ 3 dB</td><td class="num">≤ 0 dB</td><td>Sinyal dibanding derau</td></tr>
        <tr><td>Baterai UPS</td><td class="num">≤ 50%</td><td class="num">≤ 20%</td><td>Muatan</td></tr>
        <tr><td>Waktu cadangan UPS</td><td class="num">≤ 15 menit</td><td class="num">≤ 5 menit</td><td></td></tr>
        <tr><td>Beban UPS</td><td class="num">≥ 80%</td><td class="num">≥ 95%</td><td></td></tr>
        </tbody>
    </table>
    <p class="muted" style="padding:0 16px">Ambang bisa diatur khusus per perangkat (mis. radio PTP jarak jauh dengan sinyal normal −74 dBm) di form perangkat, bagian <i>Ambang khusus</i>.</p>
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
        <dt>RSRP, RSRQ, SINR</dt><dd>Ukuran sinyal modem 4G/LTE: kuat sinyal, kualitas, dan perbandingan sinyal dengan derau. Makin tinggi makin baik.</dd>
        <dt>CCQ</dt><dd>Client Connection Quality (MikroTik): efisiensi link nirkabel dalam %.</dd>
        <dt>Uptime</dt><dd>Lama perangkat menyala sejak boot terakhir; turun tiba-tiba = reboot.</dd>
        <dt>Syslog</dt><dd>Pesan log yang dikirim perangkat ke ANTAREJA (halaman Log).</dd>
        <dt>SLA / ketersediaan</dt><dd>Persentase waktu perangkat up dalam periode; jam maintenance tidak dihitung down.</dd>
        <dt>ACK</dt><dd>Acknowledge, menandai problem sudah ditangani.</dd>
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
