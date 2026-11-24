@extends('layouts.app')
@section('title', 'Ekspor & impor')
@php($labels = ['sites' => 'Site', 'devices' => 'Perangkat', 'links' => 'Sambungan manual', 'subnets' => 'Subnet (manual)', 'cameras' => 'Kamera', 'channels' => 'Kanal notifikasi', 'report_schedules' => 'Laporan terjadwal', 'services' => 'Layanan (SLA)', 'triggers' => 'Trigger', 'site_collectors' => 'Collector site', 'checks' => 'Cek layanan', 'runbooks' => 'Runbook', 'modbus_points' => 'Titik Modbus', 'settings' => 'Pengaturan'])
@section('content')
<div class="page-head">
    <div><h1>Ekspor & impor konfigurasi</h1><p>Satu berkas JSON berisi site, perangkat (termasuk ambang, induk, koordinat), sambungan manual, subnet, kamera, kanal notifikasi, laporan terjadwal, dan pengaturan, untuk pindah VM, membuat VM uji, atau cadangan yang bisa dibaca. Riwayat, metrik, log, dan akun pengguna tidak ikut (pakai <a href="{{ route('backups.index') }}">Backup</a> untuk itu).</p></div>
</div>
<div class="grid g-2">
<section class="card"><div class="hd"><h2>Ekspor</h2></div><div class="bd">
    <form method="post" action="{{ route('transfer.export') }}" class="stack">@csrf
        <label class="check"><input type="checkbox" name="with_secrets" value="1" data-toggle-target="#pass-fields" @checked(old('with_secrets'))><span>Sertakan rahasia<small>Community/USM SNMP, password SSH & kamera, token/password kanal, dienkripsi dengan frasa sandi di bawah (AES-256-GCM). Tanpa ini, rahasia harus diisi ulang setelah impor.</small></span></label>
        <div id="pass-fields" class="stack" @unless(old('with_secrets')) hidden @endunless>
            <div class="field"><label for="passphrase">Frasa sandi ekspor</label><input class="input @error('passphrase') is-err @enderror" type="password" id="passphrase" name="passphrase" minlength="12" autocomplete="new-password"><x-field-error name="passphrase"/>
                <div class="hint">Min. 12 karakter. Simpan terpisah dari berkasnya, tanpa frasa sandi, rahasia tidak bisa dibuka (juga oleh kami).</div></div>
            <div class="field"><label for="passphrase_confirmation">Ulangi frasa sandi</label><input class="input" type="password" id="passphrase_confirmation" name="passphrase_confirmation" autocomplete="new-password"></div>
        </div>
        <div><button class="btn primary"><x-icon name="download"/>Unduh berkas konfigurasi</button></div>
    </form>
</div></section>

<section class="card"><div class="hd"><h2>Impor</h2></div><div class="bd stack">
    <p class="muted" style="margin:0;font-size:13px">Data yang sudah ada <b>diperbarui</b> (dicocokkan lewat kode site, nama + host perangkat, nama kamera/kanal/laporan, CIDR subnet); yang belum ada <b>ditambahkan</b>. Tidak ada yang dihapus.</p>
    <form method="post" action="{{ route('transfer.preview') }}" enctype="multipart/form-data" class="stack">@csrf
        <div class="field"><label for="file">Berkas ekspor (.json)</label><input class="input @error('file') is-err @enderror" type="file" id="file" name="file" accept="application/json,.json" required><x-field-error name="file"/></div>
        <div><button class="btn">Pratinjau</button></div>
    </form>

    @if($plan)
    <div class="transfer-plan">
        <b>Pratinjau: {{ $file }}</b>
        <table class="t" style="margin:8px 0"><thead><tr><th>Bagian</th><th class="num">Baru</th><th class="num">Diperbarui</th></tr></thead><tbody>
        @foreach($labels as $k => $label)
            @php($r = $plan[$k] ?? null)
            @if($r && $r['total'])<tr><td>{{ $label }}</td><td class="num">{{ $r['new'] ?? 'tidak ada' }}</td><td class="num">{{ $r['update'] ?? ($k === 'links' ? 'yang belum ada saja' : $r['total']) }}</td></tr>@endif
        @endforeach
        </tbody></table>
        @foreach($plan['warnings'] as $w)<div class="alert warn" style="margin:6px 0">{{ $w }}</div>@endforeach
        <form method="post" action="{{ route('transfer.apply') }}" class="stack" data-confirm="Terapkan impor ini? Data yang cocok akan diperbarui.">@csrf
            @if($plan['has_secrets'])
            <div class="field"><label for="ipass">Frasa sandi berkas</label><input class="input @error('passphrase') is-err @enderror" type="password" id="ipass" name="passphrase" autocomplete="off"><x-field-error name="passphrase"/>
                <div class="hint">Berkas berisi rahasia terenkripsi.</div></div>
            <label class="check"><input type="checkbox" name="skip_secrets" value="1"><span>Impor tanpa rahasia<small>Rahasia yang sudah tersimpan di server ini tidak diubah.</small></span></label>
            @endif
            <div><button class="btn primary">Terapkan impor</button></div>
        </form>
    </div>
    @endif
</div></section>
</div>
@endsection
