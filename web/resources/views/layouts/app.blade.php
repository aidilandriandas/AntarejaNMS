@php
    $u = auth()->user();
    $downCount = \App\Models\Device::where('enabled', true)->where('status', 'down')->count();
    $probCount = \App\Models\Problem::whereNull('resolved_at')->whereNull('acked_at')->whereNull('suppressed')->count();
    $nav = fn (string $pattern) => request()->routeIs($pattern) ? 'on' : '';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Ringkasan') · ANTAREJA NMS</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="app">
    <aside class="side" aria-label="Navigasi utama">
        <a class="brand" href="{{ route('dashboard') }}"><span class="mark">A</span><span><b>ANTAREJA</b><small>NETWORK MONITORING</small></span></a>
        <nav class="nav">
            <a href="{{ route('dashboard') }}" class="{{ $nav('dashboard') }}"><x-icon name="home"/>Ringkasan</a>
            <a href="{{ route('noc') }}"><x-icon name="monitor"/>Layar NOC</a>
            <a href="{{ route('problems.index') }}" class="{{ $nav('problems.*') }}"><x-icon name="alert"/>Problem
                @if($probCount)<span class="count" title="{{ $probCount }} problem belum di-ACK">{{ $probCount }}</span>@endif</a>
            <div class="grp">Pemantauan</div>
            <a href="{{ route('devices.index') }}" class="{{ $nav('devices.*') }}"><x-icon name="server"/>Perangkat
                @if($downCount)<span class="count" title="{{ $downCount }} perangkat down">{{ $downCount }}</span>@endif</a>
            <a href="{{ route('topology.index') }}" class="{{ $nav('topology.*') }}"><x-icon name="share"/>Topologi</a>
            <a href="{{ route('sensors.index') }}" class="{{ $nav('sensors.*') }}"><x-icon name="gauge"/>Sensor</a>
            <a href="{{ route('events.index') }}" class="{{ $nav('events.*') }}"><x-icon name="pulse"/>Kejadian</a>
            <a href="{{ route('sites.index') }}" class="{{ $nav('sites.*') }}"><x-icon name="map-pin"/>Site</a>
            <a href="{{ route('maintenance.index') }}" class="{{ $nav('maintenance.*') }}"><x-icon name="wrench"/>Maintenance</a>
            @can('admin')
                <div class="grp">Administrasi</div>
                <a href="{{ route('channels.index') }}" class="{{ $nav('channels.*') }}"><x-icon name="bell"/>Notifikasi</a>
                <a href="{{ route('users.index') }}" class="{{ $nav('users.*') }}"><x-icon name="users"/>Pengguna</a>
                <a href="{{ route('audit.index') }}" class="{{ $nav('audit.*') }}"><x-icon name="audit"/>Jejak audit</a>
            @endcan
        </nav>
        <div class="side-foot">ANTAREJA NMS v{{ config('antareja.version') }}</div>
    </aside>
    <div class="main">
        <header class="top">
            <button type="button" class="iconbtn menu-toggle" data-menu aria-label="Buka menu"><x-icon name="menu"/></button>
            <form class="search" action="{{ route('devices.index') }}" role="search">
                <x-icon name="search"/>
                <input type="search" name="q" value="{{ request()->routeIs('devices.index') ? request('q') : '' }}" placeholder="Cari perangkat, IP, sysName…" aria-label="Cari perangkat">
            </form>
            <span class="spacer"></span>
            <button type="button" class="iconbtn" data-theme-toggle title="Tema terang/gelap" aria-label="Ganti tema"><x-icon name="moon"/></button>
            <a class="who" href="{{ route('profile') }}" title="Profil & password">
                <span class="av">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                <div><b>{{ $u->name }}</b><small>{{ $u->roleLabel() }}</small></div>
            </a>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="iconbtn" title="Keluar" aria-label="Keluar"><x-icon name="logout"/></button></form>
        </header>
        <main class="content">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>
