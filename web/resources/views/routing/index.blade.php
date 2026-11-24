@extends('layouts.app')
@section('title', 'Routing')
@section('content')
<div class="page-head"><div><h1>Routing</h1>
    <p>Sesi BGP (BGP4-MIB) dan tetangga OSPF (OSPF-MIB) yang dilaporkan router kategori Core, Distribution, Firewall, dan MikroTik lewat SNMP. Sesi yang tidak established atau tetangga yang tidak full menjadi problem. Tetangga yang hilang dari tabel tetap ditampilkan sampai dilupakan.</p></div></div>
@if($bgp->isEmpty() && $ospf->isEmpty())
<section class="card"><div class="bd"><div class="empty">Belum ada data. Router harus menjawab BGP4-MIB atau OSPF-MIB lewat SNMP; di RouterOS dan IOS tabel ini umumnya ikut view SNMP bawaan.</div></div></section>
@else
@include('routing._tables', ['showDevice' => true])
@endif
@endsection
