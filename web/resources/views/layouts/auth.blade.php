<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · ANTAREJA NMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/brand/favicon-32.png">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="auth">
    <section class="hero">
        <div class="brand" style="padding:0;border:0;position:relative;z-index:1"><img class="mark" src="/brand/logo.png" alt="" width="34" height="34"><span><b>ANTAREJA</b><small>NETWORK MONITORING</small></span></div>
        <div style="position:relative;z-index:1">
            <h1>Pantau jaringan & infrastruktur tambang dari satu layar.</h1>
            <p>Ketersediaan, latensi, reboot, dan trafik setiap switch, radio PTP, NVR, server, dan UPS — diperiksa tiap menit.</p>
            <ul>
                <li>Ping 1 menit & deteksi down ±2 menit</li>
                <li>SNMP v1/v2c/v3: uptime, port & trafik</li>
                <li>Riwayat kejadian & grafik hingga 400 hari</li>
            </ul>
        </div>
        <small>ANTAREJA NMS v{{ config('antareja.version') }}</small>
    </section>
    <section class="panel"><div class="box">@yield('content')</div></section>
</div>
</body>
</html>
