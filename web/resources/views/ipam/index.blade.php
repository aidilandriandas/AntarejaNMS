@extends('layouts.app')
@section('title', 'IPAM')
@section('content')
@include('ipam._nav')
<div class="kpis">
    <div class="kpi"><div class="lbl">Subnet</div><div class="val">{{ count($subnets) }}</div></div>
    <div class="kpi"><div class="lbl">IP aktif (7 hari)</div><div class="val">{{ number_format($stats->ips, 0, ',', '.') }}</div><div class="note">{{ number_format($stats->macs, 0, ',', '.') }} MAC unik</div></div>
    <div class="kpi"><div class="lbl">Router sumber ARP</div><div class="val">{{ $stats->routers }}</div><div class="note">kategori Core / Distribution / Firewall atau MikroTik</div></div>
    <div class="kpi {{ $conflicts->isNotEmpty() ? 'warn' : 'ok' }}"><div class="lbl">Konflik IP</div><div class="val">{{ $conflicts->count() }}</div><div class="note">2 MAC bergantian berulang</div></div>
</div>

@if($conflicts->isNotEmpty())
<section class="card" style="margin-bottom:16px"><div class="hd"><h2>Konflik IP aktif</h2></div><div class="bd flush"><table class="t"><tbody>
    @foreach($conflicts as $p)
        <tr class="is-down"><td class="name">{{ $p->title }}<small>{{ $p->detail }}</small></td><td class="nowrap">sejak {{ $p->started_at->format('d/m H:i') }}</td>
            <td class="right"><a class="btn sm" href="{{ route('ipam.search', ['q' => substr($p->sensor_key, 3)]) }}">Lacak</a></td></tr>
    @endforeach
</tbody></table></div></section>
@endif

<div class="grid g-main">
    <section class="card"><div class="hd"><h2>Subnet</h2></div><div class="bd flush">
        @if(empty($subnets))
            <div class="empty">Belum ada subnet. Subnet muncul otomatis dari IP interface perangkat ber-SNMP, atau tambahkan manual.</div>
        @else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Subnet</th><th>Site</th><th class="num">VLAN</th><th>Pemakaian</th><th class="num">Terpakai</th></tr></thead>
            <tbody>
            @foreach($subnets as $s)
                @php($pct = $s->size > 0 ? min(100, $s->used / $s->size * 100) : 0)
                <tr>
                    <td class="name"><a class="mono" href="{{ route('ipam.show', $s->id) }}">{{ $s->cidr }}</a><small>{{ $s->name ?: ($s->source === 'auto' ? 'otomatis dari interface perangkat' : '–') }}</small></td>
                    <td>{{ $s->site ?? '–' }}</td>
                    <td class="num">{{ $s->vlan ?? '–' }}</td>
                    <td style="min-width:140px"><div class="bar {{ $pct >= 90 ? 'crit' : ($pct >= 75 ? 'warn' : '') }}"><span style="width:{{ max(1, $pct) }}%"></span></div></td>
                    <td class="num">{{ $s->used }} / {{ number_format($s->size, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </div></section>
    <div class="stack">
        @can('manage-devices')
        <section class="card"><div class="hd"><h2>Tambah subnet</h2></div><div class="bd">
            <form method="post" action="{{ route('ipam.store') }}" class="form">
                @csrf
                <div class="field"><label for="cidr">Subnet (CIDR)</label><input class="input mono @error('cidr') is-err @enderror" id="cidr" name="cidr" value="{{ old('cidr') }}" placeholder="10.10.5.0/24" required><x-field-error name="cidr"/></div>
                <div class="field"><label for="name">Nama</label><input class="input" id="name" name="name" value="{{ old('name') }}" placeholder="CCTV Pit A"></div>
                <div class="row">
                    <div class="field"><label for="site_id">Site</label><select class="input" id="site_id" name="site_id"><option value="">–</option>@foreach($sites as $st)<option value="{{ $st->id }}">{{ $st->code }}</option>@endforeach</select></div>
                    <div class="field"><label for="vlan">VLAN</label><input class="input" type="number" min="1" max="4094" id="vlan" name="vlan" value="{{ old('vlan') }}"></div>
                </div>
                <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Tambah</button></div>
            </form>
        </div></section>
        @endcan
        <section class="card"><div class="hd"><h2>Pergantian MAC 24 jam</h2></div><div class="bd flush">
            @if($changes->isEmpty())<div class="empty">Tidak ada.</div>@else
            <table class="t"><tbody>
                @foreach($changes as $c)
                    <tr><td class="mono" style="font-size:12.5px"><a href="{{ route('ipam.search', ['q' => $c->ip]) }}">{{ $c->ip }}</a><div class="muted">{{ $c->old_mac }} → {{ $c->new_mac }}</div></td>
                        <td class="right nowrap" style="font-size:12.5px">{{ \Illuminate\Support\Carbon::parse($c->at)->format('H:i') }}<div class="muted">{{ $c->reporter }}</div></td></tr>
                @endforeach
            </tbody></table>@endif
            <p class="muted" style="font-size:12px;margin:0;padding:10px 14px">Satu kali berganti biasanya wajar (DHCP / ganti perangkat). Konflik baru dinyatakan bila dua MAC bergantian berulang.</p>
        </div></section>
    </div>
</div>
@endsection
