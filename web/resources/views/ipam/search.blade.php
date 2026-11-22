@extends('layouts.app')
@section('title', 'Cari IP / MAC')
@section('content')
<div class="crumbs"><a href="{{ route('ipam.index') }}">IPAM</a> / Cari</div>
@include('ipam._nav', ['q' => $q])
<section class="card"><div class="hd"><h2>Hasil untuk "{{ $q }}"</h2><span class="sub">{{ $results->count() }} entri ARP</span></div><div class="bd flush">
    @if($q === '')<div class="empty">Ketik alamat IP (boleh sebagian, mis. 10.10.5.) atau MAC (format apa pun).</div>
    @elseif($results->isEmpty())<div class="empty">Tidak ditemukan di tabel ARP router mana pun.</div>
    @else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>IP</th><th>MAC</th><th>Perangkat</th><th>Subnet</th><th>Terlihat di</th><th>Pertama</th><th>Terakhir</th></tr></thead>
        <tbody>
        @foreach($results as $r)
            <tr>
                <td class="mono">{{ $r->ip }}</td>
                <td class="mono"><a href="{{ route('ipam.search', ['q' => $r->mac]) }}">{{ $r->mac }}</a></td>
                <td>{{ $r->device ?? 'tidak ada' }}</td>
                <td class="mono">{{ $r->subnet ?? 'tidak ada' }}</td>
                <td><a href="{{ route('devices.show', $r->reporter_id) }}">{{ $r->reporter }}</a>{{ $r->port ? ', ' . $r->port : '' }}</td>
                <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($r->first_seen)->format('d/m H:i') }}</td>
                <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($r->last_seen)->diffForHumans() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>@endif
</div></section>
@endsection
