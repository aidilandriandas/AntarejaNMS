@extends('layouts.app')
@section('title', 'SNMP trap')
@php($S = \App\Http\Controllers\TrapController::SEVERITIES)
@section('content')
<div class="page-head">
    <div><h1>SNMP trap</h1>
        <p>Pemberitahuan yang dikirim perangkat sendiri saat sesuatu terjadi, tanpa menunggu putaran polling. Trap port putus/tersambung, restart, dan UPS membuat ANTAREJA langsung membaca ulang perangkat itu.</p></div>
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">Trap 24 jam</div><div class="val">{{ number_format($stats->day, 0, ',', '.') }}</div>
        <div class="note">{{ $stats->last ? 'terakhir ' . \Illuminate\Support\Carbon::parse($stats->last)->diffForHumans() : 'belum pernah menerima trap' }}</div></div>
    <div class="kpi {{ $stats->link >= 20 ? 'warn' : '' }}"><div class="lbl">Port putus/tersambung</div><div class="val">{{ $stats->link }}</div>
        <div class="note">{{ $stats->link >= 20 ? 'banyak, periksa port yang flapping' : '24 jam terakhir' }}</div></div>
    <div class="kpi {{ $stats->unknown ? 'warn' : '' }}"><div class="lbl">Dari pengirim tak dikenal</div><div class="val">{{ $stats->unknown }}</div>
        <div class="note">{{ $stats->unknown ? 'IP belum terdaftar sebagai perangkat' : 'semua pengirim terdaftar' }}</div></div>
</div>

<form class="filters" method="get">
    <input class="input" type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Cari teks atau OID" aria-label="Cari">
    <select class="input" name="device" aria-label="Perangkat" data-autosubmit><option value="">Semua perangkat</option>
        @foreach($devices as $d)<option value="{{ $d->id }}" @selected(($f['device'] ?? null) == $d->id)>{{ $d->name }}</option>@endforeach</select>
    <select class="input" name="sev" aria-label="Severity minimal" data-autosubmit><option value="">Semua severity</option>
        @foreach($S as $k => [$l])<option value="{{ $k }}" @selected(($f['sev'] ?? '') === $k)>{{ $l }} ke atas</option>@endforeach</select>
    <select class="input" name="period" aria-label="Periode" data-autosubmit>
        @foreach(\App\Http\Controllers\TrapController::PERIODS as $k => $l)<option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>@endforeach</select>
    <label class="check"><input type="checkbox" name="known" value="1" data-autosubmit @checked(! empty($f['known']))><span>Hanya yang dikenal</span></label>
    <label class="check"><input type="checkbox" name="unknown" value="1" data-autosubmit @checked(! empty($f['unknown']))><span>Pengirim tak dikenal</span></label>
    <button class="btn">Saring</button>
</form>

<div class="grid g-main">
<section class="card"><div class="hd"><h2>Trap</h2><span class="sub">terbaru di atas</span></div>
    <div class="bd flush">
    @if($rows->isEmpty())
        <div class="empty">Tidak ada trap untuk saringan ini.@if(! $stats->last) Arahkan trap perangkat ke IP server ini port UDP {{ $port }}, lihat petunjuk di samping.@endif</div>
    @else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Waktu</th><th>Perangkat</th><th>Trap</th><th>Severity</th></tr></thead>
        <tbody>
        @foreach($rows as $t)
            @php([$sl, $st] = $S[$t->severity] ?? [$t->severity, ''])
            <tr>
                <td class="nowrap mono">{{ \Illuminate\Support\Carbon::parse($t->received_at)->format('d/m H:i:s') }}</td>
                <td class="name">@if($t->device_id)<a href="{{ route('devices.show', $t->device_id) }}">{{ $t->device }}</a>@else<span class="muted">belum terdaftar</span>@endif<small class="mono">{{ $t->source }}, v{{ $t->version }}</small></td>
                <td>{{ $t->summary }}
                    @php($vb = json_decode($t->varbinds, true) ?: [])
                    @if($vb)<details class="tr-vb"><summary>{{ $t->name ?? $t->trap_oid }}, {{ count($vb) }} varbind</summary>
                        <dl class="facts mono">@foreach($vb as $v)<dt>{{ $v['oid'] }}</dt><dd>{{ $v['value'] !== '' ? $v['value'] : 'kosong' }}</dd>@endforeach</dl></details>
                    @else<small class="muted mono" style="display:block">{{ $t->name ?? $t->trap_oid }}</small>@endif</td>
                <td><span class="badge plain {{ $st }}">{{ $sl }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @if($more)<div class="pager"><a class="btn sm" href="{{ request()->fullUrlWithQuery(['before' => $rows->last()->id]) }}">Lebih lama</a></div>@endif
    @endif
    </div>
</section>

<div class="stack">
    <section class="card"><div class="hd"><h2>Pengirim terbanyak 24 jam</h2></div><div class="bd flush">
        @if($top->isEmpty())<div class="empty">Belum ada.</div>@else
        <table class="t"><tbody>@foreach($top as $x)<tr><td class="name"><a href="{{ request()->fullUrlWithQuery(['device' => $x->id, 'before' => null]) }}">{{ $x->name }}</a></td><td class="num">{{ $x->n }}</td></tr>@endforeach</tbody></table>
        @endif
    </div></section>
    <section class="card"><div class="hd"><h2>Mengirim trap ke ANTAREJA</h2></div><div class="bd notes">
        <p>Tujuan trap: IP server ini, UDP <b>{{ $port }}</b>, versi 1 atau 2c. Inform dan SNMPv3 belum diterima.</p>
        <p>MikroTik: <code>/snmp set enabled=yes trap-version=2 trap-target=IP-SERVER trap-generators=interfaces,start-trap trap-interfaces=all</code></p>
        <p>Cisco: <code>snmp-server host IP-SERVER version 2c COMMUNITY</code> lalu <code>snmp-server enable traps snmp linkdown linkup coldstart</code></p>
        <p>Perangkat dicocokkan lewat IP pengirim (IP manajemen atau IP interface yang tercatat di IPAM). Batasi port {{ $port }} di firewall ke subnet manajemen perangkat.</p>
    </div></section>
    @can('admin')
    <section class="card"><div class="hd"><h2>Pengaturan</h2></div><div class="bd">
        <form method="post" action="{{ route('traps.settings') }}" class="form">@csrf
            <div class="field"><label for="keep">Simpan trap selama (hari)</label><input class="input" type="number" min="1" max="365" id="keep" name="keep" value="{{ old('keep', $keep) }}"><x-field-error name="keep"/></div>
            <div class="form-foot"><button class="btn primary">Simpan</button></div>
        </form>
    </div></section>
    @endcan
</div>
</div>
@endsection
