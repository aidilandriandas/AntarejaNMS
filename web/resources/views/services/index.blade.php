@extends('layouts.app')
@section('title', 'SLA layanan')
@php
    $fmtPct = fn ($v) => $v === null ? 'tidak ada' : number_format($v, $v >= 99.995 ? 2 : 3, ',', '.') . '%';
    $tone = fn ($v, $t) => $v === null ? '' : ($v >= $t ? 'ok' : ($v >= $t - 1 ? 'warn' : 'crit'));
    $States = \App\Services\ServiceSla::STATES;
    $live = $services->mapWithKeys(fn ($s) => [$s->id => \App\Services\ServiceSla::liveStatus($s)[0]]);
    $below = $services->filter(fn ($s) => $sla[$s->id]['availability'] !== null && $s->components->isNotEmpty() && $sla[$s->id]['availability'] < $s->target())->count();
@endphp
@section('content')
<div class="page-head">
    <div><h1>SLA layanan, {{ $start->translatedFormat('F Y') }}</h1>
        <p>Ketersediaan per layanan yang dipakai pengguna (mis. CCTV Pit A, internet kantor site), dihitung dari perangkat penyusunnya. Perangkat cadangan diperhitungkan; gangguan selama maintenance tidak dihitung. @if($partial)<b>Bulan berjalan, angka sementara.</b>@endif</p></div>
    <div class="actions">
        <a class="btn" href="{{ route('services.csv', request()->only('bulan')) }}"><x-icon name="download"/>CSV (Excel)</a>
        <button class="btn" type="button" data-print>Cetak / PDF</button>
        @can('manage-devices')
        <details class="ack"><summary class="btn primary"><x-icon name="plus"/>Tambah layanan</summary>
            <form method="post" action="{{ route('services.store') }}" class="ack-pop stack" style="width:340px">@csrf
                @include('services.fields', ['s' => null])
                <button class="btn primary sm">Buat layanan</button>
            </form></details>
        @endcan
    </div>
</div>
<section class="card" style="margin-bottom:16px">
    <form class="filters" method="get">
        <select class="input" name="bulan" aria-label="Bulan" data-autosubmit>
            @foreach($months as $m)<option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $start->format('Y-m'))>{{ $m->translatedFormat('F Y') }}</option>@endforeach
        </select>
        <select class="input" name="site" aria-label="Site" data-autosubmit><option value="">Semua site</option>
            @foreach($sites as $st)<option value="{{ $st->id }}" @selected(request('site') == $st->id)>{{ $st->code }}</option>@endforeach</select>
    </form>
</section>

@if($services->isEmpty())
    <div class="card"><div class="empty"><x-icon name="layers"/>Belum ada layanan.
        <div class="muted" style="margin-top:8px;max-width:560px;margin-inline:auto">Contoh: layanan <b>CCTV Pit A</b> = komponen <i>Jalur</i> (Core, Radio PTP, semua wajib hidup) + komponen <i>Rekam</i> (NVR). Layanan <b>Internet kantor</b> = komponen <i>Uplink</i> (ISP 1, ISP 2, cukup 1 hidup).</div>
    </div></div>
@else
<div class="kpis">
    <div class="kpi {{ $live->filter(fn ($v) => $v === 'down')->count() ? 'crit' : 'ok' }}"><div class="lbl">Terganggu sekarang</div><div class="val">{{ $live->filter(fn ($v) => $v === 'down')->count() }}</div><div class="note">dari {{ $services->count() }} layanan</div></div>
    <div class="kpi {{ $live->filter(fn ($v) => $v === 'degraded')->count() ? 'warn' : '' }}"><div class="lbl">Tanpa cadangan</div><div class="val">{{ $live->filter(fn ($v) => $v === 'degraded')->count() }}</div><div class="note">jalan, tapi perangkat cadangan down</div></div>
    <div class="kpi {{ $below ? 'warn' : 'ok' }}"><div class="lbl">Di bawah target</div><div class="val">{{ $below }}</div><div class="note">bulan ini</div></div>
</div>
<section class="card"><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Layanan</th><th>Sekarang</th><th class="num">Ketersediaan</th><th class="num">Target</th><th class="num">Downtime</th><th class="num">Gangguan</th><th class="num">Terlama</th><th class="num" title="Waktu layanan jalan tetapi ada perangkat (mis. cadangan) yang down">Tanpa cadangan</th></tr></thead>
    <tbody>
    @foreach($services->sortBy(fn ($s) => [['down' => 0, 'degraded' => 1, 'up' => 2, 'empty' => 3][$live[$s->id]], $sla[$s->id]['availability'] ?? 101]) as $s)
        @php($r = $sla[$s->id]) @php([$lbl, $lt] = $States[$live[$s->id]])
        <tr class="{{ $live[$s->id] === 'down' ? 'is-down' : '' }}">
            <td class="name"><a href="{{ route('services.show', ['service' => $s] + request()->only('bulan')) }}">{{ $s->name }}</a><small>{{ $s->site?->code ?? 'lintas site' }}, {{ $s->components->count() }} komponen, {{ $s->components->flatMap->devices->unique('id')->count() }} perangkat</small></td>
            <td><span class="badge {{ $lt }}">{{ $lbl }}</span></td>
            @if($s->components->isEmpty())<td class="num muted" colspan="6">tambahkan komponen</td>@else
            <td class="num"><span class="badge plain {{ $tone($r['availability'], $s->target()) }}">{{ $fmtPct($r['availability']) }}</span></td>
            <td class="num muted">{{ $fmtPct($s->target()) }}</td>
            <td class="num">{{ \App\Http\Controllers\ServiceController::dur($r['down']) }}</td>
            <td class="num">{{ $r['count'] }}</td>
            <td class="num">{{ \App\Http\Controllers\ServiceController::dur($r['longest']) }}</td>
            <td class="num">{{ \App\Http\Controllers\ServiceController::dur($r['degraded']) }}</td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table></div></div></section>
@endif
@endsection
