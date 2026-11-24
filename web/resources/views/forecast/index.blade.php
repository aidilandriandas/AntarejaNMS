@extends('layouts.app')
@section('title', 'Prediksi habis')
@php($F = \App\Http\Controllers\ForecastController::class)
@php($num = fn ($v, $d = 1) => str_replace('.', ',', (string) round((float) $v, $d)))
@section('content')
<div class="page-head"><div><h1>Prediksi habis</h1>
    <p>Perkiraan kapan disk penuh (tren 7 hari terakhir) dan kapan bahan bakar genset habis (sejak isi ulang terakhir), dihitung tiap jam. Disk penuh dalam 7 hari atau bahan bakar habis dalam 24 jam menjadi problem. Untuk port, lihat <a href="{{ route('capacity.index') }}">Kapasitas port</a>.</p></div></div>
<section class="card"><div class="bd flush">
    @if($rows->isEmpty())<div class="empty">Belum ada prediksi. Butuh sensor disk (SNMP atau exporter) atau bahan bakar (Modbus) dengan data minimal 6 jam.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Perangkat</th><th>Sensor</th><th class="num">Sekarang</th><th class="num">Laju</th><th>Perkiraan</th><th class="num">Kecocokan tren</th></tr></thead>
        <tbody>
        @foreach($rows as $f)@php($tone = $F::tone($f))
            <tr><td class="name"><a href="{{ route('devices.show', $f->device_id) }}">{{ $f->device }}</a><small>{{ $f->site }}</small></td>
                <td>{{ $f->label }}</td>
                <td class="num">{{ $num($f->current) }}{{ $f->class === 'disk' ? '%' : '' }}</td>
                <td class="num nowrap">@if($f->class === 'fuel'){{ $num(-$f->per_day / 24) }} per jam @else{{ $num($f->per_day) }}% per hari @endif</td>
                <td class="nowrap">@if($f->eta)<span class="badge {{ $tone }} plain">{{ $f->class === 'disk' ? 'penuh' : 'habis' }} dalam {{ $F::left($f->eta) }}</span><div class="muted" style="font-size:12px">{{ \Illuminate\Support\Carbon::parse($f->eta)->format('d/m/Y H:i') }}</div>@else<span class="muted">{{ $F::left(null) }}</span>@endif</td>
                <td class="num">{{ $f->r2 !== null ? $num($f->r2 * 100, 0) . '%' : '' }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    <p class="hint muted" style="margin:0;padding:var(--space-sm) var(--space-md)">Kecocokan tren (R²) rendah berarti pemakaian naik turun tidak teratur; prediksi disk dengan kecocokan di bawah 50% tidak dijadikan problem.</p>@endif
</div></section>
@endsection
