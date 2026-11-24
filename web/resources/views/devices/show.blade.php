@extends('layouts.app')
@section('title', $device->name)
@section('content')
<div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a>@if($device->site) / <a href="{{ route('devices.index', ['site' => $device->site_id]) }}">{{ $device->site->code }}</a>@endif</div>
<div class="page-head">
    <div><h1>{{ $device->name }} <x-status :device="$device"/></h1>
        @if($device->collector)<p class="muted">Dipantau dari collector site @can('admin')<a href="{{ route('collectors.show', $device->collector) }}">{{ $device->collector->name }}</a>@else{{ $device->collector->name }}@endcan @if($device->collector->state() !== 'online'), <span class="txt-warn">collector {{ $device->collector->stateLabel() }}{{ $device->collector->last_seen_at ? ' sejak ' . $device->collector->last_seen_at->format('d/m H.i') : '' }}: status di bawah adalah hasil terakhir yang diterima</span>@endif</p>@endif
        <div class="meta" style="margin-top:6px"><span class="mono">{{ $device->host }}</span><span>{{ $device->categoryLabel() }}</span>@if($device->status === 'down' && $device->status_reason)<span style="color:var(--color-crit);white-space:normal">{{ $device->status_reason }}</span>@endif</div></div>
    @can('manage-devices')
        <div class="actions">
            <form method="post" action="{{ route('devices.poll', $device) }}">@csrf<button class="btn"><x-icon name="refresh"/>Periksa sekarang</button></form>
            @if($device->snmp_enabled)<a class="btn" href="{{ route('devices.diagnose', $device) }}" title="Berkas JSON hasil baca SNMP (tanpa kredensial) untuk membuat profil perangkat yang belum dikenal. Butuh sampai 1 menit."><x-icon name="download"/>Diagnosa SNMP</a>@endif
            <a class="btn" href="{{ route('devices.edit', $device) }}"><x-icon name="edit"/>Ubah</a>
        </div>
    @endcan
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">Latensi</div><div class="val">{{ $device->ping_enabled ? \App\Support\Fmt::ms($device->last_rtt_ms) : 'Ping mati' }}</div>
        <div class="note">{{ $device->last_jitter_ms !== null ? 'jitter ' . \App\Support\Fmt::ms($device->last_jitter_ms) . ', ' : '' }}{{ $device->last_ping_at ? 'ping ' . $device->last_ping_at->diffForHumans() : ($device->ping_enabled ? 'menunggu ping pertama' : 'ping dimatikan') }}</div></div>
    <div class="kpi {{ $device->last_loss_pct > 0 ? 'warn' : '' }}"><div class="lbl">Packet loss</div><div class="val">{{ $device->ping_enabled && $device->last_loss_pct !== null ? (int) $device->last_loss_pct : 'tidak ada' }}<small>%</small></div><div class="note">3 paket per menit</div></div>
    <div class="kpi"><div class="lbl">Uptime</div><div class="val" style="font-size:22px;padding-top:6px">{{ $device->uptimeHuman() ?? 'Belum terbaca' }}</div><div class="note">{{ $device->snmp_enabled ? 'dari SNMP' : 'butuh SNMP' }}</div></div>
    <div class="kpi"><div class="lbl">Status sejak</div><div class="val" style="font-size:22px;padding-top:6px">{{ $device->status_changed_at?->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) ?? 'tidak ada' }}</div><div class="note">{{ $device->status_changed_at?->format('d/m/Y H:i') }}</div></div>
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

        @if($device->snmp_enabled && $device->ports->isNotEmpty())
        <section class="card live-card" data-live-card data-url="{{ route('devices.live', $device) }}" hidden>
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
                <p class="muted live-note" data-live-note>Dibaca langsung dari perangkat (SNMP) setiap beberapa detik selama halaman ini terbuka; berhenti sendiri saat tab disembunyikan atau setelah 15 menit.</p>
            </div>
        </section>
        @endif

        @if($device->snmp_enabled)
        <section class="card" data-ports-card>
            <div class="hd"><h2>Port</h2><span class="sub">{{ $device->ports->count() }} port, klik nama port untuk grafik lengkap, baris untuk grafik ringkas, <b>Live</b> untuk trafik saat ini</span>
                @if($device->ports->isNotEmpty())<label class="check live-all"><input type="checkbox" data-live-all><span>Live semua port</span></label>@endif</div>
            <div class="bd flush">
                @if($device->ports->isEmpty())
                    <div class="empty">{{ $device->snmp_ok === false ? 'SNMP gagal: ' . $device->snmp_error : 'Data port muncul setelah pembacaan SNMP pertama.' }}</div>
                @else
                <div class="table-wrap"><table class="t">
                    <thead><tr><th>Port</th><th>Status</th><th class="num">Kecepatan</th><th class="num">Masuk</th><th class="num">Keluar</th><th>Pemakaian</th><th class="num" title="Error dan discard per menit (masuk + keluar), dari putaran SNMP terakhir">Error / discard</th><th>24 jam</th><th></th><th title="Problem bila port putus atau penuh">Pantau</th></tr></thead>
                    <tbody>
                    @foreach($device->ports as $p)
                        @php
                            $in = $traffic['in'][$p->if_index] ?? null; $out = $traffic['out'][$p->if_index] ?? null;
                            $util = $p->speed_bps > 0 && ($in !== null || $out !== null) ? min(100, max($in ?? 0, $out ?? 0) / $p->speed_bps * 100) : null;
                        @endphp
                        <tr class="port-row {{ $p->oper_up ? '' : 'is-off' }}" data-port="{{ $p->if_index }}" data-port-name="{{ $p->label() }}" data-speed="{{ (int) $p->speed_bps }}" data-up="{{ $p->oper_up ? 1 : 0 }}" tabindex="0">
                            <td class="name"><a href="{{ route('ports.show', [$device, $p->if_index]) }}" data-stop-row>{{ $p->label() }}</a><small>{{ $p->alias ?: ($p->descr !== $p->name ? $p->descr : '') }}</small></td>
                            <td>@if(! $p->admin_up)<span class="badge">dimatikan</span>@elseif($p->oper_up)<span class="badge ok">up</span>@else<span class="badge">down</span>@endif</td>
                            <td class="num">{{ $p->speed_bps ? \App\Support\Fmt::bps((float) $p->speed_bps) : 'tidak ada' }}</td>
                            <td class="num" data-cell-in>{{ \App\Support\Fmt::bps($in) }}</td>
                            <td class="num" data-cell-out>{{ \App\Support\Fmt::bps($out) }}</td>
                            <td data-cell-util>@if($util !== null)<div class="bar {{ $util >= 90 ? 'crit' : ($util >= 70 ? 'warn' : '') }}" title="{{ number_format($util, 1, ',', '.') }}%"><span style="width:{{ max(1, $util) }}%"></span></div>@else<span class="muted"><span class="nil">tidak ada</span></span>@endif</td>
                            <td class="num nowrap">@if($p->err_rate === null && $p->discard_rate === null)<span class="muted"><span class="nil">tidak ada</span></span>@else<span class="{{ $p->err_rate >= 1 ? 'txt-warn' : '' }}">{{ \App\Support\Fmt::perMin($p->err_rate) }}</span> / <span class="{{ $p->discard_rate >= 1 ? 'txt-warn' : '' }}">{{ \App\Support\Fmt::perMin($p->discard_rate) }}</span>@endif</td>
                            <td>@include('ports.spark', ['sp' => $traffic['spark'][$p->if_index] ?? []])</td>
                            <td><button type="button" class="btn sm live-btn" data-live-port="{{ $p->if_index }}" title="Trafik live port {{ $p->label() }}">Live</button></td>
                            <td>
                                @php($mon = in_array($p->if_index, $monitored))
                                @can('manage-devices')
                                    <form method="post" action="{{ route('devices.monitor', [$device, $p->if_index]) }}" data-stop>@csrf
                                        <button class="btn sm {{ $mon ? 'primary' : '' }}" title="{{ $mon ? 'Berhenti memantau' : 'Pantau port ini' }}" aria-pressed="{{ $mon ? 'true' : 'false' }}">{{ $mon ? 'Dipantau' : 'Pantau' }}</button></form>
                                @else
                                    {!! $mon ? '<span class="badge info plain">dipantau</span>' : '<span class="muted"><span class="nil">tidak ada</span></span>' !!}
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
        @php($defPort = ['camera' => 554, 'nvr' => 554, 'server' => 22][$device->category] ?? ($device->cfg_type ? 22 : 80))
        <section class="card" data-tools data-url="{{ route('devices.tool', $device) }}"><div class="hd"><h2>Alat diagnosa</h2><span class="sub">dari server ANTAREJA ke {{ $device->host }}</span></div><div class="bd">
            <div class="tool-bar">
                <button type="button" class="btn sm" data-tool="ping"><x-icon name="pulse"/>Ping 10 kali</button>
                <button type="button" class="btn sm" data-tool="traceroute"><x-icon name="share"/>Traceroute</button>
                <span class="tool-port"><label class="sr-only" for="tool-port">Port TCP</label><input class="input sm mono" id="tool-port" type="number" min="1" max="65535" value="{{ $defPort }}" data-tool-port><button type="button" class="btn sm" data-tool="port"><x-icon name="plug"/>Cek port</button></span>
            </div>
            <div class="tool-out" data-tool-out aria-live="polite"><p class="muted">Hasil muncul di sini. Traceroute bisa butuh sampai 30 detik.</p></div>
        </div></section>
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
                <p class="muted" style="font-size:12px;margin:0;padding:8px 14px">Dicek terakhir {{ $device->cfg_last_at?->diffForHumans() ?? 'tidak ada' }}, host key {{ $device->ssh_hostkey ? \Illuminate\Support\Str::limit($device->ssh_hostkey, 22) : 'belum dicatat' }}</p>
            </div></section>
        @endif
        @endcan
        @if($runbooks->isNotEmpty() || auth()->user()->can('manage-devices'))
        <section class="card"><div class="hd"><h2>Runbook</h2>@can('manage-devices')<a class="sub" href="{{ route('runbooks.create', ['device' => $device->id]) }}">Tambah runbook</a>@endcan</div><div class="bd">
            @if($runbooks->isEmpty())<p class="muted" style="margin:0">Belum ada langkah penanganan untuk perangkat ini.</p>@else
            <ul class="impact-list">@foreach($runbooks as $rb)<li><a href="{{ route('runbooks.show', $rb) }}">{{ $rb->title }}</a> <span class="muted">{{ $rb->problem_kind ? (\App\Models\Problem::KINDS[$rb->problem_kind] ?? $rb->problem_kind) : 'semua problem' }}</span></li>@endforeach</ul>@endif
        </div></section>
        @endif
        @if($checks->isNotEmpty() || auth()->user()->can('manage-devices'))
        <section class="card"><div class="hd"><h2>Cek layanan</h2>@can('manage-devices')<a class="sub" href="{{ route('checks.create', ['device' => $device->id]) }}">Tambah cek</a>@endcan</div><div class="bd @if($checks->isNotEmpty()) flush @endif">
            @if($checks->isEmpty())<p class="muted" style="margin:0">Belum ada. Tambahkan bila perangkat ini menjalankan aplikasi (web, database, DNS) supaya aplikasinya ikut dipantau.</p>@else
            <table class="t"><tbody>
            @foreach($checks as $c)
                @php([$lbl, $tone] = \App\Models\ServiceCheck::STATES[$c->status])
                <tr><td class="name">{{ $c->name }}<small class="mono">{{ $c->target() }}</small></td>
                    <td class="right">@if(! $c->enabled)<span class="badge">nonaktif</span>@else<span class="badge {{ $tone }}">{{ $lbl }}</span>@endif
                        @if($c->last_ms !== null && $c->status !== 'down')<div class="muted" style="font-size:12px">{{ \App\Support\Fmt::ms($c->last_ms) }}</div>@endif</td></tr>
            @endforeach
            </tbody></table>@endif
        </div></section>
        @endif
        @if($impact && ($impactLine = \App\Services\Impact::line($impact)) !== '')
        <section class="card"><div class="hd"><h2>{{ $device->status === 'down' ? 'Dampak sekarang' : 'Bila perangkat ini mati' }}</h2><a class="sub" href="{{ route('impact.index', ['sim' => [$device->id]]) }}#simulasi">Rincian</a></div><div class="bd">
            <p style="margin:0">{{ ucfirst($impactLine) }}.</p>
            @if($impact['services'])<p class="muted" style="margin:var(--space-2xs) 0 0">Layanan: {{ collect($impact['services'])->map(fn ($s) => $s['service']->name)->implode(', ') }}</p>@endif
        </div></section>
        @endif
        <section class="card"><div class="hd"><h2>Informasi</h2></div><div class="bd">
            <dl class="facts">
                <dt>Site</dt><dd>{{ $device->site ? $device->site->code . ' (' . $device->site->name . ')' : 'tidak ada' }}</dd>
                @if($pluggedIn)<dt>Terpasang di</dt><dd>@foreach($pluggedIn as $pi)<a href="{{ route('ports.show', [$pi->device_id, $pi->if_index]) }}">{{ $pi->sw_name }}, {{ $pi->sw_port ?? 'port ' . $pi->if_index }}</a>{{ $pi->vlan ? ' (VLAN ' . $pi->vlan . ')' : '' }}@if(! $loop->last); @endif @endforeach</dd>@endif
                <dt>Induk</dt><dd>@if($device->parent)<a href="{{ route('devices.show', $device->parent) }}">{{ $device->parent->name }}</a>@else<span class="nil">tidak ada</span>@endif</dd>
                @if($device->children->isNotEmpty())<dt>Di bawahnya</dt><dd>@foreach($device->children as $ch)<a href="{{ route('devices.show', $ch) }}">{{ $ch->name }}</a>@if(! $loop->last), @endif @endforeach</dd>@endif
                <dt>Ping</dt><dd>{{ $device->ping_enabled ? 'aktif, tiap menit' : 'dimatikan' }}</dd>
                <dt>SNMP</dt><dd>@if(! $device->snmp_enabled) dimatikan @elseif($device->snmp_ok === false)<span style="color:var(--warn)">gagal: {{ $device->snmp_error }}</span>@else v{{ $device->snmp_version }}, port {{ $device->snmp_port }}@if($device->last_snmp_at), {{ $device->last_snmp_at->diffForHumans() }}@endif @endif</dd>
                @if($device->sys_name)<dt>sysName</dt><dd>{{ $device->sys_name }}</dd>@endif
                @if($device->sys_descr)<dt>Deskripsi</dt><dd>{{ \Illuminate\Support\Str::limit($device->sys_descr, 220) }}</dd>@endif
                @if($device->sys_location)<dt>Lokasi SNMP</dt><dd>{{ $device->sys_location }}</dd>@endif
                @if($device->profiles)<dt>Profil</dt><dd>{{ collect(explode(',', $device->profiles))->map(fn ($x) => \App\Models\DeviceSensor::PROFILES[$x] ?? $x)->implode(', ') }}</dd>@endif
                @if($device->sys_object_id)<dt>sysObjectID</dt><dd class="mono">{{ $device->sys_object_id }}</dd>@endif
                @if(! empty($device->thresholds))<dt>Ambang khusus</dt><dd>{{ collect($device->thresholds)->map(fn ($v, $k) => (\App\Models\Device::THRESHOLDS[$k][0] ?? $k) . ' ' . (is_array($v) ? implode(' / ', $v) : $v))->implode(', ') }}</dd>@endif
                @if($device->notes)<dt>Catatan</dt><dd style="white-space:pre-line">{{ $device->notes }}</dd>@endif
            </dl>
        </div></section>
        @if($logs->isNotEmpty())
        <section class="card"><div class="hd"><h2>Log terbaru</h2><a class="sub" href="{{ route('logs.index', ['device' => $device->id]) }}">Semua log</a></div>
            <div class="bd flush"><ul class="feed logfeed">@foreach($logs as $l)<li><span class="badge plain {{ $l->sevTone() }}">{{ $l->sevLabel() }}</span><div class="msg mono">{{ \Illuminate\Support\Str::limit($l->message, 160) }}@if($l->app)<small>{{ $l->app }}</small>@endif</div><time title="{{ $l->received_at->format('d/m/Y H:i:s') }}">{{ $l->received_at->diffForHumans(short: true) }}</time></li>@endforeach</ul></div></section>
        @endif
        <section class="card"><div class="hd"><h2>Kejadian terbaru</h2><a class="sub" href="{{ route('events.index', ['q' => $device->host]) }}">Semua kejadian</a></div>
            <div class="bd flush">@include('events.feed', ['events' => $events, 'showDevice' => false])</div></section>
    </div>
</div>
@endsection
