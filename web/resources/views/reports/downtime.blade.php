@extends('layouts.app')
@section('title', 'Down bulanan')
@php
    $P = fn ($v) => \App\Http\Controllers\DowntimeController::pct($v);
    $limit = 100 - $target; // batas % down sesuai target SLA
    $tone = fn ($v) => $v === null ? 'muted' : ($v <= 0 ? 'muted' : ($v > $limit ? 'dt-crit' : 'dt-warn'));
    $cur = now()->format('Y-m');
    $last = $total[$to->format('Y-m')] ?? null;
    $lastPct = $last && $last->mon > 0 ? $last->down / $last->mon * 100 : null;
    $over = $devices->filter(fn ($d) => ($c = $d->cells[$to->format('Y-m')] ?? null) && $c->monitored_s > 0 && $c->down_s / $c->monitored_s * 100 > $limit)->count();
@endphp
@section('content')
<div class="page-head">
    <div><h1>Down bulanan</h1>
        <p>Persentase waktu down tiap perangkat per bulan: lama down di luar maintenance dibagi waktu dipantau dalam bulan itu. Rumusnya sama dengan laporan SLA perangkat. Data disimpan tiap jam sehingga riwayatnya tetap ada walau perangkat dihapus atau diganti nama.@if($to->format('Y-m') === $cur) <b>Bulan berjalan masih sementara.</b>@endif</p></div>
    <div class="actions"><a class="btn" href="{{ route('reports.downtime.csv', request()->query()) }}"><x-icon name="download"/>CSV (Excel)</a><button class="btn" type="button" data-print>Cetak / PDF</button></div>
</div>

<form class="filters" method="get">
    <select class="input" name="sampai" aria-label="Sampai bulan" data-autosubmit>
        @foreach($monthsSel as $m)<option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $to->format('Y-m'))>sampai {{ $m->translatedFormat('F Y') }}</option>@endforeach</select>
    <select class="input" name="bulan" aria-label="Jumlah bulan" data-autosubmit>
        @foreach([3, 6, 12, 24] as $k)<option value="{{ $k }}" @selected($n === $k)>{{ $k }} bulan</option>@endforeach</select>
    <select class="input" name="site" aria-label="Site" data-autosubmit><option value="">Semua site</option>
        @foreach($sites as $s)<option value="{{ $s }}" @selected(($f['site'] ?? '') === $s)>{{ $s }}</option>@endforeach</select>
    <select class="input" name="kategori" aria-label="Kategori" data-autosubmit><option value="">Semua kategori</option>
        @foreach($categories as $k => $v)<option value="{{ $k }}" @selected(($f['kategori'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
    <label class="check"><input type="checkbox" name="pernah_down" value="1" data-autosubmit @checked(! empty($f['pernah_down']))><span>Hanya yang pernah down</span></label>
</form>

<div class="kpis">
    <div class="kpi {{ $lastPct !== null && $lastPct > $limit ? 'crit' : '' }}"><div class="lbl">Down {{ $to->translatedFormat('F Y') }}</div><div class="val">{{ $lastPct === null ? 'belum ada' : $P($lastPct) }}@if($lastPct !== null)<small>%</small>@endif</div>
        <div class="note">semua perangkat digabung, batas {{ $P($limit) }}% (target {{ rtrim(rtrim(number_format($target, 2, ',', '.'), '0'), ',') }}%)</div></div>
    <div class="kpi {{ $over ? 'warn' : '' }}"><div class="lbl">Perangkat melewati batas</div><div class="val">{{ $over }}</div><div class="note">pada {{ $to->translatedFormat('F Y') }}</div></div>
    <div class="kpi"><div class="lbl">Gangguan</div><div class="val">{{ $last->outages ?? 0 }}</div><div class="note">{{ $last ? 'total lama ' . \App\Support\Fmt::duration((int) $last->down) : 'belum ada data' }}</div></div>
</div>

@if($devices->isEmpty())
    <div class="empty">Belum ada data untuk rentang dan saringan ini.@if(! $lastComputed) Rekap diisi collector tiap jam setelah pemasangan.@endif</div>
@else
<section class="card">
    <div class="hd"><h2>Per site</h2><span class="sub">% down gabungan semua perangkat di site</span></div>
    <div class="bd flush"><div class="table-wrap"><table class="t dt-matrix">
        <thead><tr><th>Site</th>@foreach($months as $m)<th class="num">{{ $m->translatedFormat('M y') }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($bySite as $site => $cols)
            <tr><td class="name">{{ $site }}</td>
            @foreach($months as $m)@php($c = $cols[$m->format('Y-m')] ?? null)@php($v = $c && $c->mon > 0 ? $c->down / $c->mon * 100 : null)
                <td class="num {{ $tone($v) }}" title="{{ $c ? $c->devices . ' perangkat, ' . $c->outages . ' gangguan' : 'tidak dipantau' }}">{{ $v === null ? '' : $P($v) }}</td>@endforeach</tr>
        @endforeach
        <tr class="dt-total"><td class="name">Semua</td>
            @foreach($months as $m)@php($c = $total[$m->format('Y-m')] ?? null)@php($v = $c && $c->mon > 0 ? $c->down / $c->mon * 100 : null)
                <td class="num {{ $tone($v) }}">{{ $v === null ? '' : $P($v) }}</td>@endforeach</tr>
        </tbody>
    </table></div></div>
</section>

<section class="card" style="margin-top:var(--space-xl)">
    <div class="hd"><h2>Per perangkat</h2><span class="sub">{{ $devices->count() }} perangkat, urut dari persen down tertinggi dalam rentang</span></div>
    <div class="bd flush"><div class="table-wrap"><table class="t dt-matrix">
        <thead><tr><th>Perangkat</th>@foreach($months as $m)<th class="num">{{ $m->translatedFormat('M y') }}</th>@endforeach<th class="num">Rentang</th></tr></thead>
        <tbody>
        @foreach($devices as $d)
            <tr>
                <td class="name">@if($d->id)<a href="{{ route('devices.show', $d->id) }}">{{ $d->name }}</a>@else{{ $d->name }} <span class="muted">(dihapus)</span>@endif<small>{{ $d->site }}, {{ $d->host }}</small></td>
                @foreach($months as $m)
                    @php($c = $d->cells[$m->format('Y-m')] ?? null)
                    @php($v = $c && $c->monitored_s > 0 ? $c->down_s / $c->monitored_s * 100 : null)
                    <td class="num {{ $tone($v) }}" title="{{ $c ? 'down ' . \App\Support\Fmt::duration((int) $c->down_s) . ', ' . $c->outages . ' gangguan' . ($c->final ? '' : ', sementara') : 'tidak dipantau' }}">{{ $v === null ? '' : $P($v) }}</td>
                @endforeach
                <td class="num {{ $tone($d->pct) }}"><b>{{ $P($d->pct) }}</b><small class="muted" style="display:block">{{ $d->outages }} gangguan</small></td>
            </tr>
        @endforeach
        </tbody>
    </table></div></div>
    <p class="hint muted" style="margin:var(--space-sm) 0 0">Sel kosong: perangkat belum ditambahkan atau tidak aktif pada bulan itu. Warna merah: di atas batas {{ $P($limit) }}% sesuai target SLA. Arahkan kursor ke angka untuk lama down dan jumlah gangguan.</p>
</section>
@endif
@endsection
