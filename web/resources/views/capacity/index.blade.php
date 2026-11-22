@extends('layouts.app')
@section('title', 'Kapasitas')
@php
    $C = \App\Services\Capacity::class;
    $S = $C::STATES;
    $cnt = fn ($st) => collect($rows)->whereIn('state', (array) $st)->count();
    $pct = fn ($v) => $v === null ? 'tidak ada' : number_format($v, $v < 10 ? 1 : 0, ',', '.') . '%';
@endphp
@section('content')
<div class="page-head">
    <div><h1>Kapasitas port</h1>
        <p>Puncak harian (persentil 95, arah masuk/keluar yang lebih tinggi) port yang <b>dipantau</b>, tren {{ $days }} hari terakhir, dan perkiraan kapan melewati batas <b>{{ rtrim(rtrim(number_format($threshold, 1, ',', ''), '0'), ',') }}%</b> kecepatan port. Perkiraan hanya garis lurus dari data lalu, gunakan sebagai peringatan dini, bukan kepastian.</p></div>
    <div class="actions">
        <div class="seg">@foreach($C::WINDOWS as $d => $lbl)<a class="btn sm {{ $days === $d ? 'primary' : '' }}" href="{{ route('capacity.index', ['hari' => $d]) }}">{{ $lbl }}</a>@endforeach</div>
        <a class="btn" href="{{ route('capacity.csv', ['hari' => $days]) }}"><x-icon name="download"/>CSV</a>
        @can('admin')
        <details class="ack"><summary class="btn">Batas kapasitas</summary>
            <form method="post" action="{{ route('capacity.threshold') }}" class="ack-pop">@csrf @method('PUT')
                <label for="threshold" style="font-weight:500">Port dianggap penuh pada (%) kecepatan</label>
                <input class="input" type="number" step="1" min="10" max="100" id="threshold" name="threshold" value="{{ $threshold }}">
                <button class="btn primary sm">Simpan</button></form></details>
        @endcan
    </div>
</div>

@if(! $rows)
    <div class="card"><div class="empty"><x-icon name="chart"/>Belum ada port yang dipantau.
        <div class="muted" style="margin-top:8px;max-width:560px;margin-inline:auto">Buka halaman perangkat → tabel port → tandai port penting (uplink, link PTP, ke ISP) sebagai <b>pantau</b>. Port yang dipantau juga memunculkan problem bila putus atau penuh. Perkiraan muncul setelah {{ $C::MIN_DAYS }} hari data.</div>
    </div></div>
@else
<div class="kpis">
    <div class="kpi {{ $cnt(['over', 'soon']) ? 'crit' : 'ok' }}"><div class="lbl">Penuh ≤ 30 hari</div><div class="val">{{ $cnt(['over', 'soon']) }}</div><div class="note">termasuk yang sudah melewati batas</div></div>
    <div class="kpi {{ $cnt('watch') ? 'warn' : '' }}"><div class="lbl">Penuh ≤ 6 bulan</div><div class="val">{{ $cnt('watch') }}</div><div class="note">rencanakan upgrade</div></div>
    <div class="kpi ok"><div class="lbl">Aman</div><div class="val">{{ $cnt('ok') }}</div><div class="note">tren datar/turun atau &gt; 6 bulan</div></div>
    <div class="kpi"><div class="lbl">Port dipantau</div><div class="val">{{ count($rows) }}</div><div class="note">{{ $cnt(['short', 'nodata', 'nospeed']) }} belum bisa diperkirakan</div></div>
</div>
<section class="card"><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Port</th><th class="num">Kecepatan</th><th class="num">Puncak 7 hari</th><th class="num">Pemakaian</th><th>{{ $days }} hari</th><th class="num">Tren / 30 hari</th><th>Perkiraan penuh</th></tr></thead>
    <tbody>
    @foreach($rows as $r)
        @php([$lbl, $tone] = $S[$r['state']])
        <tr class="{{ $tone === 'crit' ? 'is-down' : '' }}">
            <td class="name"><a href="{{ route('devices.show', $r['device_id']) }}">{{ $r['device'] }}</a>, {{ $r['port'] }}<small>{{ $r['alias'] ?: '' }}{{ $r['alias'] && $r['site'] ? ', ' : '' }}{{ $r['site'] }}</small></td>
            <td class="num nowrap">{{ $r['speed'] > 0 ? $C::bps($r['speed']) : 'tidak ada' }}</td>
            <td class="num nowrap">{{ $C::bps($r['current']) }}</td>
            <td class="num"><div class="ubar"><span class="{{ $r['util'] !== null && $r['util'] >= $threshold ? 'crit' : ($r['util'] !== null && $r['util'] >= $threshold * 0.75 ? 'warn' : '') }}" style="width:{{ min(100, (int) round($r['util'] ?? 0)) }}%"></span></div>{{ $pct($r['util']) }}</td>
            <td>@include('capacity.spark', ['r' => $r])</td>
            <td class="num nowrap" title="{{ $r['r2'] !== null ? 'Kecocokan garis tren (R²): ' . number_format($r['r2'], 2, ',', '') : '' }}">{{ $r['slope'] !== null ? ($r['slope'] > 0 ? '+' : '') . $C::bps($r['slope'] * 30) : 'tidak ada' }}@if($r['r2'] !== null && $r['r2'] < 0.3 && $r['slope'] > 0)<div class="muted" style="font-size:11.5px">tren lemah</div>@endif</td>
            <td class="nowrap"><span class="badge plain {{ $tone }}">{{ $lbl }}</span>
                @if($r['full_at'] && $r['state'] !== 'ok')<div class="muted" style="font-size:12px">± {{ \Illuminate\Support\Carbon::createFromTimestamp($r['full_at'], config('app.timezone'))->translatedFormat('j M Y') }} ({{ (int) ceil($r['days_left']) }} hari)</div>
                @elseif($r['state'] === 'short')<div class="muted" style="font-size:12px">{{ $r['days'] }} / {{ $C::MIN_DAYS }} hari</div>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div></div></section>
<p class="muted" style="font-size:12.5px;margin-top:10px">Grafik: garis biru = puncak harian, garis putus-putus = tren, garis merah = batas kapasitas. Data diperbarui tiap 10 menit.</p>
@endif
@endsection
