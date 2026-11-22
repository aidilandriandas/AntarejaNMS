<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Layar NOC · ANTAREJA NMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/brand/favicon-32.png">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/css/noc.css', 'resources/js/noc.js'])
</head>
<body class="noc-body">
<div class="noc" data-noc data-url="{{ route('noc.data') }}" data-topo-url="{{ route('topology.data') }}" data-live-url="{{ route('topology.live') }}" data-login="{{ route('login') }}">
    <header class="n-head n-panel">
        <a class="brand" href="{{ route('dashboard') }}" title="Kembali ke aplikasi"><img class="mark" src="/brand/logo.png" alt="" width="34" height="34"><span><b>ANTAREJA NOC</b><small>NETWORK OPERATIONS CENTER</small></span></a>
        <div class="n-alarm ok" data-alarm role="status" aria-live="polite"><x-icon name="shield"/><span>Memuat…</span></div>
        <span class="n-spacer"></span>
        <span class="n-live" data-live title="Data diperbarui tiap 15 detik"><i></i><span>LIVE</span></span>
        <button type="button" class="n-btn" data-sound title="Bunyi saat ada problem High/Disaster baru"><x-icon name="volume-x"/><span>SUARA MATI</span></button>
        <select class="n-select" data-site aria-label="Filter site">
            <option value="">SEMUA SITE</option>
            @foreach($sites as $s)<option value="{{ $s->id }}">{{ $s->code }} — {{ $s->name }}</option>@endforeach
        </select>
        <div class="n-clock"><b data-clock>--.--.--</b><small data-date></small></div>
        <button type="button" class="n-btn" data-fs title="Layar penuh (F)" aria-label="Layar penuh"><x-icon name="expand"/></button>
    </header>

    <section class="n-left">
        <div class="n-panel n-kpi" data-k-avail><div class="lbl">Ketersediaan</div><div class="val">–<small>%</small></div><div class="meter"><span></span></div><div class="sub"></div></div>
        <div class="n-duo">
            <div class="n-panel n-kpi ok" data-k-up><div class="lbl">Online</div><div class="val">–</div></div>
            <div class="n-panel n-kpi" data-k-down><div class="lbl">Down</div><div class="val">–</div></div>
        </div>
        <div class="n-panel"><h2>Problem belum ACK</h2><ul class="n-sev" data-sev></ul></div>
        <div class="n-panel n-grow"><h2>Port tersibuk</h2><ul class="n-list n-ports" data-ports></ul></div>
    </section>

    <section class="n-center n-panel">
        <h2><span data-view-title>Dinding status</span>
            <span class="n-tabs" role="tablist">
                <button type="button" data-view="wall">DINDING</button><button type="button" data-view="map">TOPOLOGI</button>@if($hasGeo)<button type="button" data-view="geo">PETA</button>@endif<button type="button" data-view="auto" title="Bergantian tiap 30 detik (dinding → topologi{{ $hasGeo ? ' → peta' : '' }})">OTOMATIS ↻</button>
            </span></h2>
        <div class="n-views">
            <div class="n-view" data-v="wall"><div class="n-wall" data-wall></div></div>
            <div class="n-view hidden" data-v="map"><div class="n-map" data-map></div></div>
            @if($hasGeo)<div class="n-view hidden" data-v="geo"><div class="n-geo" data-geo data-url="{{ route('map.data') }}" data-pos-url="{{ route('map.position') }}" data-layers='@json($mapLayers)'></div></div>@endif
            <div class="n-legend"><i class="ok"></i>Normal <i class="problem"></i>Ada problem <i class="down"></i>Down <i class="impacted"></i>Terdampak induk <i class="maintenance"></i>Maintenance <i class="unknown"></i>Menunggu</div>
        </div>
    </section>

    <section class="n-right">
        <div class="n-panel n-grow2"><h2>Problem aktif <b data-prob-n></b></h2><ul class="n-list" data-problems></ul></div>
        <div class="n-panel n-grow"><h2>Kejadian 1 jam <b data-ev-n></b></h2><ul class="n-list" data-events></ul></div>
    </section>
</div>
<div class="n-overlay" data-overlay hidden><div><b>Sesi berakhir</b>Login ulang (centang "Ingat saya") untuk melanjutkan layar NOC.<br><br><a href="{{ route('login') }}">Login</a></div></div>
</body>
</html>
