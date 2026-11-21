@extends('layouts.app')
@section('title', 'Sensor')
@section('content')
@php($cfg = \App\Http\Controllers\SensorController::TABS[$tab])
<div class="page-head">
    <div><h1>Sensor</h1><p>Dibaca tiap putaran SNMP dari profil perangkat. Perangkat dengan nilai di luar ambang tampil paling atas dan menjadi problem.</p></div>
    <div class="seg" role="tablist">
        @foreach(\App\Http\Controllers\SensorController::TABS as $k => $t)
            <a class="btn sm {{ $tab === $k ? 'primary' : '' }}" href="{{ route('sensors.index', ['tab' => $k] + request()->only('site')) }}">{{ $t['label'] }} ({{ $counts[$k] }})</a>
        @endforeach
    </div>
</div>
<section class="card">
    <form class="filters" method="get">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <select class="input" name="site" aria-label="Site" onchange="this.form.submit()"><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach</select>
    </form>
    <div class="bd flush">
    @if($rows->isEmpty())
        <div class="empty">Belum ada perangkat dengan sensor {{ strtolower($cfg['label']) }}. Sensor muncul setelah SNMP aktif dan profil perangkat terdeteksi (±5 menit).</div>
    @else
        <div class="table-wrap"><table class="t pivot">
            <thead><tr><th>Perangkat</th>@foreach($cfg['cols'] as [$title])<th class="right">{{ $title }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($rows as $r)
                <tr class="{{ $r['worst'] === 'crit' ? 'is-down' : '' }}">
                    <td class="name"><a href="{{ route('devices.show', $r['device']) }}">{{ $r['device']->name }}</a><small>{{ $r['device']->site?->code ?? '–' }} · {{ $r['device']->host }}</small></td>
                    @foreach($r['cells'] as $c)
                        <td class="c" title="{{ $c['label'] ?? '' }}{{ ($c['n'] ?? 1) > 1 ? ' · ' . $c['n'] . ' sensor (ditampilkan yang terburuk)' : '' }}">
                            @if(! $c)<span class="muted">–</span>
                            @elseif($c['status'] === 'ok'){{ $c['text'] }}
                            @else<span class="badge plain {{ $c['status'] }}">{{ $c['text'] }}</span>@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
    </div>
</section>
<p class="muted" style="font-size:12.5px">Ambang bawaan: sinyal ≤ −70 dBm peringatan, ≤ −78 dBm kritis · CCQ/kualitas ≤ 70% / 50% · baterai ≤ 50% / 20% · cadangan ≤ 15 / 5 menit · beban UPS ≥ 80% / 95% · CPU ≥ 80% / 95% · memori & disk ≥ 85% / 95% · suhu ≥ 60 °C / 75 °C (CPU 75 / 90).</p>
@endsection
