@extends('layouts.app')
@section('title', 'Perangkat')
@section('content')
<div class="page-head">
    <div><h1>Perangkat</h1><p>{{ number_format($devices->total(), 0, ',', '.') }} perangkat · yang bermasalah tampil paling atas</p></div>
    @can('manage-devices')<div class="actions"><a class="btn primary" href="{{ route('devices.create') }}"><x-icon name="plus"/>Tambah perangkat</a></div>@endcan
</div>
<section class="card">
    <form class="filters" method="get">
        <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="Nama, IP, sysName">
        <select class="input" name="status" aria-label="Status">
            <option value="">Semua status</option>
            @foreach(['down' => 'Down', 'up' => 'Online', 'unknown' => 'Menunggu', 'snmp_fail' => 'SNMP gagal', 'off' => 'Nonaktif'] as $k => $v)
                <option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <select class="input" name="site" aria-label="Site">
            <option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach
        </select>
        <select class="input" name="category" aria-label="Kategori">
            <option value="">Semua kategori</option>
            @foreach(\App\Models\Device::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $v }}</option>@endforeach
        </select>
        <button class="btn sm">Terapkan</button>
        @if(request()->hasAny(['q', 'status', 'site', 'category']))<a href="{{ route('devices.index') }}" class="btn sm">Reset</a>@endif
    </form>
    <div class="bd flush"><div class="table-wrap"><table class="t">
        <thead><tr><th>Perangkat</th><th>Status</th><th>Site</th><th>Kategori</th><th class="num">RTT</th><th class="num">Loss</th><th>SNMP</th><th class="num">Uptime</th></tr></thead>
        <tbody>
        @forelse($devices as $d)
            <tr class="{{ ! $d->enabled ? 'is-off' : ($d->status === 'down' ? 'is-down' : '') }}">
                <td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a><small class="mono">{{ $d->host }}@if($d->sys_name && $d->sys_name !== $d->name) · {{ $d->sys_name }}@endif</small></td>
                <td><x-status :device="$d"/>@if($d->status === 'down' && $d->status_changed_at)<div class="muted" style="font-size:12px;margin-top:2px">{{ $d->status_changed_at->diffForHumans(short: true) }}</div>@endif</td>
                <td>{{ $d->site?->code ?? '–' }}</td>
                <td><span class="nowrap"><x-icon :name="\App\Models\Device::CATEGORY_ICONS[$d->category] ?? 'box'" class="i muted"/> {{ $d->categoryLabel() }}</span></td>
                <td class="num">{{ $d->ping_enabled ? \App\Support\Fmt::ms($d->last_rtt_ms) : '–' }}</td>
                <td class="num">{{ $d->ping_enabled && $d->last_loss_pct !== null ? (int) $d->last_loss_pct . '%' : '–' }}</td>
                <td>@if(! $d->snmp_enabled)<span class="muted">–</span>@elseif($d->snmp_ok === null)<span class="badge">menunggu</span>@elseif($d->snmp_ok)<span class="badge ok plain">v{{ $d->snmp_version }}</span>@else<span class="badge warn" title="{{ $d->snmp_error }}">gagal</span>@endif</td>
                <td class="num">{{ $d->uptimeHuman() ?? '–' }}</td>
            </tr>
        @empty
            <tr><td colspan="8"><div class="empty">Tidak ada perangkat yang cocok.</div></td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    @include('partials.pager', ['p' => $devices])
</section>
@endsection
