@extends('layouts.app')
@section('title', 'Link radio')
@section('content')
<div class="page-head"><div><h1>Link radio</h1><p>Station yang tersambung ke AP Ubiquiti airMAX dan LTU, serta pasangan link airFiber, dibaca lewat SNMP tiap putaran. Lawan yang terdaftar di ANTAREJA otomatis jadi garis di Topologi. Sinyal di bawah {{ \App\Support\Wireless::SIGNAL_WARN }} dBm ditandai lemah.</p></div></div>
<div class="kpis">
    <a class="kpi" href="{{ route('wireless.index') }}"><div class="lbl">Link tersambung</div><div class="val">{{ $stats['total'] }}</div></a>
    <a class="kpi {{ $stats['weak'] ? 'warn' : '' }}" href="{{ route('wireless.index', ['kondisi' => 'lemah']) }}"><div class="lbl">Sinyal lemah</div><div class="val">{{ $stats['weak'] }}</div></a>
    <a class="kpi {{ $stats['lost'] ? 'crit' : '' }}" href="{{ route('wireless.index', ['kondisi' => 'putus']) }}"><div class="lbl">Terputus, 7 hari</div><div class="val">{{ $stats['lost'] }}</div></a>
    <a class="kpi" href="{{ route('wireless.index', ['kondisi' => 'belum']) }}"><div class="lbl">Lawan belum terdaftar</div><div class="val">{{ $stats['unknown'] }}</div><div class="note">tambahkan perangkatnya supaya masuk topologi</div></a>
</div>
<section class="card">
    <form class="filters" method="get">
        <input class="input" type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Cari radio, station, IP, atau MAC" aria-label="Cari" style="min-width:240px">
        <select class="input" name="kondisi" aria-label="Kondisi" data-autosubmit><option value="">Semua kondisi</option>
            @foreach(['lemah' => 'Sinyal lemah', 'putus' => 'Terputus', 'belum' => 'Lawan belum terdaftar'] as $k => $v)<option value="{{ $k }}" @selected(($f['kondisi'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
        <button class="btn">Saring</button>
    </form>
    <div class="bd flush">
    @if($links->isEmpty())
        <div class="empty">{{ ($f['q'] ?? null) || ($f['kondisi'] ?? null) ? 'Tidak ada link yang cocok.' : 'Belum ada link radio terbaca. Pastikan SNMP aktif di AP atau radio Ubiquiti (airOS: Services, SNMP Agent) dan perangkatnya terdaftar dengan SNMP. Data muncul setelah satu putaran SNMP.' }}</div>
    @else
        @include('wireless._table', ['links' => $links, 'showReporter' => true])
    @endif
    </div>
</section>
@endsection
