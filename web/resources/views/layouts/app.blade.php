@php
    $u = auth()->user();
    $downCount = \App\Models\Device::where('enabled', true)->where('status', 'down')->count();
    $probCount = \App\Models\Problem::whereNull('resolved_at')->whereNull('acked_at')->whereNull('suppressed')->count();
    $limited = \App\Support\SiteScope::current() !== null;
    $incCount = $limited ? 0 : \App\Models\Incident::where('status', 'open')->count();
    [$sysStale, $sysAlerts, $sysBeat] = \App\Http\Controllers\SystemController::brief();
    $sysIssue = $sysStale || $sysAlerts > 0;
    $nav = fn (string $pattern) => request()->routeIs($pattern) ? 'on' : '';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Ringkasan') | ANTAREJA NMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/brand/favicon-32.png">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="app">
    <aside class="side" aria-label="Navigasi utama">
        <a class="brand" href="{{ route('dashboard') }}"><img class="mark" src="/brand/logo.png" alt="" width="34" height="34"><span><b>ANTAREJA</b><small>NETWORK MONITORING</small></span></a>
        <nav class="nav">
            <a href="{{ route('dashboard') }}" class="{{ $nav('dashboard') }}"><x-icon name="home"/>Ringkasan</a>
            @if($limited)
            <a href="{{ route('problems.index') }}" class="{{ $nav('problems.*') }}"><x-icon name="alert"/>Problem
                @if($probCount)<span class="count" title="{{ $probCount }} problem belum di-ACK">{{ $probCount }}</span>@endif</a>
            <a href="{{ route('devices.index') }}" class="{{ $nav('devices.*') }}"><x-icon name="server"/>Perangkat
                @if($downCount)<span class="count" title="{{ $downCount }} perangkat down">{{ $downCount }}</span>@endif</a>
            <a href="{{ route('sensors.index') }}" class="{{ $nav('sensors.*') }}"><x-icon name="gauge"/>Sensor</a>
            <a href="{{ route('events.index') }}" class="{{ $nav('events.*') }}"><x-icon name="pulse"/>Kejadian</a>
            <a href="{{ route('help') }}" class="{{ $nav('help') }}"><x-icon name="info"/>Panduan</a>
            <div class="grp">Akses terbatas</div>
            <p class="nav-note">Akun ini hanya melihat site {{ \App\Models\Site::orderBy('code')->pluck('code')->implode(', ') }}.</p>
            @else
            <a href="{{ route('noc') }}"><x-icon name="monitor"/>Layar NOC</a>
            <a href="{{ route('problems.index') }}" class="{{ $nav('problems.*') }}"><x-icon name="alert"/>Problem
                @if($probCount)<span class="count" title="{{ $probCount }} problem belum di-ACK">{{ $probCount }}</span>@endif</a>
            <a href="{{ route('incidents.index') }}" class="{{ $nav('incidents.*') }}"><x-icon name="flag"/>Insiden
                @if($incCount)<span class="count" title="{{ $incCount }} insiden terbuka belum ditangani">{{ $incCount }}</span>@endif</a>
            <a href="{{ route('impact.index') }}" class="{{ $nav('impact.*') }}"><x-icon name="layers"/>Dampak</a>
            <a href="{{ route('runbooks.index') }}" class="{{ $nav('runbooks.*') }}"><x-icon name="list"/>Runbook</a>
            <div class="grp">Pemantauan</div>
            <a href="{{ route('devices.index') }}" class="{{ $nav('devices.*') }}"><x-icon name="server"/>Perangkat
                @if($downCount)<span class="count" title="{{ $downCount }} perangkat down">{{ $downCount }}</span>@endif</a>
            <a href="{{ route('topology.index') }}" class="{{ $nav('topology.*') }}"><x-icon name="share"/>Topologi</a>
            <a href="{{ route('routing.index') }}" class="{{ $nav('routing.*') }}"><x-icon name="layers"/>Routing</a>
            <a href="{{ route('wireless.index') }}" class="{{ $nav('wireless.*') }}"><x-icon name="antenna"/>Link radio</a>
            <a href="{{ route('vlans.index') }}" class="{{ $nav('vlans.*') }}"><x-icon name="grid"/>VLAN &amp; STP</a>
            <a href="{{ route('map.index') }}" class="{{ $nav('map.*') }}"><x-icon name="map-pin"/>Peta lokasi</a>
            <a href="{{ route('quality.index') }}" class="{{ $nav('quality.*') }}"><x-icon name="pulse"/>Kualitas jaringan</a>
            <a href="{{ route('checks.index') }}" class="{{ $nav('checks.*') }}"><x-icon name="check"/>Cek layanan</a>
            <a href="{{ route('flow.index') }}" class="{{ $nav('flow.*') }}"><x-icon name="chart"/>Flow trafik</a>
            <a href="{{ route('sensors.index') }}" class="{{ $nav('sensors.*') }}"><x-icon name="gauge"/>Sensor</a>
            <a href="{{ route('versions.index') }}" class="{{ $nav('versions.*') }}"><x-icon name="clock"/>Versi &amp; jam</a>
            <a href="{{ route('ipam.index') }}" class="{{ $nav('ipam.*') }}"><x-icon name="grid"/>IPAM</a>
            <a href="{{ route('cctv.index') }}" class="{{ $nav('cctv.*') }}"><x-icon name="camera"/>CCTV</a>
            <a href="{{ route('events.index') }}" class="{{ $nav('events.*') }}"><x-icon name="pulse"/>Kejadian</a>
            <a href="{{ route('anomalies.index') }}" class="{{ $nav('anomalies.*') }}"><x-icon name="wave"/>Anomali</a>
            <a href="{{ route('logs.index') }}" class="{{ $nav('logs.*') }}"><x-icon name="terminal"/>Log</a>
            <a href="{{ route('traps.index') }}" class="{{ $nav('traps.*') }}"><x-icon name="bell"/>Trap</a>
            <a href="{{ route('sites.index') }}" class="{{ $nav('sites.*') }}"><x-icon name="map-pin"/>Site</a>
            <a href="{{ route('triggers.index') }}" class="{{ $nav('triggers.*') }}"><x-icon name="bell"/>Trigger</a>
            @can('manage-devices')<a href="{{ route('templates.index') }}" class="{{ $nav('templates.*') }}"><x-icon name="layers"/>Templat</a>@endcan
            <a href="{{ route('maintenance.index') }}" class="{{ $nav('maintenance.*') }}"><x-icon name="wrench"/>Maintenance</a>
            <a href="{{ route('oncall.index') }}" class="{{ $nav('oncall.*') }}"><x-icon name="clock"/>Jadwal piket</a>
            <div class="grp">Laporan</div>
            <a href="{{ route('services.index') }}" class="{{ $nav('services.*') }}"><x-icon name="layers"/>SLA layanan</a>
            <a href="{{ route('reports.sla') }}" class="{{ $nav('reports.sla*') }}"><x-icon name="chart"/>SLA perangkat</a>
            <a href="{{ route('isp.index') }}" class="{{ $nav('isp.*') }}"><x-icon name="pulse"/>SLA link ISP</a>
            <a href="{{ route('reports.downtime') }}" class="{{ $nav('reports.downtime*') }}"><x-icon name="arrow-down"/>Down bulanan</a>
            <a href="{{ route('reports.incidents') }}" class="{{ $nav('reports.incidents*') }}"><x-icon name="flag"/>Insiden bulanan</a>
            <a href="{{ route('capacity.index') }}" class="{{ $nav('capacity.*') }}"><x-icon name="gauge"/>Kapasitas port</a>
            <a href="{{ route('forecast.index') }}" class="{{ $nav('forecast.*') }}"><x-icon name="clock"/>Prediksi habis</a>
            @can('admin')<a href="{{ route('reports.schedules') }}" class="{{ $nav('reports.schedules*') }}"><x-icon name="bell"/>Laporan terjadwal</a>@endcan
            <a href="{{ route('help') }}" class="{{ $nav('help') }}"><x-icon name="info"/>Panduan</a>
            @can('admin')
                <div class="grp">Administrasi</div>
                <a href="{{ route('channels.index') }}" class="{{ $nav('channels.*') }}"><x-icon name="bell"/>Notifikasi</a>
                <a href="{{ route('users.index') }}" class="{{ $nav('users.*') }}"><x-icon name="users"/>Pengguna</a>
                <a href="{{ route('backups.index') }}" class="{{ $nav('backups.*') }}"><x-icon name="database"/>Backup</a>
                <a href="{{ route('transfer.index') }}" class="{{ $nav('transfer.*') }}"><x-icon name="download"/>Ekspor & impor</a>
                <a href="{{ route('status.links') }}" class="{{ $nav('status.*') }}"><x-icon name="share"/>Tautan status</a>
                <a href="{{ route('collectors.index') }}" class="{{ $nav('collectors.*') }}"><x-icon name="server"/>Collector site</a>
                <a href="{{ route('apitokens.index') }}" class="{{ $nav('apitokens.*') }}"><x-icon name="key"/>Token API</a>
                <a href="{{ route('audit.index') }}" class="{{ $nav('audit.*') }}"><x-icon name="audit"/>Jejak audit</a>
                <a href="{{ route('system.index') }}" class="{{ $nav('system.*') }}"><x-icon name="pulse"/>Kesehatan sistem
                    @if($sysIssue)<span class="count" title="Ada masalah sistem">!</span>@endif</a>
            @endcan
            @endif
        </nav>
        <div class="side-foot">ANTAREJA NMS v{{ config('antareja.version') }}</div>
    </aside>
    <div class="main">
        <header class="top">
            <button type="button" class="iconbtn menu-toggle" data-menu aria-label="Buka menu"><x-icon name="menu"/></button>
            <form class="search" action="{{ route('devices.index') }}" role="search">
                <x-icon name="search"/>
                <input type="search" name="q" value="{{ request()->routeIs('devices.index') ? request('q') : '' }}" placeholder="Cari perangkat, IP, sysName" aria-label="Cari perangkat">
            </form>
            <span class="spacer"></span>
            <a class="iconbtn" href="{{ route('help') }}" title="Panduan operator" aria-label="Panduan operator"><span aria-hidden="true" style="font:700 15px var(--sans)">?</span></a>
            <button type="button" class="iconbtn" data-theme-toggle title="Tema terang/gelap" aria-label="Ganti tema"><x-icon name="moon"/></button>
            <a class="who" href="{{ route('profile') }}" title="Profil & password">
                <span class="av">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                <div><b>{{ $u->name }}</b><small>{{ $u->roleLabel() }}</small></div>
            </a>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="iconbtn" title="Keluar" aria-label="Keluar"><x-icon name="logout"/></button></form>
        </header>
        <main class="content">
            @if($sysStale)
                <div class="alert error sys-banner" role="alert"><x-icon name="alert"/><div><b>Data tidak diperbarui:</b> collector ANTAREJA tidak melapor {{ $sysBeat ? 'sejak ' . $sysBeat->diffForHumans() : '' }}. Status perangkat di layar ini mungkin sudah usang.
                    @can('admin')<a href="{{ route('system.index') }}">Buka kesehatan sistem</a>, atau jalankan <code>./antareja.sh cek</code> di server.@else Hubungi admin NOC.@endcan</div></div>
            @elseif($sysAlerts && $u->isAdmin() && ! request()->routeIs('system.*'))
                <div class="alert warn sys-banner"><x-icon name="alert"/><div>Ada {{ $sysAlerts }} masalah sistem ANTAREJA (mis. backup/disk/notifikasi). <a href="{{ route('system.index') }}">Lihat kesehatan sistem</a></div></div>
            @endif
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>
