@extends('layouts.app')
@section('title', 'Exporter ' . $device->name)
@php($show = fn ($v) => $v === null ? '' : str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.')))
@php($tone = ['warn' => 'txt-warn', 'crit' => 'txt-crit'])
@section('content')
<div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / <a href="{{ route('devices.show', $device) }}">{{ $device->name }}</a> / Exporter</div>
<div class="page-head"><div><h1>Exporter {{ $device->name }}</h1>
    <p>Membaca CPU, memori, disk, dan status layanan server lewat <b>node_exporter</b> (Linux) atau <b>windows_exporter</b> (Windows) tiap menit. Hasilnya menjadi sensor perangkat: tampil di halaman Sensor, punya grafik, dan bisa menjadi problem. Ambangnya ikut ambang CPU, memori, dan disk perangkat ini.</p></div></div>

<div class="grid g-main">
<div class="stack">
<section class="card"><div class="hd"><h2>Bacaan terakhir</h2>@if($device->exporter_at)<span class="sub">{{ $device->exporter_at->diffForHumans() }}</span>@endif</div><div class="bd flush">
    @if($values->isEmpty())<div class="empty">Belum ada bacaan. Aktifkan exporter di samping lalu tunggu satu sampai dua menit, atau klik Uji scrape.</div>@else
    <table class="t"><tbody>
    @foreach($values as $s)
        <tr><td class="name">{{ $s->label }}</td>
            <td class="num"><span class="{{ $tone[$s->status] ?? '' }}">@if($s->value !== null){{ $show($s->value) }} {{ $s->unit }}@else{{ $s->text }}@endif</span></td></tr>
    @endforeach
    </tbody></table>@endif
</div></section>

@if($test)
<section class="card"><div class="hd"><h2>Hasil uji scrape</h2><span class="sub">{{ $test['kind'] === 'windows' ? 'windows_exporter' : 'node_exporter' }}</span></div><div class="bd flush">
    <table class="t"><tbody>
    @forelse($test['readings'] as $r)
        <tr><td>{{ $r['label'] }}</td><td class="num"><span class="{{ $tone[$r['status'] ?? ''] ?? '' }}">@if(isset($r['value'])){{ $show($r['value']) }} {{ $r['unit'] ?? '' }}@else{{ $r['text'] ?? '' }}@endif</span></td></tr>
    @empty
        <tr><td class="muted">Metrik terbaca tetapi belum ada nilai yang bisa dihitung.</td></tr>
    @endforelse
    </tbody></table>
    <p class="hint muted" style="margin:0;padding:var(--space-sm) var(--space-md)">CPU baru terhitung dari dua kali scrape, jadi tidak muncul di uji sekali jalan.</p>
</div></section>
@endif

<section class="card"><div class="hd"><h2>Memasang exporter</h2></div><div class="bd notes">
    <p style="margin-top:0"><b>Linux:</b> jalankan node_exporter (port bawaan 9100). Untuk memantau layanan, aktifkan collector systemd: <code>node_exporter --collector.systemd</code>. URL: <code>http://IP_SERVER:9100/metrics</code>.</p>
    <p><b>Windows:</b> pasang windows_exporter (port bawaan 9182) dengan collector cpu, memory, logical_disk, dan service. URL: <code>http://IP_SERVER:9182/metrics</code>.</p>
    <p class="muted" style="margin-bottom:0">Batasi port exporter di firewall server supaya hanya bisa dibuka dari IP ANTAREJA. ANTAREJA tidak menyimpan kata sandi exporter, jadi URL dengan nama pengguna dan kata sandi ditolak.</p>
</div></section>
</div>

<div class="stack">
<section class="card"><div class="hd"><h2>Pengaturan</h2>@if($device->exporter_url)<span class="badge {{ $device->exporter_error ? 'warn' : 'ok' }}">{{ $device->exporter_error ? 'ada galat' : 'aktif' }}</span>@else<span class="badge">mati</span>@endif</div><div class="bd">
    <form method="post" action="{{ route('exporter.update', $device) }}" class="form">@csrf @method('PUT')
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', (bool) $device->exporter_url))><span>Scrape exporter tiap menit</span></label>
        <div class="field"><label for="exporter_url">URL metrics</label><input class="input mono @error('exporter_url') is-err @enderror" id="exporter_url" name="exporter_url" maxlength="255" value="{{ old('exporter_url', $device->exporter_url ?? 'http://' . $device->host . ':9100/metrics') }}"><x-field-error name="exporter_url"/></div>
        <div class="field"><label for="exporter_services">Layanan dipantau</label><textarea class="input mono @error('exporter_services') is-err @enderror" id="exporter_services" name="exporter_services" rows="3" placeholder="nginx, postgresql">{{ old('exporter_services', $device->exporter_services) }}</textarea>
            <div class="hint">Pisahkan dengan koma. Linux: nama unit systemd (tanpa .service boleh). Windows: nama layanan, bukan nama tampilan (mis. MSSQLSERVER). Berhenti atau gagal menjadi problem.</div><x-field-error name="exporter_services"/></div>
        <div class="form-foot"><button class="btn primary">Simpan</button></div>
    </form>
    @if($device->exporter_error)<p class="hint txt-warn" style="margin:var(--space-sm) 0 0">{{ $device->exporter_error }}</p>@endif
    @if($device->exporter_url)
    <form method="post" action="{{ route('exporter.test', $device) }}" style="margin-top:var(--space-sm)">@csrf<button class="btn"><x-icon name="check"/>Uji scrape sekarang</button></form>
    @endif
</div></section>
</div>
</div>
@endsection
