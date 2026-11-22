@extends('layouts.app')
@section('title', 'Impor perangkat')
@section('content')
<div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / Impor CSV</div>
<div class="page-head"><div><h1>Impor perangkat dari CSV</h1>
    <p>Kolom: <code>nama;host;site;kategori;snmp;community;induk</code>. Site dibuat otomatis bila belum ada. Kosongkan <code>snmp</code> untuk perangkat hanya-ping. SNMPv3 diisi lewat form perangkat.</p></div>
    <div class="actions"><a class="btn" href="{{ route('devices.import.template') }}"><x-icon name="download"/>Unduh templat</a></div>
</div>
@if($rows === null)
<section class="card" style="max-width:860px"><div class="bd">
    <form method="post" action="{{ route('devices.import.preview') }}" enctype="multipart/form-data" class="form">
        @csrf
        <div class="field"><label for="file">Berkas CSV</label><input class="input" type="file" id="file" name="file" accept=".csv,text/csv,text/plain" style="padding-top:6px"></div>
        <div class="field"><label for="text">… atau tempel isi CSV</label>
            <textarea class="input mono" id="text" name="text" rows="10" placeholder="nama;host;site;kategori;snmp;community;induk&#10;Core Switch Pit A;10.10.1.1;PIT-A;core;2c;public-ro;">{{ old('text') }}</textarea></div>
        <p class="hint muted" style="margin:0">Kategori: {{ implode(', ', array_keys(\App\Models\Device::CATEGORIES)) }}. Kolom <code>induk</code> = nama perangkat uplink (terdaftar atau baris sebelumnya).</p>
        <div class="form-foot"><button class="btn primary">Pratinjau</button></div>
    </form>
</div></section>
@else
@php($ok = collect($rows)->where('errors', [])->count())
<section class="card">
    <div class="hd"><h2>Pratinjau: {{ $ok }} siap diimpor, {{ count($rows) - $ok }} bermasalah</h2>
        <div style="display:flex;gap:8px"><a class="btn sm" href="{{ route('devices.import') }}">Ulangi</a>
            @if($ok)<form method="post" action="{{ route('devices.import.store') }}">@csrf<button class="btn primary sm">Impor {{ $ok }} perangkat</button></form>@endif</div></div>
    <div class="bd flush"><div class="table-wrap"><table class="t">
        <thead><tr><th class="num">#</th><th>Nama</th><th>Host</th><th>Site</th><th>Kategori</th><th>SNMP</th><th>Induk</th><th>Keterangan</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
            <tr class="{{ $r['errors'] ? 'is-down' : '' }}">
                <td class="num muted">{{ $r['line'] }}</td><td>{{ $r['nama'] }}</td><td class="mono">{{ $r['host'] }}</td><td>{{ strtoupper($r['site']) ?: 'tidak ada' }}</td>
                <td>{{ $r['kategori'] }}</td><td>{{ $r['snmp'] ? 'v' . $r['snmp'] . ($r['community'] !== '' ? ', ••••' : '') : 'ping saja' }}</td><td>{{ $r['induk'] ?: 'tidak ada' }}</td>
                <td>@if($r['errors'])<span style="color:var(--crit)">{{ implode('; ', $r['errors']) }}</span>@else<span class="badge ok plain">siap</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div></div>
</section>
@endif
@endsection
