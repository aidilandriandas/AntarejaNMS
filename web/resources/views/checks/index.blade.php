@extends('layouts.app')
@section('title', 'Cek layanan')
@section('content')
<div class="page-head">
    <div><h1>Cek layanan</h1><p>Memeriksa aplikasinya, bukan hanya perangkatnya: halaman web, port TCP, dan server DNS, dari server ANTAREJA. Gagal dua kali berturut-turut menjadi problem.</p></div>
    @can('manage-devices')<div class="actions"><a class="btn primary" href="{{ route('checks.create') }}"><x-icon name="plus"/>Tambah cek</a></div>@endcan
</div>

<div class="kpis">
    <a class="kpi {{ ($counts['down'] ?? 0) ? 'crit' : '' }}" href="{{ route('checks.index', ['status' => 'down']) }}"><span class="lbl">Gagal</span><span class="val">{{ $counts['down'] ?? 0 }}</span></a>
    <a class="kpi {{ ($counts['slow'] ?? 0) ? 'warn' : '' }}" href="{{ route('checks.index', ['status' => 'slow']) }}"><span class="lbl">Lambat</span><span class="val">{{ $counts['slow'] ?? 0 }}</span></a>
    <a class="kpi" href="{{ route('checks.index', ['status' => 'up']) }}"><span class="lbl">Normal</span><span class="val">{{ $counts['up'] ?? 0 }}</span></a>
    <div class="kpi {{ $certSoon ? 'warn' : '' }}"><span class="lbl">Sertifikat habis &lt; 30 hari</span><span class="val">{{ $certSoon }}</span></div>
</div>

<section class="card">
    @if(request('status'))<div class="filters"><span class="muted">Hanya status {{ \App\Models\ServiceCheck::STATES[request('status')][0] ?? '' }}</span><a class="btn sm" href="{{ route('checks.index') }}">Semua</a></div>@endif
    <div class="bd flush">
    @if($checks->isEmpty())
        <div class="empty">Belum ada cek layanan. Contoh yang berguna: halaman login FMS/dispatch, port database server aplikasi, server DNS kantor, portal VPN.</div>
    @else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Cek</th><th>Status</th><th class="num">Waktu respons</th><th>Sertifikat</th><th>Terakhir</th>@can('manage-devices')<th></th>@endcan</tr></thead>
        <tbody>
        @foreach($checks as $c)
            @php([$lbl, $tone] = \App\Models\ServiceCheck::STATES[$c->status])
            <tr class="{{ ! $c->enabled ? 'is-off' : ($c->status === 'down' ? 'is-down' : '') }}">
                <td class="name">{{ $c->name }} <span class="badge plain">{{ $c->typeLabel() }}</span>
                    <small><a href="{{ route('devices.show', $c->device) }}">{{ $c->device->name }}</a>@if($c->device->site), {{ $c->device->site->code }}@endif, <span class="mono">{{ $c->target() }}</span></small></td>
                <td>@if(! $c->enabled)<span class="badge">nonaktif</span>@else<span class="badge {{ $tone }}">{{ $lbl }}</span>@endif
                    @if($c->last_error && $c->status !== 'up')<div class="muted check-err">{{ $c->last_error }}</div>@endif</td>
                <td class="num">{{ $c->last_ms !== null && $c->status !== 'down' ? \App\Support\Fmt::ms($c->last_ms) : 'tidak ada' }}@if($c->slow_ms)<div class="muted" style="font-size:12px">ambang {{ $c->slow_ms }} ms</div>@endif</td>
                <td>@if($c->cert_expires_at)@php($left = $c->certDaysLeft())<span class="{{ $left < ($c->cert_warn_days ?? 14) ? 'txt-warn' : '' }}">{{ $left < 0 ? 'habis' : $left . ' hari' }}</span><div class="muted" style="font-size:12px">{{ $c->cert_expires_at->format('d/m/Y') }}</div>@else<span class="muted">tidak ada</span>@endif</td>
                <td>{{ $c->last_at?->diffForHumans() ?? 'belum' }}<div class="muted" style="font-size:12px">tiap {{ \App\Models\ServiceCheck::INTERVALS[$c->interval_s] ?? $c->interval_s . ' detik' }}</div></td>
                @can('manage-devices')
                <td class="right"><div class="row-actions">
                    <form method="post" action="{{ route('checks.run', $c) }}">@csrf<button class="btn sm">Uji sekarang</button></form>
                    <a class="btn sm" href="{{ route('checks.edit', $c) }}"><x-icon name="edit"/>Ubah</a>
                </div></td>
                @endcan
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>
@endsection
