@extends('layouts.app')
@section('title', $s->name)
@php
    $fmtPct = fn ($v) => $v === null ? 'tidak ada' : number_format($v, $v >= 99.995 ? 2 : 3, ',', '.') . '%';
    $T = $s->target();
    $tone = fn ($v) => $v === null ? '' : ($v >= $T ? 'ok' : ($v >= $T - 1 ? 'warn' : 'crit'));
    [$state, $compState] = \App\Services\ServiceSla::liveStatus($s);
    [$lbl, $lt] = \App\Services\ServiceSla::STATES[$state];
    $dur = fn ($x) => \App\Http\Controllers\ServiceController::dur($x);
    $tz = config('app.timezone');
@endphp
@section('content')
<div class="page-head">
    <div><div class="crumbs"><a href="{{ route('services.index') }}">SLA layanan</a> / {{ $s->name }}</div>
        <h1>{{ $s->name }} <span class="badge {{ $lt }}" style="vertical-align:middle">{{ $lbl }}</span></h1>
        <p>{{ $s->description ?: 'Layanan' }}, {{ $s->site ? $s->site->code . ' (' . $s->site->name . ')' : 'lintas site' }}, target {{ $fmtPct($T) }}{{ $s->target_pct === null ? ' (global)' : '' }}</p></div>
    <div class="actions">
        <form class="filters" method="get" style="padding:0;border:0"><select class="input" name="bulan" aria-label="Bulan" data-autosubmit>
            @foreach($months as $m)<option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $start->format('Y-m'))>{{ $m->translatedFormat('F Y') }}</option>@endforeach</select></form>
        @can('manage-devices')
        <details class="ack"><summary class="btn"><x-icon name="edit"/>Ubah</summary>
            <form method="post" action="{{ route('services.update', $s) }}" class="ack-pop stack" style="width:340px">@csrf @method('PUT')
                @include('services.fields', ['s' => $s])
                <button class="btn primary sm">Simpan</button>
            </form></details>
        <form method="post" action="{{ route('services.destroy', $s) }}" data-confirm="Hapus layanan {{ $s->name }}? Perangkat tidak ikut terhapus.">@csrf @method('DELETE')<button class="btn"><x-icon name="trash"/>Hapus</button></form>
        @endcan
    </div>
</div>

<div class="kpis">
    <div class="kpi {{ $tone($sla['availability']) }}"><div class="lbl">Ketersediaan {{ $start->translatedFormat('M Y') }}</div><div class="val">{{ $s->components->isEmpty() ? 'tidak ada' : $fmtPct($sla['availability']) }}</div><div class="note">{{ $partial ? 'sementara, bulan berjalan' : 'target ' . $fmtPct($T) }}</div></div>
    <div class="kpi"><div class="lbl">Downtime</div><div class="val" style="font-size:22px;padding-top:6px">{{ $dur($sla['down']) }}</div><div class="note">{{ $sla['count'] }} gangguan, terlama {{ $dur($sla['longest']) }}</div></div>
    <div class="kpi {{ $sla['degraded'] > 0 ? 'warn' : '' }}"><div class="lbl">Tanpa cadangan</div><div class="val" style="font-size:22px;padding-top:6px">{{ $dur($sla['degraded']) }}</div><div class="note">jalan, tapi ada perangkat yang down</div></div>
    @if($sla['availability'] !== null && $sla['monitored'] > 0)
    <div class="kpi"><div class="lbl">Sisa jatah downtime</div><div class="val" style="font-size:22px;padding-top:6px">@php($budget = $sla['monitored'] * (100 - $T) / 100 - $sla['down']){{ $budget > 0 ? $dur($budget) : 'habis' }}</div><div class="note">agar tetap ≥ target bulan ini</div></div>
    @endif
</div>

<div class="grid g-main">
<div class="stack">
    <section class="card"><div class="hd"><h2>Komponen</h2><span class="sub">layanan jalan bila semua komponen memenuhi syarat</span></div><div class="bd flush">
        @if($s->components->isEmpty())<div class="empty">Belum ada komponen.@can('manage-devices') Tambahkan di samping.@endcan</div>@else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Komponen</th><th>Aturan</th><th>Perangkat</th><th>Sekarang</th>@can('manage-devices')<th></th>@endcan</tr></thead>
            <tbody>
            @foreach($s->components as $c)
                @php([$cl, $ct] = \App\Services\ServiceSla::STATES[$compState[$c->id]])
                <tr class="{{ $compState[$c->id] === 'down' ? 'is-down' : '' }}">
                    <td class="name">{{ $c->name }}</td>
                    <td class="nowrap">{{ $c->ruleLabel() }}</td>
                    <td>@foreach($c->devices as $d)<div><span class="sdot {{ $d->status === 'down' ? 'crit' : ($d->status === 'up' ? 'ok' : 'off') }}"></span> <a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a></div>@endforeach</td>
                    <td><span class="badge plain {{ $ct }}">{{ $cl }}</span></td>
                    @can('manage-devices')
                    <td class="right nowrap">
                        <details class="ack"><summary class="btn sm">Ubah</summary><div class="ack-pop" style="width:380px">@include('services.component-form', ['c' => $c])</div></details>
                        <form method="post" action="{{ route('services.components.destroy', [$s, $c]) }}" data-confirm="Hapus komponen {{ $c->name }}?" style="display:inline">@csrf @method('DELETE')<button class="linkbtn" style="font-size:12.5px">hapus</button></form>
                    </td>
                    @endcan
                </tr>
            @endforeach
            </tbody>
        </table></div>@endif
    </div></section>

    <section class="card"><div class="hd"><h2>Gangguan {{ $start->translatedFormat('F Y') }}</h2></div><div class="bd flush">
        @if(! $sla['outages'])<div class="empty"><x-icon name="check"/>Tidak ada gangguan layanan.</div>@else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Mulai</th><th>Selesai</th><th class="num">Lama</th><th>Penyebab (komponen)</th></tr></thead>
            <tbody>
            @foreach(array_reverse($sla['outages']) as $o)
                @php($open = $o['end'] >= time() - 90)
                <tr>
                    <td class="nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp((int) $o['start'], $tz)->format('d/m H:i') }}</td>
                    <td class="nowrap">@if($open)<span class="badge crit plain">berlangsung</span>@else{{ \Illuminate\Support\Carbon::createFromTimestamp((int) $o['end'], $tz)->format('d/m H:i') }}@endif</td>
                    <td class="num">{{ $dur($o['seconds']) }}</td>
                    <td>{{ implode(', ', $o['components']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>@endif
    </div></section>
</div>

<div class="stack">
    @can('manage-devices')
    <section class="card"><div class="hd"><h2>Tambah komponen</h2></div><div class="bd">@include('services.component-form', ['c' => null])</div></section>
    @endcan
    <section class="card"><div class="hd"><h2>12 bulan terakhir</h2></div><div class="bd flush">
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Bulan</th><th class="num">Ketersediaan</th><th class="num">Downtime</th></tr></thead>
            <tbody>
            @foreach($history->reverse() as $h)
                <tr><td><a href="{{ route('services.show', [$s, 'bulan' => $h['month']->format('Y-m')]) }}">{{ $h['month']->translatedFormat('M Y') }}</a></td>
                    <td class="num">@if($s->components->isEmpty())<span class="nil">tidak ada</span>@else<span class="badge plain {{ $tone($h['availability']) }}">{{ $fmtPct($h['availability']) }}</span>@endif</td>
                    <td class="num">{{ $dur($h['down']) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        <p class="muted" style="font-size:12px;padding:8px 16px">Dihitung ulang dari riwayat problem, jadi komponen yang baru ditambahkan juga berlaku untuk bulan-bulan sebelumnya.</p>
    </div></section>
</div>
</div>
@endsection
