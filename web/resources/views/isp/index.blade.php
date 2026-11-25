@extends('layouts.app')
@section('title', 'SLA link ISP')
@php($S = \App\Services\IspSla::class)
@php($V = \App\Models\IspLink::VERDICTS)
@section('content')
<div class="page-head">
    <div><h1>SLA link ISP</h1>
        <p>Ketersediaan, latensi, jitter, dan packet loss tiap link internet atau Metro-E dari ping ANTAREJA. Gangguan = ping gagal atau packet loss di atas ambang link. Pilih link untuk tren harian dan daftar insiden.</p></div>
    @can('manage-devices')<div class="actions"><a class="btn" href="{{ route('isp.manage') }}"><x-icon name="edit"/>Atur link</a></div>@endcan
</div>
<section class="card" style="margin-bottom:16px">@include('isp._period')</section>

@if($rows->isEmpty())
<section class="card"><div class="bd"><div class="empty">Belum ada link ISP. @can('manage-devices')<a href="{{ route('isp.manage') }}">Tambahkan link</a>: pilih perangkat yang di-ping untuk link itu (gateway ISP, router ujung, atau IP di sisi ISP).@else Minta engineer menambahkan link ISP.@endcan</div></div></section>
@else
<div class="kpis">
    <div class="kpi"><div class="lbl">Link</div><div class="val">{{ $rows->count() }}</div><div class="note">dipantau</div></div>
    @php($ok = $rows->where(fn ($x) => $x['r']['verdict'] === 'compliant')->count())
    <div class="kpi {{ $ok === $rows->count() ? 'ok' : 'warn' }}"><div class="lbl">Memenuhi SLA</div><div class="val">{{ $ok }}</div><div class="note">dari {{ $rows->count() }} link</div></div>
    <div class="kpi"><div class="lbl">Insiden</div><div class="val">{{ $rows->sum(fn ($x) => count($x['r']['incidents'])) }}</div><div class="note">semua link</div></div>
</div>
<section class="card"><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Link</th><th class="num">Ketersediaan</th><th>Status SLA</th><th class="num">Gangguan</th><th class="num">Latensi</th><th class="num">Jitter</th><th class="num">Loss</th><th class="num">Insiden</th></tr></thead><tbody>
    @foreach($rows as ['link' => $l, 'r' => $r])
        <tr><td class="name"><a href="{{ route('isp.show', [$l] + request()->only('periode', 'dari', 'sampai')) }}">{{ $l->name }}</a><small>{{ $l->provider }}{{ $l->provider ? ', ' : '' }}{{ $l->device?->name }}</small></td>
            <td class="num">{{ $r['availability'] === null ? 'tanpa data' : $S::num($r['availability'], 2) . '%' }}<div class="muted" style="font-size:12px">target {{ $S::num($l->target_pct) }}%</div></td>
            <td>@if($r['verdict'])<span class="badge {{ $V[$r['verdict']][1] }} plain">{{ $V[$r['verdict']][0] }}</span>@endif</td>
            <td class="num nowrap">{{ $r['downtime_s'] ? $S::dur($r['downtime_s']) : '0' }}</td>
            <td class="num {{ $r['latency'] !== null && $r['latency'] > $l->latency_target_ms ? 'txt-warn' : '' }}">{{ $r['latency'] !== null ? $S::num($r['latency']) . ' ms' : '' }}</td>
            <td class="num {{ $r['jitter'] !== null && $r['jitter'] > $l->jitter_target_ms ? 'txt-warn' : '' }}">{{ $r['jitter'] !== null ? $S::num($r['jitter']) . ' ms' : '' }}</td>
            <td class="num {{ $r['loss'] !== null && $r['loss'] > $l->loss_target_pct ? 'txt-warn' : '' }}">{{ $r['loss'] !== null ? $S::num($r['loss']) . '%' : '' }}</td>
            <td class="num">{{ count($r['incidents']) }}</td></tr>
    @endforeach
    </tbody></table></div></div></section>
@endif
@endsection
