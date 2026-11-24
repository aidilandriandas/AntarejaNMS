@extends('layouts.app')
@section('title', 'VLAN & STP')
@section('content')
<div class="page-head"><div><h1>VLAN &amp; STP</h1>
    <p>VLAN yang dikenal tiap switch (Q-BRIDGE-MIB, atau VTP untuk Cisco) dan status spanning tree (BRIDGE-MIB), dibaca tiap putaran SNMP dari switch kategori Core, Distribution, dan Access. Nama VLAN yang berbeda antar switch ditandai. Perubahan topologi STP dicatat sebagai kejadian; bila terjadi di 3 pembacaan dalam 1 jam menjadi problem.</p></div></div>

<section class="card"><div class="hd"><h2>Spanning tree</h2>@if($stp->isNotEmpty())<span class="sub">{{ $roots }} root bridge</span>@endif</div><div class="bd flush">
    @if($stp->isEmpty())<div class="empty">Belum ada switch yang melaporkan STP.</div>@else
    <div class="table-wrap"><table class="t"><thead><tr><th>Switch</th><th>Root bridge</th><th>Port ke root</th><th class="num">Perubahan topologi</th><th>Terakhir berubah</th></tr></thead><tbody>
    @foreach($stp as $s)
        <tr><td class="name"><a href="{{ route('devices.show', $s->id) }}">{{ $s->name }}</a></td>
            <td class="mono">{{ $s->stp_root }}</td>
            <td>@if((int) $s->stp_root_port === 0)<span class="badge info plain">switch ini root</span>@elseif($s->root_if)<a href="{{ route('ports.show', [$s->id, $s->stp_root_port]) }}">{{ $s->root_if }}</a>@else ifIndex {{ $s->stp_root_port }} @endif</td>
            <td class="num">{{ number_format($s->stp_top_changes, 0, ',', '.') }}</td>
            <td class="muted">{{ $s->stp_last_change_at ? \Illuminate\Support\Carbon::parse($s->stp_last_change_at)->diffForHumans() : '' }}</td></tr>
    @endforeach
    </tbody></table></div>
    @if($roots > 1)<p class="hint txt-warn" style="margin:0;padding:var(--space-sm) var(--space-md)">Ada lebih dari satu root bridge. Wajar bila switch berada di domain STP terpisah; bila satu jaringan, periksa prioritas bridge.</p>@endif
    @endif
</div></section>

<section class="card"><div class="hd"><h2>VLAN</h2><span class="sub">{{ $vlans->count() }} VLAN</span></div><div class="bd flush">
    @if($vlans->isEmpty())<div class="empty">Belum ada VLAN terbaca.</div>@else
    <div class="table-wrap"><table class="t"><thead><tr><th class="num">ID</th><th>Nama</th><th>Switch</th></tr></thead><tbody>
    @foreach($vlans as $v)
        <tr><td class="num mono">{{ $v['id'] }}</td>
            <td>{{ $v['name'] }}@if($v['mismatch'])<div class="txt-warn" style="font-size:12px">Nama beda antar switch: {{ $v['names']->keys()->implode(', ') }}</div>@endif</td>
            <td>@foreach($v['devices'] as $d)<a href="{{ route('devices.show', $d->device_id) }}">{{ $d->device }}</a>@if($v['mismatch'] && $d->name !== $v['name']) <span class="muted">({{ $d->name ?: 'tanpa nama' }})</span>@endif @if(! $loop->last), @endif @endforeach</td></tr>
    @endforeach
    </tbody></table></div>@endif
</div></section>
@endsection
