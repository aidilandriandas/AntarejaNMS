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
            <thead><tr><th>Waktu</th><th>Jenis</th><th>Berkas</th><th class="num">Ukuran</th><th>Status</th><th></th></tr></thead>
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
        <section class="card"><div class="hd"><h2>Memulihkan (restore)</h2></div><div class="bd" style="font-size:13.5px">
            <p style="margin-top:0">Pemulihan dilakukan dari server (bukan dari web) agar tidak terjadi tanpa sengaja:</p>
            <ol style="padding-left:18px;margin:0 0 10px">
                <li>Pasang ANTAREJA di VM baru seperti biasa, dengan <code>.env</code> lama (kunci sama).</li>
                <li>Salin berkas <code>.dump</code> ke folder backup.</li>
                <li>Jalankan <code>./antareja.sh restore antareja-…dump</code> lalu ketik <code>PULIHKAN</code>.</li>
            </ol>
            <p class="muted" style="margin:0">Arahkan <code>BACKUP_DIR</code> ke folder NAS yang di-mount di VM agar backup tetap ada walau VM rusak.</p>
        </div></section>
    </div>
</div>
@endsection
