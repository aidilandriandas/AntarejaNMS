@extends('layouts.app')
@section('title', $device->name)
@section('content')
<div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a>@if($device->site) / <a href="{{ route('devices.index', ['site' => $device->site_id]) }}">{{ $device->site->code }}</a>@endif</div>
<div class="page-head">
    <div><h1>{{ $device->name }} <x-status :device="$device"/></h1>
        <p class="mono">{{ $device->host }} · {{ $device->categoryLabel() }}@if($device->status === 'down' && $device->status_reason) · <span style="color:var(--crit)">{{ $device->status_reason }}</span>@endif</p></div>
    @can('manage-devices')
        <div class="actions">
            <form method="post" action="{{ route('devices.poll', $device) }}">@csrf<button class="btn"><x-icon name="refresh"/>Periksa sekarang</button></form>
            <a class="btn" href="{{ route('devices.edit', $device) }}"><x-icon name="edit"/>Ubah</a>
        </div>
    @endcan
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">Latensi</div><div class="val">{{ $device->ping_enabled ? \App\Support\Fmt::ms($device->last_rtt_ms) : '–' }}</div>
        <div class="note">{{ $device->last_jitter_ms !== null ? 'jitter ' . \App\Support\Fmt::ms($device->last_jitter_ms) . ' · ' : '' }}{{ $device->last_ping_at ? 'ping ' . $device->last_ping_at->diffForHumans() : ($device->ping_enabled ? 'menunggu ping pertama' : 'ping dimatikan') }}</div></div>
    <div class="kpi {{ $device->last_loss_pct > 0 ? 'warn' : '' }}"><div class="lbl">Packet loss</div><div class="val">{{ $device->ping_enabled && $device->last_loss_pct !== null ? (int) $device->last_loss_pct : '–' }}<small>%</small></div><div class="note">3 paket per menit</div></div>
    <div class="kpi"><div class="lbl">Uptime</div><div class="val" style="font-size:22px;padding-top:6px">{{ $device->uptimeHuman() ?? '–' }}</div><div class="note">{{ $device->snmp_enabled ? 'dari SNMP' : 'butuh SNMP' }}</div></div>
    <div class="kpi"><div class="lbl">Status sejak</div><div class="val" style="font-size:22px;padding-top:6px">{{ $device->status_changed_at?->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) ?? '–' }}</div><div class="note">{{ $device->status_changed_at?->format('d/m/Y H:i') }}</div></div>
</div>

@if($problems->isNotEmpty())
<section class="card" style="margin-bottom:16px">
    <div class="hd"><h2>Problem aktif</h2><span class="sub">{{ $problems->count() }}</span></div>
    <div class="bd flush">@include('problems.table', ['problems' => $problems, 'compact' => true])</div>
</section>
@endif

<div class="grid g-main">
    <div class="stack">
        <section class="card" data-chart-card data-url="{{ route('devices.metrics', $device) }}">
            <div class="hd"><h2 data-chart-title>Latensi & packet loss</h2>
                <div class="seg" role="group" aria-label="Rentang waktu">
                    @foreach(['6h' => '6 jam', '24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'] as $k => $v)<button type="button" data-range="{{ $k }}" class="{{ $k === '24h' ? 'on' : '' }}">{{ $v }}</button>@endforeach
                </div></div>
            <div class="bd"><div class="chart" data-chart></div></div>
        </section>

        @if($device->snmp_enabled)
        <section class="card">
            <div class="hd"><h2>Port</h2><span class="sub">{{ $device->ports->count() }} port · klik baris untuk grafik trafik</span></div>
            <div class="bd flush">
                @if($device->ports->isEmpty())
                    <div class="empty">{{ $device->snmp_ok === false ? 'SNMP gagal: ' . $device->snmp_error : 'Data port muncul setelah pembacaan SNMP pertama.' }}</div>
                @else
                <div class="table-wrap"><table class="t">
                    <thead><tr><th>Port</th><th>Status</th><th class="num">Kecepatan</th><th class="num">Masuk</th><th class="num">Keluar</th><th>Pemakaian</th><th title="Problem bila port putus atau penuh">Pantau</th></tr></thead>
                    <tbody>
                    @foreach($device->ports as $p)
                        @php
                            $in = $traffic['in'][$p->if_index] ?? null; $out = $traffic['out'][$p->if_index] ?? null;
                            $util = $p->speed_bps > 0 && ($in !== null || $out !== null) ? min(100, max($in ?? 0, $out ?? 0) / $p->speed_bps * 100) : null;
                        @endphp
                        <tr class="port-row {{ $p->oper_up ? '' : 'is-off' }}" data-port="{{ $p->if_index }}" data-port-name="{{ $p->label() }}" tabindex="0">
                            <td class="name">{{ $p->label() }}<small>{{ $p->alias ?: ($p->descr !== $p->name ? $p->descr : '') }}</small></td>
                            <td>@if(! $p->admin_up)<span class="badge">dimatikan</span>@elseif($p->oper_up)<span class="badge ok">up</span>@else<span class="badge">down</span>@endif</td>
                            <td class="num">{{ $p->speed_bps ? \App\Support\Fmt::bps((float) $p->speed_bps) : '–' }}</td>
                            <td class="num">{{ \App\Support\Fmt::bps($in) }}</td>
                            <td class="num">{{ \App\Support\Fmt::bps($out) }}</td>
                            <td>@if($util !== null)<div class="bar {{ $util >= 90 ? 'crit' : ($util >= 70 ? 'warn' : '') }}" title="{{ number_format($util, 1, ',', '.') }}%"><span style="width:{{ max(1, $util) }}%"></span></div>@else<span class="muted">–</span>@endif</td>
                            <td>
                                @php($mon = in_array($p->if_index, $monitored))
                                @can('manage-devices')
                                    <form method="post" action="{{ route('devices.monitor', [$device, $p->if_index]) }}" data-stop>@csrf
                                        <button class="btn sm {{ $mon ? 'primary' : '' }}" title="{{ $mon ? 'Berhenti memantau' : 'Pantau port ini' }}" aria-pressed="{{ $mon ? 'true' : 'false' }}">{{ $mon ? 'Dipantau' : 'Pantau' }}</button></form>
                                @else
                                    {!! $mon ? '<span class="badge info plain">dipantau</span>' : '<span class="muted">–</span>' !!}
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @endif
            </div>
        </section>
        @endif
    </div>

    <div class="stack">
        @if($sensors->isNotEmpty())
        <section class="card"><div class="hd"><h2>Kesehatan & sensor</h2><span class="sub">klik untuk grafik</span></div><div class="bd flush">
            @foreach(\App\Models\DeviceSensor::GROUPS as $g => $gLabel)
                @continue(! isset($sensors[$g]))
                <div class="sensor-grp">{{ $gLabel }}</div>
                <table class="t"><tbody>
                @foreach($sensors[$g] as $s)
                    <tr class="sensor-row {{ $s->stale() ? 'is-off' : '' }}" data-sensor="{{ $s->key }}" data-sensor-label="{{ $s->label }}" data-sensor-unit="{{ $s->unit }}" tabindex="0">
                        <td>{{ $s->label }}</td>
                        <td class="num">@if($s->status === 'ok'){{ $s->display() }}@else<span class="badge plain {{ $s->tone() }}">{{ $s->display() }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody></table>
            @endforeach
        </div></section>
        @endif
        @can('manage-devices')
        @if($device->cfg_type)
        <section class="card"><div class="hd"><h2>Konfigurasi</h2>
            <form method="post" action="{{ route('devices.config.backup', $device) }}">@csrf<button class="btn sm" @disabled($device->cfg_requested)>{{ $device->cfg_requested ? 'Diminta…' : 'Backup sekarang' }}</button></form></div>
            <div class="bd flush">
                @if($device->cfg_error)
                    <div class="alert error" style="margin:12px"><x-icon name="alert"/><div>{{ $device->cfg_error }}
                        @if(str_contains($device->cfg_error, 'host key'))<form method="post" action="{{ route('devices.config.hostkey', $device) }}" data-confirm="Reset host key SSH {{ $device->name }}? Lakukan hanya bila perangkat memang diganti / di-reset." style="margin-top:8px">@csrf<button class="btn sm danger">Reset host key</button></form>@endif
                    </div></div>
                @endif
                @if($configs->isEmpty())<div class="empty">Belum ada versi.{{ $device->cfg_last_at ? '' : ' Backup pertama berjalan dalam 1 menit.' }}</div>@else
                <table class="t"><tbody>
                    @foreach($configs as $c)
                        <tr><td><a href="{{ route('devices.config', [$device, $c->id]) }}">{{ \Illuminate\Support\Carbon::parse($c->taken_at)->format('d/m/Y H:i') }}</a></td>
                            <td class="num">@if($c->added || $c->removed)<span style="color:var(--ok)">+{{ $c->added }}</span> <span style="color:var(--crit)">−{{ $c->removed }}</span>@else<span class="muted">{{ $c->lines }} baris</span>@endif</td></tr>
                    @endforeach
                </tbody></table>@endif
                <p class="muted" style="font-size:12px;margin:0;padding:8px 14px">Dicek terakhir {{ $device->cfg_last_at?->diffForHumans() ?? '–' }} · host key {{ $device->ssh_hostkey ? \Illuminate\Support\Str::limit($device->ssh_hostkey, 22) : 'belum dicatat' }}</p>
            </div></section>
        @endif
        @endcan
        <section class="card"><div class="hd"><h2>Informasi</h2></div><div class="bd">
            <dl class="facts">
                <dt>Site</dt><dd>{{ $device->site ? $device->site->code . ' — ' . $device->site->name : '–' }}</dd>
                <dt>Induk</dt><dd>@if($device->parent)<a href="{{ route('devices.show', $device->parent) }}">{{ $device->parent->name }}</a>@else – @endif</dd>
                @if($device->children->isNotEmpty())<dt>Di bawahnya</dt><dd>@foreach($device->children as $ch)<a href="{{ route('devices.show', $ch) }}">{{ $ch->name }}</a>@if(! $loop->last), @endif @endforeach</dd>@endif
                <dt>Ping</dt><dd>{{ $device->ping_enabled ? 'aktif, tiap menit' : 'dimatikan' }}</dd>
                <dt>SNMP</dt><dd>@if(! $device->snmp_enabled) dimatikan @elseif($device->snmp_ok === false)<span style="color:var(--warn)">gagal — {{ $device->snmp_error }}</span>@else v{{ $device->snmp_version }}, port {{ $device->snmp_port }}@if($device->last_snmp_at) · {{ $device->last_snmp_at->diffForHumans() }}@endif @endif</dd>
                @if($device->sys_name)<dt>sysName</dt><dd>{{ $device->sys_name }}</dd>@endif
                @if($device->sys_descr)<dt>Deskripsi</dt><dd>{{ \Illuminate\Support\Str::limit($device->sys_descr, 220) }}</dd>@endif
                @if($device->sys_location)<dt>Lokasi SNMP</dt><dd>{{ $device->sys_location }}</dd>@endif
                @if($device->profiles)<dt>Profil</dt><dd>{{ collect(explode(',', $device->profiles))->map(fn ($x) => \App\Models\DeviceSensor::PROFILES[$x] ?? $x)->implode(', ') }}</dd>@endif
                @if($device->sys_object_id)<dt>sysObjectID</dt><dd class="mono">{{ $device->sys_object_id }}</dd>@endif
                @if(! empty($device->thresholds))<dt>Ambang khusus</dt><dd>{{ collect($device->thresholds)->map(fn ($v, $k) => (\App\Models\Device::THRESHOLDS[$k][0] ?? $k) . ' ' . (is_array($v) ? implode(' / ', $v) : $v))->implode(' · ') }}</dd>@endif
                @if($device->notes)<dt>Catatan</dt><dd style="white-space:pre-line">{{ $device->notes }}</dd>@endif
            </dl>
        </div></section>
        <section class="card"><div class="hd"><h2>Kejadian terbaru</h2><a class="sub" href="{{ route('events.index', ['q' => $device->host]) }}">Semua →</a></div>
            <div class="bd flush">@include('events.feed', ['events' => $events, 'showDevice' => false])</div></section>
    </div>
</div>
@endsection
