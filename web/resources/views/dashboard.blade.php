@extends('layouts.app')
@section('title', 'Ringkasan')
@section('content')
<div class="page-head">
    <div><h1>Ringkasan</h1><p>Kondisi seluruh perangkat saat ini · diperbarui otomatis tiap 60 detik</p></div>
    @can('manage-devices')<div class="actions"><a class="btn primary" href="{{ route('devices.create') }}"><x-icon name="plus"/>Tambah perangkat</a></div>@endcan
</div>

<div class="kpis">
    <div class="kpi {{ $availability === null ? '' : ($availability >= 99 ? 'ok' : ($availability >= 95 ? 'warn' : 'crit')) }}">
        <div class="lbl">Ketersediaan</div>
        <div class="val">{{ $availability === null ? '–' : number_format($availability, $availability == 100 ? 0 : 2, ',', '.') }}<small>%</small></div>
        <div class="note">{{ $counts->up }} dari {{ $counts->up + $counts->down }} perangkat online</div>
    </div>
    <a class="kpi ok" href="{{ route('devices.index', ['status' => 'up']) }}"><div class="lbl">Online</div><div class="val">{{ $counts->up }}</div><div class="note">dari {{ $counts->total }} aktif</div></a>
    <a class="kpi {{ $counts->down ? 'crit' : '' }}" href="{{ route('devices.index', ['status' => 'down']) }}"><div class="lbl">Down</div><div class="val">{{ $counts->down }}</div><div class="note">{{ $counts->down ? 'perlu ditangani' : 'tidak ada' }}</div></a>
    <a class="kpi {{ $counts->snmp_fail ? 'warn' : '' }}" href="{{ route('devices.index', ['status' => 'snmp_fail']) }}"><div class="lbl">SNMP gagal</div><div class="val">{{ $counts->snmp_fail }}</div><div class="note">ping jalan, data detail tidak terbaca</div></a>
    <a class="kpi" href="{{ route('devices.index', ['status' => 'unknown']) }}"><div class="lbl">Menunggu</div><div class="val">{{ $counts->unknown }}</div><div class="note">{{ $disabled ? $disabled . ' nonaktif' : 'belum diperiksa' }}</div></a>
</div>

@if($counts->total === 0)
    <div class="card"><div class="empty"><x-icon name="server"/>Belum ada perangkat.
        @can('manage-devices')<div style="margin-top:12px"><a class="btn primary" href="{{ route('devices.create') }}"><x-icon name="plus"/>Tambah perangkat pertama</a></div>@endcan
    </div></div>
@else
<div class="grid g-main">
    <div class="stack">
        <section class="card">
            <div class="hd"><h2>Perangkat down</h2><span class="sub">{{ $down->count() }} perangkat</span></div>
            <div class="bd flush">
                @if($down->isEmpty())
                    <div class="empty"><x-icon name="check"/>Semua perangkat online.</div>
                @else
                <div class="table-wrap"><table class="t">
                    <thead><tr><th>Perangkat</th><th>Site</th><th>Penyebab</th><th class="num">Down sejak</th></tr></thead>
                    <tbody>
                    @foreach($down as $d)
                        <tr class="is-down">
                            <td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a><small class="mono">{{ $d->host }}</small></td>
                            <td>{{ $d->site?->code ?? '–' }}</td>
                            <td>{{ $d->status_reason ?? '–' }}</td>
                            <td class="num" title="{{ $d->status_changed_at?->format('d/m/Y H:i:s') }}">{{ $d->status_changed_at?->diffForHumans(short: true) ?? '–' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @endif
            </div>
        </section>

        <section class="card">
            <div class="hd"><h2>Latensi & loss tertinggi</h2><span class="sub">ping terakhir</span></div>
            <div class="bd flush"><div class="table-wrap"><table class="t">
                <thead><tr><th>Perangkat</th><th>Site</th><th class="num">RTT</th><th class="num">Loss</th></tr></thead>
                <tbody>
                @forelse($slow as $d)
                    <tr>
                        <td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a></td>
                        <td>{{ $d->site?->code ?? '–' }}</td>
                        <td class="num" style="color:{{ $d->last_rtt_ms >= 150 ? 'var(--crit)' : ($d->last_rtt_ms >= 50 ? 'var(--warn)' : 'inherit') }}">{{ \App\Support\Fmt::ms($d->last_rtt_ms) }}</td>
                        <td class="num" style="color:{{ $d->last_loss_pct > 0 ? 'var(--warn)' : 'inherit' }}">{{ (int) $d->last_loss_pct }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">Belum ada data ping.</td></tr>
                @endforelse
                </tbody>
            </table></div></div>
        </section>
    </div>

    <section class="card">
        <div class="hd"><h2>Kejadian 24 jam</h2><a class="sub" href="{{ route('events.index') }}">Semua →</a></div>
        <div class="bd flush">@include('events.feed', ['events' => $events, 'showDevice' => true])</div>
    </section>
</div>

@if($sites->isNotEmpty())
<section class="card" style="margin-top:16px">
    <div class="hd"><h2>Per site</h2></div>
    <div class="sites">
        @foreach($sites as $s)
            <a class="site {{ $s->down ? 'bad' : '' }}" href="{{ route('devices.index', ['site' => $s->id]) }}">
                <b>{{ $s->code }}</b><small>{{ $s->name }}</small>
                <div style="margin-top:6px;font-size:13px">@if($s->down)<strong style="color:var(--crit)">{{ $s->down }} down</strong> · @endif{{ $s->up }}/{{ $s->total }} online</div>
                <div class="stack-bar">
                    @if($s->up)<span style="flex:{{ $s->up }};background:var(--ok)"></span>@endif
                    @if($s->down)<span style="flex:{{ $s->down }};background:var(--crit)"></span>@endif
                    @if($s->total - $s->up - $s->down > 0)<span style="flex:{{ $s->total - $s->up - $s->down }};background:var(--off)"></span>@endif
                </div>
            </a>
        @endforeach
    </div>
</section>
@endif
@endif
@push('scripts')<script>setTimeout(function () { if (!document.hidden) location.reload(); else document.addEventListener('visibilitychange', function () { location.reload(); }, { once: true }); }, 60000);</script>@endpush
@endsection
