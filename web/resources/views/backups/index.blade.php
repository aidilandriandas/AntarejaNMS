@extends('layouts.app')
@section('title', 'Backup')
@section('content')
<div class="page-head">
    <div><h1>Backup</h1><p>Seluruh data ANTAREJA (perangkat, site, kredensial terenkripsi, problem, kejadian, pengguna, pengaturan) di-backup harian. Grafik VictoriaMetrics tidak termasuk.</p></div>
    <div class="actions"><form method="post" action="{{ route('backups.store') }}">@csrf<button class="btn primary" @disabled($pending)><x-icon name="database"/>{{ $pending ? 'Backup sedang berjalan…' : 'Backup sekarang' }}</button></form></div>
</div>
@unless($dirOk)
    <div class="alert error"><x-icon name="alert"/><div>Folder backup tidak terbaca oleh web. Pastikan <code>BACKUP_DIR</code> di <code>deploy/.env</code> benar lalu jalankan ulang <code>./antareja.sh up</code>.</div></div>
@endunless
<div class="alert info"><x-icon name="key"/><div><b>Simpan juga salinan berkas <code>deploy/.env</code></b> (terutama <code>ANTAREJA_APP_KEY</code>) di tempat aman terpisah. Tanpa kunci yang sama, kredensial SNMP dan notifikasi di dalam backup tidak bisa dibuka setelah pemulihan.</div></div>
<div class="grid g-main">
    <section class="card"><div class="hd"><h2>Riwayat backup</h2></div><div class="bd flush">
        @if($backups->isEmpty())
            <div class="empty">Belum ada backup. Tekan <b>Backup sekarang</b> untuk membuat yang pertama.</div>
        @else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Waktu</th><th>Jenis</th><th>Berkas</th><th class="num">Ukuran</th><th>Status</th>@if($offsite['type'] !== 'off')<th>Luar server</th>@endif<th></th></tr></thead>
            <tbody>
            @foreach($backups as $b)
                @php($at = \Illuminate\Support\Carbon::parse($b->requested_at))
                <tr class="{{ $b->status === 'deleted' ? 'is-off' : '' }}">
                    <td class="nowrap mono">{{ $at->format('d/m/Y H:i') }}</td>
                    <td>{{ $b->kind === 'manual' ? 'manual' . ($b->username ? ', ' . $b->username : '') : 'terjadwal' }}</td>
                    <td class="mono" style="font-size:12.5px">{{ $b->file ?? 'tidak ada' }}</td>
                    <td class="num">{{ $b->size_bytes ? number_format($b->size_bytes / 1048576, 1, ',', '.') . ' MB' : 'tidak ada' }}</td>
                    <td>@switch($b->status)
                        @case('ok')<span class="badge ok">berhasil</span>@break
                        @case('failed')<span class="badge crit" title="{{ $b->error }}">gagal</span><div class="muted" style="font-size:12px;max-width:260px">{{ $b->error }}</div>@break
                        @case('deleted')<span class="badge plain">terhapus (retensi)</span>@break
                        @default<span class="badge info">{{ $b->status === 'running' ? 'berjalan' : 'menunggu' }}</span>
                    @endswitch</td>
                    @if($offsite['type'] !== 'off')<td>@if($b->status === 'ok')
                        @if($b->offsite_status === 'ok')<span class="badge ok plain">tersalin</span>
                        @elseif($b->offsite_status === 'failed')<span class="badge crit plain">gagal{{ $b->offsite_tries >= 6 ? '' : ', dicoba lagi' }}</span><div class="muted" style="font-size:12px;max-width:260px">{{ $b->offsite_error }}</div>
                        @else<span class="badge plain">menunggu</span>@endif
                    @endif</td>@endif
                    <td class="right">@if($b->status === 'ok')<a class="btn sm" href="{{ route('backups.download', $b->id) }}"><x-icon name="download"/>Unduh</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </div></section>
    <div class="stack">
        <section class="card"><div class="hd"><h2>Jadwal</h2></div><div class="bd">
            <form method="post" action="{{ route('backups.settings') }}" class="form">
                @csrf @method('PUT')
                <label class="check"><input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])><span>Backup harian otomatis</span></label>
                <div class="row">
                    <div class="field"><label for="time">Jam</label><input class="input @error('time') is-err @enderror" type="time" id="time" name="time" value="{{ old('time', $settings['time']) }}"><x-field-error name="time"/></div>
                    <div class="field"><label for="keep">Simpan</label><input class="input" type="number" min="1" max="365" id="keep" name="keep" value="{{ old('keep', $settings['keep']) }}"><div class="hint">backup terbaru</div></div>
                </div>
                <div class="form-foot"><button class="btn primary">Simpan</button></div>
            </form>
            @unless($serviceSeen)<p class="muted" style="font-size:12.5px;margin:10px 0 0">Belum terlihat backup dalam 2 hari terakhir. Bila tombol backup tidak memproses apa pun, periksa layanan: <code>./antareja.sh logs backup</code>.</p>@endunless
        </div></section>
        <section class="card" id="luar-server"><div class="hd"><h2>Salinan ke luar server</h2></div><div class="bd">
            <form method="post" action="{{ route('backups.offsite') }}" class="form">
                @csrf @method('PUT')
                <div class="row">
                    <div class="field"><label for="off-type">Tujuan</label><select class="input" id="off-type" name="type">
                        @foreach(['off' => 'Mati', 'sftp' => 'Server SFTP (NAS, server lain)', 's3' => 'S3 atau MinIO'] as $k => $v)<option value="{{ $k }}" @selected(old('type', $offsite['type']) === $k)>{{ $v }}</option>@endforeach</select></div>
                    <div class="field" data-show-if="type=sftp,s3"><label for="off-keep">Simpan di tujuan</label><input class="input" type="number" min="1" max="365" id="off-keep" name="keep" value="{{ old('keep', $offsite['keep'] ?? 30) }}"><div class="hint">backup terbaru</div></div>
                </div>
                <div data-show-if="type=sftp" class="stack-sm">
                    <div class="row r3">
                        <div class="field"><label for="off-host">Host</label><input class="input mono @error('host') is-err @enderror" id="off-host" name="host" value="{{ old('host', $offsite['host'] ?? '') }}" placeholder="nas.kantor.local"><x-field-error name="host"/></div>
                        <div class="field"><label for="off-port">Port</label><input class="input" type="number" id="off-port" name="port" value="{{ old('port', $offsite['port'] ?? 22) }}"></div>
                        <div class="field"><label for="off-user">User</label><input class="input mono @error('user') is-err @enderror" id="off-user" name="user" value="{{ old('user', $offsite['user'] ?? '') }}" autocomplete="off"><x-field-error name="user"/></div>
                    </div>
                    <div class="field"><label for="off-dir">Folder</label><input class="input mono" id="off-dir" name="dir" value="{{ old('dir', $offsite['dir'] ?? '') }}" placeholder="/backup/antareja"></div>
                    <div class="field"><label for="off-pass">Password <span class="muted">(atau passphrase private key)</span></label><input class="input @error('password') is-err @enderror" type="password" id="off-pass" name="password" autocomplete="new-password" placeholder="{{ ! empty($offsiteHasSecret['password']) ? 'tersimpan, kosongkan bila tidak diganti' : '' }}"><x-field-error name="password"/></div>
                    <div class="field"><label for="off-key">Private key <span class="muted">(opsional, OpenSSH)</span></label><textarea class="input mono" id="off-key" name="private_key" rows="3" placeholder="{{ ! empty($offsiteHasSecret['private_key']) ? 'tersimpan, kosongkan bila tidak diganti' : '-----BEGIN OPENSSH PRIVATE KEY-----' }}"></textarea></div>
                    @if($offsiteHostKey)<p class="hint muted" style="margin:0">Host key server tercatat: <code>{{ $offsiteHostKey }}</code>. Koneksi ditolak bila berubah.</p>@endif
                </div>
                <div data-show-if="type=s3" class="stack-sm">
                    <div class="field"><label for="off-ep">Endpoint</label><input class="input mono @error('endpoint') is-err @enderror" id="off-ep" name="endpoint" value="{{ old('endpoint', $offsite['endpoint'] ?? '') }}" placeholder="https://minio.kantor.local:9000"><x-field-error name="endpoint"/></div>
                    <div class="row r3">
                        <div class="field"><label for="off-bucket">Bucket</label><input class="input mono @error('bucket') is-err @enderror" id="off-bucket" name="bucket" value="{{ old('bucket', $offsite['bucket'] ?? '') }}"><x-field-error name="bucket"/></div>
                        <div class="field"><label for="off-prefix">Folder</label><input class="input mono" id="off-prefix" name="prefix" value="{{ old('prefix', $offsite['prefix'] ?? '') }}" placeholder="antareja"></div>
                        <div class="field"><label for="off-region">Region</label><input class="input mono" id="off-region" name="region" value="{{ old('region', $offsite['region'] ?? 'us-east-1') }}"></div>
                    </div>
                    <div class="row">
                        <div class="field"><label for="off-ak">Access key</label><input class="input mono @error('access_key') is-err @enderror" id="off-ak" name="access_key" value="{{ old('access_key', $offsite['access_key'] ?? '') }}" autocomplete="off"><x-field-error name="access_key"/></div>
                        <div class="field"><label for="off-sk">Secret key</label><input class="input @error('secret_key') is-err @enderror" type="password" id="off-sk" name="secret_key" autocomplete="new-password" placeholder="{{ ! empty($offsiteHasSecret['secret_key']) ? 'tersimpan, kosongkan bila tidak diganti' : '' }}"><x-field-error name="secret_key"/></div>
                    </div>
                </div>
                <div class="form-foot"><button class="btn primary">Simpan</button></div>
            </form>
            @if($offsite['type'] !== 'off')
            <div class="tool-bar" style="margin-top:var(--space-sm)">
                <form method="post" action="{{ route('backups.offsite.test') }}">@csrf<button class="btn sm"><x-icon name="check"/>Uji koneksi</button></form>
                @if($offsiteHostKey)<form method="post" action="{{ route('backups.offsite.hostkey') }}" data-confirm="Reset host key? Lakukan hanya bila server tujuan memang diganti.">@csrf<button class="btn sm">Reset host key</button></form>@endif
            </div>
            @endif
            <p class="hint muted" style="margin:var(--space-sm) 0 0">Setiap backup yang berhasil disalin collector ke tujuan ini (berkas .dump dan manifest), lalu salinan lama di tujuan dihapus sesuai jumlah simpan. Password dan key terenkripsi di database dan tidak pernah ditampilkan lagi. Akun tujuan cukup punya hak tulis dan hapus di folder itu saja.</p>
        </div></section>
        <section class="card"><div class="hd"><h2>Memulihkan (restore)</h2></div><div class="bd" style="font-size:13.5px">
            <p style="margin-top:0">Pemulihan dilakukan dari server (bukan dari web) agar tidak terjadi tanpa sengaja:</p>
            <ol style="padding-left:18px;margin:0 0 10px">
                <li>Pasang ANTAREJA di VM baru seperti biasa, dengan <code>.env</code> lama (kunci sama).</li>
                <li>Salin berkas <code>.dump</code> ke folder backup.</li>
                <li>Jalankan <code>./antareja.sh restore antareja-…dump</code> lalu ketik <code>PULIHKAN</code>.</li>
            </ol>
            <p class="muted" style="margin:0">Bila VM rusak, ambil berkas <code>.dump</code> terbaru dari tujuan <a href="#luar-server">salinan ke luar server</a>. Alternatif: arahkan <code>BACKUP_DIR</code> ke folder NAS yang di-mount di VM.</p>
        </div></section>
    </div>
</div>
@endsection
