<!doctype html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="refresh" content="60">
    <title>Status jaringan{{ $scope ? ' ' . $scope->code : '' }} | ANTAREJA</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css'])
</head>
<body class="status-page">
@php($pct = fn ($v) => $v === null ? 'tidak ada' : number_format(floor($v * 100) / 100, 2, ',', '.') . '%')
@php($nowAvail = ($c->up + $c->down) ? $c->up / ($c->up + $c->down) * 100 : null)
@php($bySite = $sla['sites'])
<header class="sp-head">
    <div class="brand" style="padding:0;border:0"><img class="mark" src="/brand/logo.png" alt="" width="34" height="34"><span><b>ANTAREJA</b><small>STATUS JARINGAN</small></span></div>
    <div class="sp-meta"><b>{{ $scope ? $scope->code . ' (' . $scope->name . ')' : 'Semua site' }}</b><small>Diperbarui {{ now()->format('d/m/Y H.i') }}, otomatis tiap menit</small></div>
</header>
<main class="sp-main">
    <div class="sp-banner {{ $c->down ? 'bad' : 'good' }}">
        @if($c->down)<b>{{ $c->down }} perangkat sedang down</b> dari {{ $c->total }} perangkat aktif.@else<b>Semua perangkat online</b> ({{ $c->up }} dari {{ $c->total }} aktif).@endif
    </div>
    <div class="kpis">
        <div class="kpi {{ $nowAvail === null ? '' : ($c->down ? 'crit' : 'ok') }}"><div class="lbl">Online sekarang</div><div class="val">{{ $c->up }}<small> / {{ $c->total }}</small></div><div class="note">{{ $pct($nowAvail) }}</div></div>
        <div class="kpi {{ ($sla['total']['availability'] ?? 100) >= $target ? 'ok' : 'warn' }}"><div class="lbl">Ketersediaan bulan ini</div><div class="val">{{ $pct($sla['total']['availability']) }}</div><div class="note">target {{ $pct($target) }}, maintenance tidak dihitung</div></div>
        <div class="kpi"><div class="lbl">Gangguan bulan ini</div><div class="val">{{ $sla['total']['incidents'] }}</div><div class="note">total downtime {{ \App\Support\Fmt::duration((int) $sla['total']['down']) }}</div></div>
        <div class="kpi {{ ($problems['disaster'] ?? 0) + ($problems['high'] ?? 0) ? 'crit' : '' }}"><div class="lbl">Problem aktif</div><div class="val">{{ $problems->sum() }}</div>
            <div class="note">{{ collect(['disaster' => 'Disaster', 'high' => 'High', 'average' => 'Average', 'warning' => 'Warning', 'info' => 'Info'])->filter(fn ($v, $k) => ($problems[$k] ?? 0) > 0)->map(fn ($v, $k) => $v . ' ' . $problems[$k])->implode(', ') ?: 'tidak ada' }}</div></div>
    </div>

    <section class="card">
        <div class="hd"><h2>Per site</h2><span class="sub">ketersediaan dihitung sejak awal bulan</span></div>
        <div class="bd flush"><div class="table-wrap"><table class="t">
            <thead><tr><th>Site</th><th>Status sekarang</th><th class="num">Online</th><th class="num">Gangguan</th><th class="num">Ketersediaan</th></tr></thead>
            <tbody>
            @foreach($sites as $s)
                @php($a = $bySite[$s->code] ?? null)
                <tr>
                    <td class="name"><b>{{ $s->code }}</b><small>{{ $s->name }}</small></td>
                    <td>@if($s->down)<span class="badge crit">{{ $s->down }} down</span>@else<span class="badge ok">Normal</span>@endif</td>
                    <td class="num">{{ $s->up }} / {{ $s->total }}</td>
                    <td class="num">{{ $a['incidents'] ?? 0 }}</td>
                    <td class="num" style="font-weight:600;color:var(--{{ ($a['availability'] ?? 100) >= $target ? 'ok' : 'crit' }})">{{ $pct($a['availability'] ?? null) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div></div>
    </section>

    @if($services->isNotEmpty())
    <section class="card" style="margin-top:16px">
        <div class="hd"><h2>Layanan</h2><span class="sub">ketersediaan dihitung sejak awal bulan</span></div>
        <div class="bd flush"><div class="table-wrap"><table class="t">
            <thead><tr><th>Layanan</th><th>Status sekarang</th><th class="num">Gangguan</th><th class="num">Target</th><th class="num">Ketersediaan</th></tr></thead>
            <tbody>
            @foreach($services as $sv)
                @php([$st] = \App\Services\ServiceSla::liveStatus($sv))
                @php([$stLabel, $stTone] = \App\Services\ServiceSla::STATES[$st])
                @php($r = $svcSla[$sv->id] ?? null)
                <tr>
                    <td class="name"><b>{{ $sv->name }}</b>@if($sv->description)<small>{{ $sv->description }}</small>@endif</td>
                    <td><span class="badge {{ $stTone }}">{{ $stLabel }}</span></td>
                    <td class="num">{{ $r['count'] ?? 0 }}</td>
                    <td class="num">{{ $pct($sv->target()) }}</td>
                    <td class="num" style="font-weight:600;color:var(--{{ ($r['availability'] ?? 100) >= $sv->target() ? 'ok' : 'crit' }})">{{ $pct($r['availability'] ?? null) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div></div>
    </section>
    @endif

    @if($link->show_devices && $down->isNotEmpty())
    <section class="card" style="margin-top:16px">
        <div class="hd"><h2>Perangkat down</h2></div>
        <div class="bd flush"><ul class="feed">
            @foreach($down as $d)
            <li><span class="ic crit"><x-icon name="arrow-down"/></span><div class="msg"><b>{{ $d->name }}</b><small>{{ \App\Models\Device::CATEGORIES[$d->category] ?? $d->category }}{{ $d->site ? ', ' . $d->site->code : '' }}</small></div><time></time></li>
            @endforeach
        </ul></div>
    </section>
    @endif
    <p class="sp-foot">Halaman hanya-baca dari ANTAREJA NMS, tautan ini rahasia, jangan dibagikan ke luar perusahaan @if($link->expires_at), berlaku sampai {{ \Illuminate\Support\Carbon::parse($link->expires_at)->format('d/m/Y') }}@endif</p>
</main>
</body>
</html>
