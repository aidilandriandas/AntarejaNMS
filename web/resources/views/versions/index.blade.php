@extends('layouts.app')
@section('title', 'Versi & jam')
@php($skew = fn ($v) => \App\Http\Controllers\VersionController::skew($v))
@section('content')
<div class="page-head">
    <div><h1>Versi &amp; jam perangkat</h1><p>Versi perangkat lunak per model (dibaca lewat SNMP tiap 24 jam) dan selisih jam perangkat terhadap server. Jam yang meleset membuat log, sertifikat, dan urutan kejadian tidak bisa dipercaya.</p></div>
</div>
<section class="card">
    <form class="filters" method="get">
        <select class="input" name="site" aria-label="Site" data-autosubmit><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach</select>
    </form>
    <div class="kpis" style="margin-bottom:0">
        <div class="kpi"><span class="lbl">Model</span><span class="val">{{ $groups->count() }}</span><span class="note">{{ $total - $unknown }} dari {{ $total }} perangkat SNMP terbaca versinya</span></div>
        <div class="kpi"><span class="lbl">Versi berbeda</span><span class="val">{{ $groups->sum(fn ($g) => $g['odd']->count()) }}</span><span class="note">perangkat tidak memakai versi terbanyak di modelnya</span></div>
        <div class="kpi"><span class="lbl">Jam meleset</span><span class="val {{ $skewed->isNotEmpty() ? 'txt-crit' : '' }}">{{ $skewed->count() }}</span><span class="note">lebih dari 1 menit; {{ $noClock }} perangkat tidak memberi jam</span></div>
    </div>
</section>

<section class="card"><div class="hd"><h2>Jam meleset</h2><span class="sub">peringatan 1 menit, kritis 5 menit</span></div>
@if($skewed->isEmpty())
    <div class="bd"><div class="empty">Semua perangkat yang memberi jam selisihnya di bawah 1 menit.</div></div>
@else
    <div class="bd flush"><table class="t"><thead><tr><th>Perangkat</th><th>Site</th><th>Selisih</th><th>Catatan</th></tr></thead><tbody>
    @foreach($skewed as $d)@php($c = $clocks[$d->id])
        <tr><td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a><small class="mono">{{ $d->host }}</small></td>
            <td>{{ $d->site?->code }}</td>
            <td class="nowrap"><span class="badge {{ $c->status }} plain">{{ $skew($c->value) }}</span></td>
            <td class="muted">{{ str_contains($c->label, 'tanpa zona') ? 'Perangkat tidak mengirim zona waktu; selisih jam penuh diabaikan.' : '' }} Periksa setelan NTP perangkat.</td></tr>
    @endforeach
    </tbody></table></div>
@endif
</section>

<section class="card"><div class="hd"><h2>Versi per model</h2><span class="sub">model dengan versi campuran di atas</span></div>
@if($groups->isEmpty())
    <div class="bd"><div class="empty">Belum ada versi terbaca. Versi dibaca pada putaran SNMP pertama setelah collector jalan, lalu tiap 24 jam.</div></div>
@else
    <div class="bd flush"><table class="t"><thead><tr><th>Model</th><th>Versi</th><th>Beda dari versi terbanyak</th></tr></thead><tbody>
    @foreach($groups as $g)
        <tr><td class="name">{{ $g['model'] }}<small>{{ $g['devices']->count() }} perangkat</small></td>
            <td>@foreach($g['counts'] as $v => $n)<span class="badge {{ $v === $g['main'] ? 'ok' : 'warn' }} plain mono">{{ $v }}</span> <span class="muted">{{ $n }}</span>@if(! $loop->last)<br>@endif @endforeach</td>
            <td>@forelse($g['odd'] as $d)<a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a> <span class="muted mono">{{ $d->os_version }}</span>@if(! $loop->last)<br>@endif @empty<span class="muted">seragam</span>@endforelse</td></tr>
    @endforeach
    </tbody></table></div>
@endif
</section>
@endsection
