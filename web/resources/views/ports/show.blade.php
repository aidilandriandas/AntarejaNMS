@extends('layouts.app')
@section('title', $port->label() . ', ' . $device->name)
@php($R = \App\Http\Controllers\PortController::RANGES)
@section('content')
<div class="page-head">
    <div><div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / <a href="{{ route('devices.show', $device) }}">{{ $device->name }}</a> / Port</div>
        <h1>{{ $port->label() }} @if(! $port->admin_up)<span class="badge" style="vertical-align:middle">dimatikan</span>@elseif($port->oper_up)<span class="badge ok" style="vertical-align:middle">up</span>@else<span class="badge crit" style="vertical-align:middle">down</span>@endif
            @if($monitored)<span class="badge info plain" style="vertical-align:middle">dipantau</span>@endif</h1>
        <div class="meta" style="margin-top:6px">
            @if($port->alias || $port->descr !== $port->name)<span style="color:var(--color-ink-2)">{{ $port->alias ?: $port->descr }}</span>@endif
            <span>{{ $port->speed_bps ? \App\Support\Fmt::bps((float) $port->speed_bps) : 'Kecepatan tak diketahui' }}</span>
            @if($port->mac)<span class="mono">{{ $port->mac }}</span>@endif
            <span>ifIndex {{ $port->if_index }}</span>
            @if($port->last_change_at)<span>Status berubah {{ $port->last_change_at->diffForHumans() }}</span>@endif
        </div></div>
    <div class="actions">
        <select class="input" aria-label="Pindah port" data-port-jump>
            @foreach($ports as $p)<option value="{{ route('ports.show', [$device, $p->if_index]) }}" @selected($p->if_index === $port->if_index)>{{ $p->name ?: ($p->descr ?: 'if' . $p->if_index) }}{{ $p->alias ? ' (' . \Illuminate\Support\Str::limit($p->alias, 30) . ')' : '' }}</option>@endforeach
        </select>
        @if($device->snmp_enabled)<button type="button" class="btn" data-live-port="{{ $port->if_index }}"><span class="live-dot on" style="display:inline-block;margin-right:6px"></span>Live</button>@endif
        @can('manage-devices')
        <form method="post" action="{{ route('devices.monitor', [$device, $port->if_index]) }}">@csrf<button class="btn {{ $monitored ? 'primary' : '' }}">{{ $monitored ? 'Dipantau' : 'Pantau port' }}</button></form>
        @endcan
    </div>
</div>

{{-- data port untuk modul trafik live --}}
<div hidden data-port="{{ $port->if_index }}" data-port-name="{{ $port->label() }}" data-speed="{{ (int) $port->speed_bps }}" data-up="{{ $port->oper_up ? 1 : 0 }}"></div>
@if($device->snmp_enabled)
<section class="card live-card" data-live-card data-url="{{ route('devices.live', $device) }}" hidden style="margin-bottom:16px">
    <div class="hd"><h2><span class="live-dot" data-live-dot></span><span data-live-title>Trafik live</span></h2>
        <div class="live-ctl">
            <div class="seg" role="group" aria-label="Interval">@foreach([2 => '2 dtk', 5 => '5 dtk', 10 => '10 dtk'] as $k => $v)<button type="button" data-live-int="{{ $k }}" class="{{ $k === 2 ? 'on' : '' }}">{{ $v }}</button>@endforeach</div>
            <button type="button" class="btn sm" data-live-pause>Jeda</button>
            <button type="button" class="btn sm" data-live-close aria-label="Tutup trafik live">✕</button>
        </div></div>
    <div class="bd">
        <div class="live-stats">
            <div><span class="lbl in">Masuk</span><b data-live-v="in"><span class="nil">tidak ada</span></b><small>rata-rata <span data-live-v="in-avg"><span class="nil">tidak ada</span></span>, puncak <span data-live-v="in-max"><span class="nil">tidak ada</span></span></small></div>
            <div><span class="lbl out">Keluar</span><b data-live-v="out"><span class="nil">tidak ada</span></b><small>rata-rata <span data-live-v="out-avg"><span class="nil">tidak ada</span></span>, puncak <span data-live-v="out-max"><span class="nil">tidak ada</span></span></small></div>
            <div><span class="lbl">Pemakaian</span><b data-live-v="util"><span class="nil">tidak ada</span></b><small data-live-v="info"><span class="nil">tidak ada</span></small></div>
        </div>
        <div class="chart" data-live-chart></div>
        <p class="muted live-note" data-live-note></p>
    </div>
</section>
@endif

<div data-port-graphs data-url="{{ route('ports.data', [$device, $port->if_index]) }}">
<section class="card" style="margin-bottom:16px">
    <div class="hd"><h2>Statistik <span class="sub" data-pg-range-label>24 jam</span></h2>
        <div class="seg" role="group" aria-label="Rentang waktu">@foreach($R as $k => [, $lbl])<button type="button" data-pg-range="{{ $k }}" class="{{ $k === '24h' ? 'on' : '' }}">{{ $lbl }}</button>@endforeach</div></div>
    <div class="bd flush"><div class="table-wrap"><table class="t pg-stats">
        <thead><tr><th></th><th class="num">Sekarang</th><th class="num">Rata-rata</th><th class="num">Maks (5 mnt)</th><th class="num" title="95% waktu trafik berada di bawah angka ini, dasar tagihan bandwidth">Persentil 95</th><th class="num">Total volume</th><th class="num">Error</th><th class="num">Discard</th></tr></thead>
        <tbody>
            @foreach(['in' => 'Masuk', 'out' => 'Keluar'] as $k => $lbl)
            <tr><td class="name"><span class="pg-sw {{ $k }}"></span>{{ $lbl }}</td>
                @foreach(['now', 'avg', 'max', 'p95', 'bytes', 'errors', 'discards'] as $c)<td class="num" data-pg-stat="{{ $k }}.{{ $c }}"><span class="nil">tidak ada</span></td>@endforeach</tr>
            @endforeach
        </tbody>
    </table></div></div>
</section>

<div class="pg-grid">
    <section class="card"><div class="hd"><h2>Trafik</h2><span class="sub">bit/s, masuk ke atas, keluar ke bawah, garis putus = persentil 95</span></div><div class="bd"><div class="chart" data-pg-chart="bits"></div></div></section>
    <section class="card"><div class="hd"><h2>Pemakaian</h2><span class="sub">% dari kecepatan port</span></div><div class="bd"><div class="chart" data-pg-chart="util"></div></div></section>
    <section class="card"><div class="hd"><h2>Paket</h2><span class="sub">paket/s, unicast & non-unicast (multicast + broadcast)</span></div><div class="bd"><div class="chart" data-pg-chart="pkts"></div></div></section>
    <section class="card"><div class="hd"><h2>Error & discard</h2><span class="sub">per detik</span></div><div class="bd"><div class="chart" data-pg-chart="errs"></div></div></section>
</div>
</div>

<div class="grid g-2" style="margin-top:16px">
    <section class="card"><div class="hd"><h2>Terhubung ke</h2></div><div class="bd flush">
        @if($neighbors->isEmpty() && $links->isEmpty())<div class="empty">Tidak ada tetangga LLDP/CDP atau sambungan manual di port ini.</div>@else
        <ul class="feed">
            @foreach($neighbors as $n)
            <li><span class="ic info"><x-icon name="share"/></span><div class="msg">@if($n->remote_device_id)<a href="{{ route('devices.show', $n->remote_device_id) }}">{{ $n->dev_name }}</a>@else{{ $n->remote_name ?: $n->remote_ip }}@endif
                {{ $n->remote_port ? ', ' . $n->remote_port : '' }}<small>{{ strtoupper($n->protocol) }}{{ $n->remote_ip ? ', ' . $n->remote_ip : '' }}{{ $n->remote_platform ? ', ' . \Illuminate\Support\Str::limit($n->remote_platform, 60) : '' }}{{ $n->remote_device_id ? '' : ', belum terdaftar' }}</small></div></li>
            @endforeach
            @foreach($links as $l)
            <li><span class="ic muted"><x-icon name="share"/></span><div class="msg"><a href="{{ route('devices.show', $l->id) }}">{{ $l->name }}</a><small>sambungan manual{{ $l->note ? ', ' . $l->note : '' }}</small></div></li>
            @endforeach
        </ul>@endif
    </div></section>
    <section class="card"><div class="hd"><h2>Riwayat putus-sambung</h2><span class="sub">7 hari, {{ ($changes24->t ?: $changes24->p) }} perubahan dalam 24 jam</span></div><div class="bd flush">
        @if($changes->isEmpty())<div class="empty">Tidak ada perubahan status dalam 7 hari terakhir.</div>@else
        <ul class="feed">@foreach($changes as $c)
            <li><span class="ic {{ $c->up ? 'ok' : 'crit' }}"><x-icon :name="$c->up ? 'arrow-up' : 'arrow-down'"/></span>
                <div class="msg"><b>{{ $c->up ? 'Tersambung' : 'Putus' }}</b><small>{{ $c->source === 'trap' ? 'dari trap perangkat' : 'terlihat saat polling' }}</small></div>
                <time>{{ \Illuminate\Support\Carbon::parse($c->at)->format('d/m H:i:s') }}</time></li>
        @endforeach</ul>
        @endif
    </div></section>
    <section class="card"><div class="hd"><h2>Alamat IP</h2></div><div class="bd flush">
        @if($ips->isEmpty())<div class="empty">Tidak ada alamat IP tercatat di port ini.</div>@else
        <ul class="feed">@foreach($ips as $ip)<li><span class="ic muted"><x-icon name="grid"/></span><div class="msg mono">{{ $ip->ip }}{{ $ip->prefix !== null ? '/' . $ip->prefix : '' }}</div></li>@endforeach</ul>@endif
    </div></section>
</div>
@endsection
