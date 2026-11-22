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
