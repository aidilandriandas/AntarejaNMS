@extends('layouts.app')
@section('title', 'Dampak')
@section('content')
<div class="page-head">
    <div><h1>Dampak</h1><p>Apa saja yang ikut terganggu oleh perangkat yang sedang down: perangkat di bawahnya, kamera, layanan, dan site. Dihitung dari isian perangkat induk, kamera, dan komponen layanan.</p></div>
</div>

<div class="kpis">
    <div class="kpi {{ $now->isNotEmpty() ? 'crit' : '' }}"><span class="lbl">Sumber gangguan</span><span class="val">{{ $now->count() }}</span><span class="note">perangkat down yang induknya tidak down</span></div>
    <div class="kpi"><span class="lbl">Perangkat ikut putus</span><span class="val">{{ count($all['devices']) }}</span></div>
    <div class="kpi"><span class="lbl">Kamera</span><span class="val">{{ $all['cameras']->count() }}</span></div>
    @php($svcDown = count(array_filter($all['services'], fn ($s) => $s['after'] === 'down')))
    <div class="kpi {{ $svcDown ? 'crit' : '' }}"><span class="lbl">Layanan terganggu</span><span class="val">{{ $svcDown }}</span></div>
    @php($sitesAll = count(array_filter($all['sites'], fn ($s) => $s['all'])))
    <div class="kpi {{ $sitesAll ? 'crit' : '' }}"><span class="lbl">Site putus total</span><span class="val">{{ $sitesAll }}</span></div>
</div>

<div class="grid g-main">
<div class="stack">
    @php($quiet = $now->filter(fn ($r) => \App\Services\Impact::line($r) === ''))
    @forelse($now->reject(fn ($r) => \App\Services\Impact::line($r) === '') as $r)
        @php($root = $r['roots'][0])
        <section class="card"><div class="hd"><h2><a href="{{ route('devices.show', $root->id) }}">{{ $root->name }}</a> <span class="badge plain crit">down</span></h2><span class="sub">{{ $root->site_code ?? 'tanpa site' }}</span></div>
            <div class="bd">
                <p class="impact-sum"><b>Dampak:</b> {{ \App\Services\Impact::line($r) }}</p>
                @include('impact.detail', ['r' => $r])
            </div></section>
    @empty
        @if($quiet->isEmpty())
        <section class="card"><div class="bd"><div class="empty"><x-icon name="check"/>Tidak ada perangkat down. Pakai simulasi di samping untuk melihat dampak bila suatu perangkat mati, mis. sebelum maintenance.</div></div></section>
        @endif
    @endforelse
    @if($quiet->isNotEmpty())
    <section class="card"><div class="hd"><h2>Down tanpa dampak lain</h2><span class="sub">{{ $quiet->count() }} perangkat</span></div><div class="bd">
        <p style="margin-top:0">@foreach($quiet as $r)<a href="{{ route('devices.show', $r['roots'][0]->id) }}">{{ $r['roots'][0]->name }}</a>{{ $r['roots'][0]->site_code ? ' (' . $r['roots'][0]->site_code . ')' : '' }}@if(! $loop->last), @endif @endforeach</p>
        <p class="hint muted" style="margin:0">Tidak ada perangkat yang tercatat di bawahnya, kamera, atau layanan. Isi <i>perangkat induk</i> di perangkat yang terhubung lewatnya agar dampaknya terhitung.</p>
    </div></section>
    @endif
</div>

<div class="stack">
<section class="card" id="simulasi"><div class="hd"><h2>Simulasi</h2></div><div class="bd">
    <form method="get" action="{{ route('impact.index') }}#simulasi" class="form">
        <div class="field"><label for="sim">Bila perangkat ini mati</label>
            <select class="input" id="sim" name="sim[]" multiple size="8">
                @foreach($candidates as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, $simIds))>{{ $d->name }}{{ $d->site_code ? ' (' . $d->site_code . ')' : '' }}</option>@endforeach
            </select>
            <div class="hint">Hanya perangkat yang punya perangkat di bawahnya, kamera, atau layanan. Tahan Ctrl untuk memilih lebih dari satu (mis. dua uplink sekaligus).</div></div>
        <div class="form-foot"><button class="btn primary">Hitung dampak</button></div>
    </form>
    @if($sim)
        <div style="margin-top:var(--space-md)">
            <p class="impact-sum"><b>{{ collect($sim['roots'])->pluck('name')->implode(', ') }}</b> mati: {{ \App\Services\Impact::line($sim) ?: 'tidak ada dampak lain' }}.</p>
            @include('impact.detail', ['r' => $sim])
        </div>
    @endif
</div></section>
</div>
</div>
@endsection
