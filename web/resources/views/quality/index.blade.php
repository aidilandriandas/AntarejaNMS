@extends('layouts.app')
@section('title', 'Kualitas jaringan')
@php($ms = fn ($v) => \App\Support\Fmt::ms($v))
@section('content')
<div class="page-head">
    <div><h1>Kualitas jaringan</h1><p>Dari ping tiap menit (3 paket). p95 = 95% waktu latensi di bawah nilai ini. Jitter = variasi latensi antarpaket — penting untuk CCTV & suara. Terburuk di atas.</p></div>
    <div class="seg" role="tablist">@foreach(\App\Http\Controllers\QualityController::RANGES as $k => $v)<a class="btn sm {{ $range === $k ? 'primary' : '' }}" href="{{ route('quality.index', ['range' => $k] + request()->only('site')) }}">{{ $v }}</a>@endforeach</div>
</div>
<section class="card">
    <form class="filters" method="get"><input type="hidden" name="range" value="{{ $range }}">
        <select class="input" name="site" aria-label="Site" data-autosubmit><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach</select></form>
    <div class="bd flush">
    @if($rows->isEmpty() || ! $rows->contains(fn ($r) => $r['loss'] !== null))<div class="empty">Belum ada data ping untuk rentang ini.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Perangkat</th><th>Site</th><th class="num">Rata-rata</th><th class="num">p95</th><th class="num">Maks</th><th class="num">Jitter</th><th class="num">Loss</th><th class="num">Ping OK</th><th></th></tr></thead>
        <tbody>
        @foreach($rows as $r)
            @php($d = $r['device'])
            <tr class="{{ $r['tone'] === 'crit' ? 'is-down' : ($r['tone'] === 'muted' ? 'is-off' : '') }}">
                <td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a><small class="mono">{{ $d->host }}{{ isset($d->thresholds['latency_ms']) ? ' · ambang ' . $d->thresholds['latency_ms'] . ' ms' : '' }}</small></td>
                <td>{{ $d->site?->code ?? '–' }}</td>
                <td class="num">{{ $ms($r['avg']) }}</td>
                <td class="num" style="{{ ($r['p95'] ?? 0) > $r['lat_th'] ? 'color:var(--warn);font-weight:600' : '' }}">{{ $ms($r['p95']) }}</td>
                <td class="num muted">{{ $ms($r['max']) }}</td>
                <td class="num">{{ $ms($r['jitter']) }}</td>
                <td class="num" style="{{ ($r['loss'] ?? 0) > 1 ? 'color:var(--warn);font-weight:600' : '' }}">{{ $r['loss'] === null ? '–' : number_format($r['loss'], $r['loss'] < 10 ? 2 : 1, ',', '.') . '%' }}</td>
                <td class="num">{{ $r['up'] === null ? '–' : number_format($r['up'], $r['up'] >= 99.95 ? 2 : 2, ',', '.') . '%' }}</td>
                <td>@if($r['tone'] !== 'muted')<span class="badge {{ $r['tone'] }}">{{ ['ok' => 'baik', 'warn' => 'perlu dicek', 'crit' => 'buruk'][$r['tone']] }}</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>@endif
    </div>
</section>
@endsection
