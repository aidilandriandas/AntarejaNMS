@extends('layouts.app')
@section('title', 'SLA ' . $link->name)
@php($S = \App\Services\IspSla::class)
@php($V = \App\Models\IspLink::VERDICTS)
@php($q = request()->only('periode', 'dari', 'sampai'))
@php($over = fn ($v, $t) => $v !== null && $v > $t)
@section('content')
<div class="crumbs"><a href="{{ route('isp.index', $q) }}">SLA link ISP</a> / {{ $link->name }}</div>
<div class="page-head">
    <div><h1>{{ $link->name }}</h1>
        <p>{{ $link->provider ? $link->provider . '. ' : '' }}@if($link->if_index !== null)Port <a href="{{ route('ports.show', [$link->device_id, $link->if_index]) }}">{{ $link->sourceLabel() }}</a>.
            Gangguan = port down{{ $link->pingDevice ? ', atau ping ke ' . $link->pingDevice->name . ' gagal / packet loss ' . $S::num($link->loss_down_pct, 0) . '% ke atas' : '' }}.
            @if(! $link->pingDevice)Latensi, jitter, dan loss kosong karena belum ada ping ke ujung ISP (atur di Atur link).@endif
            @else Diukur dari ping ke <a href="{{ route('devices.show', $link->device_id) }}">{{ $link->device?->name }}</a> ({{ $link->device?->host }}). Gangguan = ping gagal atau packet loss {{ $S::num($link->loss_down_pct, 0) }}% ke atas. Pilih port ke ISP di Atur link supaya status port dan trafik ikut dihitung.@endif
            {{ $link->exclude_maintenance ? 'Waktu maintenance tidak dihitung.' : '' }}</p></div>
    <div class="actions">
        <a class="btn" href="{{ route('isp.csv', [$link] + $q) }}"><x-icon name="download"/>CSV (Excel)</a>
        <button class="btn" type="button" data-print>Cetak / PDF</button>
    </div>
</div>
<section class="card" style="margin-bottom:16px">@include('isp._period')</section>

<div class="kpis">
    <div class="kpi {{ $r['verdict'] ? $V[$r['verdict']][1] : '' }}"><div class="lbl">Ketersediaan</div>
        <div class="val">{{ $r['availability'] === null ? 'tanpa data' : $S::num($r['availability'], 2) . '%' }}</div>
        <div class="note">{{ $r['verdict'] ? $V[$r['verdict']][0] : 'belum ada sampel ping' }}, target {{ $S::num($link->target_pct) }}%</div></div>
    <div class="kpi"><div class="lbl">Waktu normal</div><div class="val" style="font-size:22px;padding-top:6px">{{ $S::dur($r['uptime_s']) }}</div><div class="note">gangguan {{ $r['downtime_s'] ? $S::dur($r['downtime_s']) : '0' }}, {{ count($r['incidents']) }} insiden</div></div>
    <div class="kpi {{ $over($r['latency'], $link->latency_target_ms) ? 'warn' : '' }}"><div class="lbl">Latensi rata-rata</div><div class="val">{{ $r['latency'] !== null ? $S::num($r['latency']) . ' ms' : '' }}</div><div class="note">target di bawah {{ $S::num($link->latency_target_ms, 0) }} ms</div></div>
    <div class="kpi {{ $over($r['jitter'], $link->jitter_target_ms) ? 'warn' : '' }}"><div class="lbl">Jitter rata-rata</div><div class="val">{{ $r['jitter'] !== null ? $S::num($r['jitter']) . ' ms' : '' }}</div><div class="note">target di bawah {{ $S::num($link->jitter_target_ms, 1) }} ms</div></div>
    <div class="kpi {{ $over($r['loss'], $link->loss_target_pct) ? 'warn' : '' }}"><div class="lbl">Packet loss rata-rata</div><div class="val">{{ $r['loss'] !== null ? $S::num($r['loss']) . '%' : '' }}</div><div class="note">target di bawah {{ $S::num($link->loss_target_pct, 1) }}%; {{ number_format($r['samples'], 0, ',', '.') }} sampel ping</div></div>
</div>

@if($t = $r['traffic'])
<div class="kpis">
    @foreach(['in' => 'Trafik masuk (unduh)', 'out' => 'Trafik keluar (unggah)'] as $k => $lbl)
    @php($x = $t[$k])
    <div class="kpi {{ ($x['util_p95'] ?? 0) >= 90 ? 'crit' : (($x['util_p95'] ?? 0) >= 70 ? 'warn' : '') }}"><div class="lbl">{{ $lbl }}, persentil 95</div>
        <div class="val">{{ $x ? $S::num($x['p95'], 1) : '' }}<small> Mbps</small></div>
        <div class="note">@if($x)rata-rata {{ $S::num($x['avg'], 1) }}, puncak {{ $S::num($x['max'], 1) }} Mbps{{ $x['util_p95'] !== null ? ', ' . $S::num($x['util_p95'], 0) . '% dari ' . $S::num($t['capacity_mbps'], 0) . ' Mbps ' . $t['capacity_from'] : '' }}@else belum ada data trafik port @endif</div></div>
    @endforeach
</div>
@endif
<div class="isp-charts" data-isp-report="{{ json_encode(['days' => $r['days'], 'detail' => $r['detail'], 'target' => $link->target_pct, 'warn' => $link->warn_pct,
    'latency' => $link->latency_target_ms, 'jitter' => $link->jitter_target_ms, 'loss' => $link->loss_target_pct, 'lossDown' => $link->loss_down_pct, 'capacity' => $r['traffic']['capacity_mbps'] ?? null, 'tz' => config('app.timezone')]) }}">
<section class="card"><div class="hd"><h2>Tren harian</h2><span class="sub">rata-rata per hari</span></div><div class="bd">
    <div class="grid g-2 isp-grid">
        <figure class="isp-fig"><figcaption>Ketersediaan (%)</figcaption><div data-isp-plot="sla"></div></figure>
        <figure class="isp-fig"><figcaption>Latensi (ms)</figcaption><div data-isp-plot="d-latency"></div></figure>
        <figure class="isp-fig"><figcaption>Jitter (ms)</figcaption><div data-isp-plot="d-jitter"></div></figure>
        <figure class="isp-fig"><figcaption>Packet loss (%)</figcaption><div data-isp-plot="d-loss"></div></figure>
    </div>
    <p class="hint muted" style="margin:var(--space-xs) 0 0">Garis putus-putus = target. Arahkan kursor ke grafik untuk angka tiap hari; angka lengkap ada di tabel di bawah.</p>
</div></section>
<section class="card"><div class="hd"><h2>Detail</h2><span class="sub">{{ $r['step'] >= 120 ? 'per ' . intdiv($r['step'], 60) . ' menit' : 'per menit' }}, pita merah = gangguan</span></div><div class="bd">
    @if($r['traffic'])<figure class="isp-fig"><figcaption>Trafik port (Mbps), masuk dan keluar</figcaption><div data-isp-plot="traffic"></div></figure>@endif
    <figure class="isp-fig"><figcaption>Packet loss (%)</figcaption><div data-isp-plot="loss"></div></figure>
    <div class="grid g-2 isp-grid">
        <figure class="isp-fig"><figcaption>Latensi (ms)</figcaption><div data-isp-plot="latency"></div></figure>
        <figure class="isp-fig"><figcaption>Jitter (ms)</figcaption><div data-isp-plot="jitter"></div></figure>
    </div>
</div></section>
</div>

<div class="grid g-main">
<section class="card"><div class="hd"><h2>Insiden</h2><span class="sub">{{ count($r['incidents']) }}</span></div><div class="bd flush">
    @if(! $r['incidents'])<div class="empty">Tidak ada gangguan dalam periode ini.</div>@else
    <div class="table-wrap"><table class="t"><thead><tr><th class="num">No</th><th>Mulai</th><th>Pulih</th><th class="num">Durasi</th><th>Severity</th><th>Penyebab</th></tr></thead><tbody>
    @foreach($r['incidents'] as $i => $x)
        <tr><td class="num">{{ $i + 1 }}</td><td class="nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($x['start'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
            <td class="nowrap">@if($x['end']){{ \Illuminate\Support\Carbon::createFromTimestamp($x['end'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}@else<span class="badge crit plain">berlangsung</span>@endif</td>
            <td class="num nowrap">{{ $S::dur($x['duration_s']) }}</td>
            <td><span class="badge {{ \App\Models\Problem::SEVERITIES[$x['severity']][1] }} plain">{{ \App\Models\Problem::SEVERITIES[$x['severity']][0] }}</span></td>
            <td>{{ $x['cause'] }}</td></tr>
    @endforeach
    </tbody></table></div>@endif
</div></section>

<div class="stack">
<section class="card"><div class="hd"><h2>Ambang SLA</h2></div><div class="bd">
    <ul class="impact-list">
        <li><span class="badge ok plain">Memenuhi SLA</span> {{ $S::num($link->target_pct) }}% ke atas</li>
        <li><span class="badge warn plain">Peringatan</span> {{ $S::num($link->warn_pct) }}% sampai di bawah {{ $S::num($link->target_pct) }}%</li>
        <li><span class="badge crit plain">Tidak memenuhi</span> di bawah {{ $S::num($link->warn_pct) }}%</li>
    </ul>
</div></section>
<section class="card"><div class="hd"><h2>Ringkasan periode</h2></div><div class="bd">
    <dl class="facts">
        <dt>Periode</dt><dd>{{ $label }}</dd>
        <dt>Diukur sampai</dt><dd>{{ \Illuminate\Support\Carbon::createFromTimestamp($r['end'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</dd>
        <dt>Normal</dt><dd>{{ $S::dur($r['uptime_s']) }}</dd>
        <dt>Gangguan</dt><dd>{{ $r['downtime_s'] ? $S::dur($r['downtime_s']) : '0' }}</dd>
        @if($r['maint_s'])<dt>Maintenance</dt><dd>{{ $S::dur($r['maint_s']) }} (tidak dihitung)</dd>@endif
        @if($r['nodata_s'])<dt>Tanpa data</dt><dd>{{ $S::dur($r['nodata_s']) }} (tidak dihitung; ANTAREJA tidak mengukur)</dd>@endif
    </dl>
</div></section>
</div>
</div>

<section class="card"><div class="hd"><h2>Per hari</h2></div><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Tanggal</th><th class="num">Ketersediaan</th><th class="num">Gangguan</th><th class="num">Latensi</th><th class="num">Jitter</th><th class="num">Loss</th></tr></thead><tbody>
    @foreach($r['days'] as $d)
        <tr><td>{{ \Illuminate\Support\Carbon::parse($d['date'])->translatedFormat('D, j M') }}</td>
            <td class="num">@if($d['availability'] !== null)<span class="{{ ['warning' => 'txt-warn', 'breach' => 'txt-crit'][$link->verdict($d['availability'])] ?? '' }}">{{ $S::num($d['availability']) }}%</span>@else<span class="muted">tanpa data</span>@endif</td>
            <td class="num">{{ $d['downtime_s'] ? $S::dur($d['downtime_s']) : '0' }}</td>
            <td class="num {{ $over($d['latency'], $link->latency_target_ms) ? 'txt-warn' : '' }}">{{ $d['latency'] !== null ? $S::num($d['latency']) . ' ms' : '' }}</td>
            <td class="num {{ $over($d['jitter'], $link->jitter_target_ms) ? 'txt-warn' : '' }}">{{ $d['jitter'] !== null ? $S::num($d['jitter']) . ' ms' : '' }}</td>
            <td class="num {{ $over($d['loss'], $link->loss_target_pct) ? 'txt-warn' : '' }}">{{ $d['loss'] !== null ? $S::num($d['loss']) . '%' : '' }}</td></tr>
    @endforeach
    </tbody></table></div></div></section>
@endsection
