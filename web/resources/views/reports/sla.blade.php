@extends('layouts.app')
@section('title', 'SLA bulanan')
@php
    $fmtPct = fn ($v) => $v === null ? '–' : number_format($v, $v >= 99.995 ? 2 : 3, ',', '.') . '%';
    $fmtDur = fn ($s) => $s < 60 ? ($s > 0 ? '< 1 menit' : '0') : \App\Support\Fmt::duration((int) $s);
    $tone = fn ($v) => $v === null ? '' : ($v >= $target ? 'ok' : ($v >= $target - 1 ? 'warn' : 'crit'));
@endphp
@section('content')
<div class="page-head">
    <div><h1>SLA bulanan · {{ $start->translatedFormat('F Y') }}</h1>
        <p>Ketersediaan = waktu perangkat menjawab ÷ waktu dipantau. Gangguan selama maintenance tidak dihitung. @if($partial)<b>Bulan berjalan — angka sementara.</b>@endif</p></div>
    <div class="actions">
        <a class="btn" href="{{ route('reports.sla.csv', request()->only('bulan', 'site')) }}"><x-icon name="download"/>CSV (Excel)</a>
        <button class="btn" type="button" onclick="window.print()">Cetak / PDF</button>
    </div>
</div>
<section class="card" style="margin-bottom:16px">
    <form class="filters" method="get">
        <select class="input" name="bulan" aria-label="Bulan" onchange="this.form.submit()">
            @foreach($months as $m)<option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $start->format('Y-m'))>{{ $m->translatedFormat('F Y') }}</option>@endforeach
        </select>
        <select class="input" name="site" aria-label="Site" onchange="this.form.submit()"><option value="">Semua site</option>
            @foreach($sitesAll as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach</select>
        <span class="muted" style="margin-left:auto">Target: <b>{{ $fmtPct($target) }}</b></span>
        @can('admin')
            <details class="ack"><summary class="btn sm">Ubah target</summary>
                <form method="post" action="{{ route('reports.sla.target') }}" class="card ack-pop">@csrf @method('PUT')
                    <label for="target" style="font-weight:500">Target ketersediaan (%)</label>
                    <input class="input" type="number" step="0.01" min="50" max="100" id="target" name="target" value="{{ $target }}">
                    <button class="btn primary sm">Simpan</button></form></details>
        @endcan
    </form>
</section>

<div class="kpis">
    <div class="kpi {{ $tone($total['availability']) }}"><div class="lbl">Ketersediaan total</div><div class="val">{{ $fmtPct($total['availability']) }}</div><div class="note">{{ $total['devices'] }} perangkat</div></div>
    <div class="kpi"><div class="lbl">Total downtime</div><div class="val" style="font-size:22px;padding-top:6px">{{ $fmtDur($total['down']) }}</div><div class="note">gabungan semua perangkat</div></div>
    <div class="kpi"><div class="lbl">Gangguan</div><div class="val">{{ $total['incidents'] }}</div><div class="note">kejadian down</div></div>
    <div class="kpi {{ $devices->filter(fn ($d) => $d['availability'] !== null && $d['availability'] < $target)->count() ? 'warn' : 'ok' }}"><div class="lbl">Di bawah target</div>
        <div class="val">{{ $devices->filter(fn ($d) => $d['availability'] !== null && $d['availability'] < $target)->count() }}</div><div class="note">perangkat</div></div>
</div>

<div class="grid g-main">
    <section class="card"><div class="hd"><h2>Per perangkat</h2><span class="sub">terburuk di atas</span></div><div class="bd flush">
        @if($devices->isEmpty())<div class="empty">Tidak ada perangkat yang dipantau pada periode ini.</div>@else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Perangkat</th><th>Site</th><th class="num">Ketersediaan</th><th class="num">Downtime</th><th class="num">Gangguan</th><th class="num">Rata-rata pulih</th></tr></thead>
            <tbody>
            @foreach($devices->sortBy('availability') as $d)
                <tr>
                    <td class="name"><a href="{{ route('devices.show', $d['id']) }}">{{ $d['name'] }}</a><small class="mono">{{ $d['host'] }}</small></td>
                    <td>{{ $d['site'] ?? '–' }}</td>
                    <td class="num">@if($tone($d['availability']) === 'ok'){{ $fmtPct($d['availability']) }}@else<span class="badge plain {{ $tone($d['availability']) }}">{{ $fmtPct($d['availability']) }}</span>@endif</td>
                    <td class="num">{{ $fmtDur($d['down']) }}</td>
                    <td class="num">{{ $d['incidents'] }}</td>
                    <td class="num">{{ $d['mttr'] !== null ? $fmtDur($d['mttr']) : '–' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>@endif
    </div></section>
    <section class="card"><div class="hd"><h2>Per site</h2></div><div class="bd flush">
        <table class="t"><thead><tr><th>Site</th><th class="num">Ketersediaan</th><th class="num">Gangguan</th></tr></thead><tbody>
        @foreach($sites as $code => $s)
            <tr><td class="name">{{ $code }}<small>{{ $s['devices'] }} perangkat</small></td>
                <td class="num">@if($tone($s['availability']) === 'ok'){{ $fmtPct($s['availability']) }}@else<span class="badge plain {{ $tone($s['availability']) }}">{{ $fmtPct($s['availability']) }}</span>@endif</td>
                <td class="num">{{ $s['incidents'] }}</td></tr>
        @endforeach
        </tbody></table>
    </div></section>
</div>
@endsection
