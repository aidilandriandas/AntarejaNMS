@extends('layouts.app')
@section('title', 'Discovery')
@section('content')
<div class="page-head">
    <div><div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / Discovery</div><h1>Discovery jaringan</h1>
        <p>Pindai subnet untuk menemukan perangkat yang belum terdaftar: ping, lalu SNMP GET grup <i>system</i> (sysName, sysDescr, sysObjectID). Hanya membaca, tidak ada perubahan pada perangkat. Hasilnya berupa usulan; Anda yang memilih mana yang ditambahkan.</p></div>
</div>
<div class="grid g-main">
<section class="card"><div class="hd"><h2>Riwayat pemindaian</h2></div>
    <div class="bd flush">
    @if($scans->isEmpty())<div class="empty">Belum ada pemindaian. Mulai dari formulir di samping.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Subnet</th><th>Status</th><th class="num">Ditemukan</th><th class="num">Baru</th><th>Diminta</th><th></th></tr></thead>
        <tbody>
        @foreach($scans as $s)
            @php([$label, $tone] = \App\Http\Controllers\DiscoveryController::STATUS[$s->status])
            <tr>
                <td class="name"><a class="mono" href="{{ route('discovery.show', $s->id) }}">{{ $s->cidr }}</a><small>{{ $s->site_code ? 'site ' . $s->site_code : 'tanpa site' }}{{ $s->communities_enc ? '' : ', tanpa SNMP' }}</small></td>
                <td><span class="badge {{ $tone }}">{{ $label }}</span>@if($s->status === 'running')<small class="muted"> {{ $s->total ? round($s->progress / $s->total * 100) : 0 }}%</small>@endif</td>
                <td class="num">{{ $s->found }}</td>
                <td class="num">@if($s->new_count)<b style="color:var(--accent)">{{ $s->new_count }}</b>@else<span class="muted">0</span>@endif</td>
                <td class="muted">{{ \Illuminate\Support\Carbon::parse($s->created_at)->diffForHumans() }}<small>{{ $s->user_name }}</small></td>
                <td><a class="btn sm" href="{{ route('discovery.show', $s->id) }}">Buka</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>

<section class="card"><div class="hd"><h2>Pindai subnet</h2></div><div class="bd">
    <form method="post" action="{{ route('discovery.store') }}" class="stack">@csrf
        <div class="field"><label for="cidr">Subnet (CIDR)</label>
            <input class="input mono @error('cidr') is-err @enderror" id="cidr" name="cidr" value="{{ old('cidr') }}" placeholder="10.20.30.0/24" required autocomplete="off">
            <x-field-error name="cidr"/><div class="hint">Maksimal /22 (1.022 alamat). /24 selesai ± 1 menit.</div></div>
        <div class="field"><label for="site_id">Site untuk hasil</label>
            <select class="input" id="site_id" name="site_id"><option value="">— pilih nanti —</option>
                @foreach($sites as $st)<option value="{{ $st->id }}" @selected(old('site_id') == $st->id)>{{ $st->code }} ({{ $st->name }})</option>@endforeach</select></div>
        <div class="field"><label for="communities">Community SNMP v2c</label>
            <textarea class="input mono @error('communities') is-err @enderror" id="communities" name="communities" rows="3" placeholder="satu per baris (maks. 5)" autocomplete="off" spellcheck="false">{{ old('communities') }}</textarea>
            <x-field-error name="communities"/><div class="hint">Dicoba berurutan; disimpan terenkripsi dan dihapus otomatis 7 hari setelah pemindaian selesai. Kosongkan untuk ping saja.</div></div>
        <div class="field"><label for="snmp_port">Port SNMP</label><input class="input" type="number" id="snmp_port" name="snmp_port" value="{{ old('snmp_port', 161) }}" min="1" max="65535" style="max-width:120px"></div>
        <label class="check"><input type="checkbox" name="snmp_all" value="1" @checked(old('snmp_all'))><span>Coba SNMP juga ke alamat yang tidak menjawab ping<small>Untuk perangkat yang memblok ICMP (firewall). Pemindaian jadi lebih lama.</small></span></label>
        <div><button class="btn primary"><x-icon name="search"/>Mulai pindai</button></div>
    </form>
</div></section>
</div>
@endsection
