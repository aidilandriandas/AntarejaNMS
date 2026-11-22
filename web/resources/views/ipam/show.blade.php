@extends('layouts.app')
@section('title', 'Subnet ' . $s->cidr_text)
@section('content')
<div class="crumbs"><a href="{{ route('ipam.index') }}">IPAM</a> / {{ $s->cidr_text }}</div>
<div class="page-head">
    <div><h1 class="mono">{{ $s->cidr_text }}</h1><p>{{ $s->name ?: 'Tanpa nama' }} · {{ $s->site ?? 'tanpa site' }}{{ $s->vlan ? ' · VLAN ' . $s->vlan : '' }} · {{ $s->source === 'auto' ? 'otomatis' : 'manual' }}</p></div>
</div>
<div class="grid g-main">
    <div class="stack">
        @unless($big)
        <section class="card"><div class="hd"><h2>Peta alamat</h2>
            <span class="legend"><i style="background:var(--info)"></i>Perangkat terdaftar <i style="background:var(--ok)"></i>Terpakai (7 hari) <i style="background:var(--warn)"></i>Lama (> 7 hari) <i style="background:var(--crit)"></i>Konflik <i class="hollow"></i>Kosong</span></div>
            <div class="bd"><div class="ipgrid">
                @foreach($grid as $g)
                    @php($st = isset($conflictIps[$g['ip']]) ? 'conflict' : $g['state'])
                    @php($i = $g['info'])
                    <span class="ip {{ $st }}" title="{{ $g['ip'] }}{{ $i && $i['device'] ? ' · ' . $i['device'] : '' }}{{ $i && $i['macs'] ? ' · ' . implode(', ', $i['macs']) : '' }}{{ $st === 'free' ? ' · kosong' : '' }}{{ $st === 'reserved' ? ' · alamat jaringan/broadcast' : '' }}">{{ last(explode('.', $g['ip'])) }}</span>
                @endforeach
            </div></div></section>
        @endunless
        <section class="card"><div class="hd"><h2>Alamat terpakai</h2><span class="sub">{{ $ips->count() }}</span></div><div class="bd flush">
            @if($ips->isEmpty())<div class="empty">Belum ada alamat terlihat di subnet ini.</div>@else
            <div class="table-wrap"><table class="t">
                <thead><tr><th>IP</th><th>Perangkat</th><th>MAC</th><th>Terakhir terlihat</th><th>Router</th></tr></thead>
                <tbody>
                @foreach($ips as $e)
                    <tr class="{{ isset($conflictIps[$e['ip']]) ? 'is-down' : ($e['state'] === 'stale' ? 'is-off' : '') }}">
                        <td class="mono"><a href="{{ route('ipam.search', ['q' => $e['ip']]) }}">{{ $e['ip'] }}</a></td>
                        <td>@if($e['device_id'])<a href="{{ route('devices.show', $e['device_id']) }}">{{ $e['device'] }}</a>{{ $e['iface'] ? ' (interface)' : '' }}@else<span class="muted">–</span>@endif</td>
                        <td class="mono" style="font-size:12.5px">{{ implode(', ', $e['macs']) ?: '–' }}@if(count($e['macs']) > 1) <span class="badge plain warn">{{ count($e['macs']) }} MAC</span>@endif</td>
                        <td class="nowrap">{{ $e['last_seen'] ? \Illuminate\Support\Carbon::parse($e['last_seen'])->diffForHumans() : '–' }}</td>
                        <td>{{ $e['reporter'] ?? '–' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>@endif
        </div></section>
    </div>
    <div class="stack">
        @unless($big)
        <section class="card"><div class="hd"><h2>IP kosong</h2></div><div class="bd">
            @if(empty($free))<p class="muted" style="margin:0">Tidak ada IP kosong.</p>@else
            <div class="mono" style="font-size:13px;line-height:1.9">{{ implode(' · ', $free) }}</div>
            <p class="muted" style="font-size:12px;margin:8px 0 0">Kosong = tidak terlihat di ARP 30 hari terakhir dan bukan perangkat terdaftar. Tetap cek ulang (ping) sebelum dipakai: perangkat yang mati lama tidak tampil di ARP.</p>@endif
        </div></section>
        @endunless
        @can('manage-devices')
        <section class="card"><div class="hd"><h2>Ubah subnet</h2></div><div class="bd">
            <form method="post" action="{{ route('ipam.update', $s->id) }}" class="form">
                @csrf @method('PUT')
                <div class="field"><label for="name">Nama</label><input class="input" id="name" name="name" value="{{ $s->name }}"></div>
                <div class="row">
                    <div class="field"><label for="site_id">Site</label><select class="input" id="site_id" name="site_id"><option value="">–</option>@foreach($sites as $st)<option value="{{ $st->id }}" @selected($s->site_id == $st->id)>{{ $st->code }}</option>@endforeach</select></div>
                    <div class="field"><label for="vlan">VLAN</label><input class="input" type="number" min="1" max="4094" id="vlan" name="vlan" value="{{ $s->vlan }}"></div>
                </div>
                <div class="field"><label for="note">Catatan</label><textarea class="input" id="note" name="note" rows="2">{{ $s->note }}</textarea></div>
                <div class="form-foot"><button class="btn primary">Simpan</button></div>
            </form>
            <form method="post" action="{{ route('ipam.destroy', $s->id) }}" data-confirm="Hapus subnet {{ $s->cidr_text }}?" style="margin-top:12px">@csrf @method('DELETE')<button class="btn danger sm"><x-icon name="trash"/>Hapus subnet</button></form>
        </div></section>
        @endcan
    </div>
</div>
@endsection
