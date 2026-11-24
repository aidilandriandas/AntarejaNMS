@extends('layouts.app')
@section('title', 'Flow trafik')
@php($bytes = fn ($b) => \App\Support\Fmt::bytes($b))
@php($rate = fn ($b) => \App\Support\Fmt::bps($b * 8 / max(1, $secs)))
@php($pct = fn ($b) => $total ? round($b / $total * 100) : 0)
@section('content')
<div class="page-head">
    <div><h1>Flow trafik</h1><p>Siapa dan aplikasi apa yang memakai bandwidth di tiap port, dari NetFlow/IPFIX/sFlow yang dikirim router (ringkasan 5 menit, 50 percakapan terbesar per port, disimpan 7 hari).</p></div>
</div>
@if($exporters->isEmpty())
<section class="card"><div class="bd notes">
    <p style="margin-top:0">Belum ada data flow. Aktifkan ekspor flow di router ke IP server ANTAREJA port <b>2055/UDP</b> (NetFlow v5, v9, atau IPFIX) atau <b>6343/UDP</b> (sFlow v5). Hanya router yang terdaftar sebagai perangkat (IP manajemen atau IP interface-nya) yang diterima.</p>
    <p class="muted">MikroTik RouterOS 7:</p>
    <pre class="code">/ip traffic-flow set enabled=yes interfaces=all
/ip traffic-flow target add dst-address=IP_SERVER_ANTAREJA port=2055 version=ipfix</pre>
    <p class="muted">Cisco IOS (Flexible NetFlow) memakai flow exporter tujuan IP server port 2055, transport udp 2055.</p>
</div></section>
@else
<section class="card">
    <form class="filters" method="get">
        <select class="input" name="perangkat" aria-label="Router" data-autosubmit>@foreach($exporters as $e)<option value="{{ $e->id }}" @selected($dev == $e->id)>{{ $e->name }}</option>@endforeach</select>
        <select class="input" name="port" aria-label="Port" data-autosubmit>@foreach($ports as $p)<option value="{{ $p->if_index }}" @selected($if == $p->if_index)>{{ $p->name ?? 'ifIndex ' . $p->if_index }}{{ $p->alias ? ' (' . $p->alias . ')' : '' }}</option>@endforeach</select>
        <select class="input" name="arah" aria-label="Arah" data-autosubmit><option value="i" @selected($dir === 'i')>Masuk lewat port ini</option><option value="o" @selected($dir === 'o')>Keluar lewat port ini</option></select>
        <select class="input" name="rentang" aria-label="Rentang" data-autosubmit>@foreach(\App\Http\Controllers\FlowController::RANGES as $k => [$lbl])<option value="{{ $k }}" @selected($range === $k)>{{ $lbl }}</option>@endforeach</select>
        <button class="btn sm">Terapkan</button>
    </form>
    <div class="kpis" style="margin-bottom:0">
        <div class="kpi"><span class="lbl">Total</span><span class="val">{{ $bytes($total) }}</span><span class="note">rata-rata {{ $rate($total) }}</span></div>
        <div class="kpi"><span class="lbl">Di luar 50 teratas</span><span class="val">{{ $pct($other) }}%</span><span class="note">{{ $bytes($other) }}</span></div>
    </div>
</section>
@if($total === 0)
<section class="card"><div class="bd"><div class="empty">Tidak ada flow untuk port dan arah ini dalam {{ \App\Http\Controllers\FlowController::RANGES[$range][0] }} terakhir.</div></div></section>
@else
<div class="grid g-2">
    @foreach(['Sumber terbesar' => $sources, 'Tujuan terbesar' => $dests] as $title => $list)
    <section class="card"><div class="hd"><h2>{{ $title }}</h2></div><div class="bd flush"><table class="t"><tbody>
        @foreach($list as $r)<tr><td class="name">{{ $r['name'] ?? $r['ip'] }}@if($r['name'])<small class="mono">{{ $r['ip'] }}</small>@endif</td>
            <td class="num nowrap">{{ $bytes($r['bytes']) }}<div class="muted" style="font-size:12px">{{ $pct($r['bytes']) }}%, {{ $rate($r['bytes']) }}</div></td></tr>@endforeach
    </tbody></table></div></section>
    @endforeach
    <section class="card"><div class="hd"><h2>Aplikasi</h2><span class="sub">protokol dan port layanan</span></div><div class="bd flush"><table class="t"><tbody>
        @foreach($apps as $a)<tr><td>{{ \App\Http\Controllers\FlowController::app($a->proto, $a->port) }}</td>
            <td class="num nowrap">{{ $bytes($a->bytes) }}<div class="muted" style="font-size:12px">{{ $pct($a->bytes) }}%</div></td></tr>@endforeach
    </tbody></table></div></section>
    <section class="card"><div class="hd"><h2>Percakapan terbesar</h2></div><div class="bd flush"><table class="t"><tbody>
        @foreach($convs as $c)<tr><td class="name">{{ $names[$c->src] ?? $c->src }} ke {{ $names[$c->dst] ?? $c->dst }}<small>{{ \App\Http\Controllers\FlowController::app($c->proto, $c->port) }}</small></td>
            <td class="num nowrap">{{ $bytes($c->bytes) }}</td></tr>@endforeach
    </tbody></table></div></section>
</div>
@endif
@endif
@endsection
