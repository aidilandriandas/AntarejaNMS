@extends('layouts.app')
@section('title', 'Insiden bulanan')
@php
    $D = fn ($s) => \App\Http\Controllers\IncidentReportController::dur($s);
    $S = \App\Models\Problem::SEVERITIES;
    $rcaPct = $sum->done ? round($sum->rca / $sum->done * 100) : null;
    $maxN = max(1, $trend->max('n'));
@endphp
@section('content')
<div class="page-head">
    <div><h1>Insiden bulanan, {{ $start->translatedFormat('F Y') }}</h1>
        <p>Insiden yang dibuka pada bulan ini. MTTA = rata-rata dari dibuka sampai ada yang menangani; MTTR = rata-rata dari dibuka sampai semua problemnya pulih.@if($partial) <b>Bulan berjalan, angka sementara.</b>@endif</p></div>
    <div class="actions">
        <a class="btn" href="{{ route('reports.incidents.csv', request()->only('bulan', 'site')) }}"><x-icon name="download"/>CSV (Excel)</a>
        <button class="btn" type="button" data-print>Cetak / PDF</button>
    </div>
</div>
<form class="filters" method="get">
    <select class="input" name="bulan" aria-label="Bulan" data-autosubmit>
        @foreach($monthsSel as $m)<option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $start->format('Y-m'))>{{ $m->translatedFormat('F Y') }}</option>@endforeach
    </select>
    <select class="input" name="site" aria-label="Site" data-autosubmit><option value="">Semua site</option>
        @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach</select>
</form>

<div class="kpis">
    <div class="kpi {{ $sum->active ? 'crit' : '' }}"><div class="lbl">Insiden</div><div class="val">{{ $sum->n }}</div>
        <div class="note">{{ $sum->active ? $sum->active . ' masih berlangsung, ' : '' }}{{ $sum->auto }} dibuat otomatis</div></div>
    <div class="kpi"><div class="lbl">MTTA</div><div class="val">{{ $D($sum->mtta) }}</div><div class="note">sampai ditangani</div></div>
    <div class="kpi"><div class="lbl">MTTR</div><div class="val">{{ $D($sum->mttr) }}</div><div class="note">sampai pulih</div></div>
    <div class="kpi"><div class="lbl">Total lama gangguan</div><div class="val">{{ $sum->n ? $D($sum->total) : 'belum ada' }}</div><div class="note">semua insiden dijumlahkan</div></div>
    <div class="kpi {{ $rcaPct !== null && $rcaPct < 100 ? 'warn' : '' }}"><div class="lbl">Penyebab tercatat</div><div class="val">@if($rcaPct === null) belum ada @else {{ $rcaPct }}<small>%</small> @endif</div>
        <div class="note">{{ $sum->done ? $sum->rca . ' dari ' . $sum->done . ' yang sudah pulih' : 'belum ada yang pulih' }}</div></div>
</div>

@if(! $sum->n)
    <div class="empty"><x-icon name="check"/>Tidak ada insiden yang dibuka pada {{ $start->translatedFormat('F Y') }}.</div>
@else
<div class="grid g-3">
    <section class="card"><div class="hd"><h2>Per severity</h2></div><div class="bd flush"><table class="t"><tbody>
        @foreach($S as $k => [$lbl, $tone])@if($bySev[$k] ?? 0)<tr><td><span class="badge plain {{ $tone }}">{{ $lbl }}</span></td><td class="num">{{ $bySev[$k] }}</td></tr>@endif @endforeach
    </tbody></table></div></section>
    <section class="card"><div class="hd"><h2>Per site</h2></div><div class="bd flush"><table class="t">
        <thead><tr><th>Site</th><th class="num">Insiden</th><th class="num">MTTR</th><th class="num">Lama total</th></tr></thead><tbody>
        @foreach($bySite as $r)<tr><td class="name">{{ $r->site }}</td><td class="num">{{ $r->n }}</td><td class="num">{{ $D($r->mttr) }}</td><td class="num">{{ $D($r->total) }}</td></tr>@endforeach
    </tbody></table></div></section>
    <section class="card"><div class="hd"><h2>Per penanggung jawab</h2></div><div class="bd flush"><table class="t">
        <thead><tr><th>Nama</th><th class="num">Insiden</th><th class="num">MTTA</th><th class="num">MTTR</th></tr></thead><tbody>
        @forelse($byOwner as $r)<tr><td class="name">{{ $r->owner }}</td><td class="num">{{ $r->n }}</td><td class="num">{{ $D($r->mtta) }}</td><td class="num">{{ $D($r->mttr) }}</td></tr>
        @empty<tr><td colspan="4" class="muted">Belum ada insiden yang ditangani seseorang.</td></tr>@endforelse
        @if($unowned)<tr class="is-off"><td>Tanpa penanggung jawab</td><td class="num">{{ $unowned }}</td><td colspan="2"></td></tr>@endif
    </tbody></table></div></section>
</div>

<section class="card" style="margin-top:var(--space-xl)"><div class="hd"><h2>Daftar insiden</h2><span class="sub">terlama di atas</span></div><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Insiden</th><th>Severity</th><th>Status</th><th>Penanggung jawab</th><th class="num">Lama</th><th>Penyebab dan tindakan</th></tr></thead><tbody>
    @foreach($list as $i)
        <tr class="{{ in_array($i->status, ['open', 'handling']) ? 'is-down' : '' }}">
            <td class="name"><a href="{{ route('incidents.show', $i) }}">#{{ $i->id }} {{ $i->title }}</a><small>{{ $i->site?->code ?? 'Tanpa site' }}, dibuka {{ $i->opened_at->format('d/m H:i') }}, {{ $i->problems_count }} problem</small></td>
            <td><span class="badge plain {{ $i->sevTone() }}">{{ $i->sevLabel() }}</span></td>
            <td><span class="badge {{ $i->statusTone() }}">{{ $i->statusLabel() }}</span></td>
            <td>{{ $i->owner?->name ?? 'Belum ada' }}</td>
            <td class="num">{{ \App\Support\Fmt::duration($i->downtimeSeconds()) }}</td>
            <td style="max-width:360px">@if($i->cause)<div>{{ $i->cause }}</div>@endif @if($i->action_taken)<div class="muted">{{ $i->action_taken }}</div>@endif
                @if(! $i->cause && in_array($i->status, ['recovered', 'closed']))<span class="badge plain warn">belum diisi</span>@endif</td>
        </tr>
    @endforeach
    </tbody></table></div></div></section>
@endif

<section class="card" style="margin-top:var(--space-xl)"><div class="hd"><h2>12 bulan terakhir</h2></div><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Bulan</th><th>Jumlah insiden</th><th class="num">MTTR</th></tr></thead><tbody>
    @foreach($trend->reverse() as $t)
        <tr><td><a href="{{ route('reports.incidents', ['bulan' => $t['m']->format('Y-m')] + request()->only('site')) }}">{{ $t['m']->translatedFormat('M Y') }}</a></td>
            <td><span class="ubar" aria-hidden="true"><span class="{{ $t['n'] ? 'warn' : '' }}" style="width:{{ (int) round($t['n'] / $maxN * 100) }}%"></span></span>{{ $t['n'] }}</td>
            <td class="num">{{ $D($t['mttr']) }}</td></tr>
    @endforeach
    </tbody></table></div></div></section>
@endsection
