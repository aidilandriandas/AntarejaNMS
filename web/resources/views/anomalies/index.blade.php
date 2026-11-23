@extends('layouts.app')
@section('title', 'Anomali')
@php
    $canEdit = auth()->user()->can('manage-devices');
    $link = fn ($a) => $a->if_index !== null ? route('ports.show', [$a->device_id, $a->if_index]) : route('devices.show', $a->device_id);
@endphp
@section('content')
<div class="page-head">
    <div><h1>Anomali</h1>
        <p>Latensi dan trafik port yang menyimpang jauh dari kebiasaannya. Patokannya nilai di jam yang sama selama 14 hari terakhir, jadi trafik siang yang memang ramai tidak dianggap aneh, tetapi trafik ramai jam 3 pagi atau kamera yang tiba-tiba diam akan tercatat.</p></div>
</div>

<div class="kpis">
    <div class="kpi {{ $active->isNotEmpty() ? 'warn' : '' }}"><div class="lbl">Sedang terjadi</div><div class="val">{{ $active->count() }}</div>
        <div class="note">{{ $active->isEmpty() ? 'semua dalam pola biasa' : $active->pluck('device.name')->unique()->take(3)->implode(', ') }}</div></div>
    <div class="kpi"><div class="lbl">7 hari terakhir</div><div class="val">{{ $weekN }}</div><div class="note">di {{ $weekDev }} perangkat</div></div>
    <div class="kpi {{ $enabled ? '' : 'warn' }}"><div class="lbl">Deteksi</div><div class="val val-text">{{ $enabled ? 'aktif' : 'mati' }}</div>
        <div class="note">{{ $enabled ? ($severity === 'off' ? 'hanya dicatat, tidak jadi problem' : 'jadi problem ' . \App\Models\Anomaly::SEVERITIES[$severity]) : 'tidak ada penilaian' }}</div></div>
</div>

<section class="card">
    <div class="hd"><h2>Sedang terjadi</h2><span class="sub">dinilai ulang tiap 5 menit</span></div>
    <div class="bd flush">
    @if($active->isEmpty())
        <div class="empty">Tidak ada anomali. Perangkat baru dinilai setelah punya data minimal 7 hari.</div>
    @else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Perangkat</th><th>Apa</th><th class="num">Sekarang</th><th class="num">Biasanya</th><th>Sejak</th>@if($canEdit)<th><span class="sr-only">Aksi</span></th>@endif</tr></thead>
        <tbody>
        @foreach($active as $a)
            <tr>
                <td class="name"><a href="{{ route('devices.show', $a->device_id) }}">{{ $a->device->name }}</a><small>{{ $a->device->site?->code ?? 'tanpa site' }}</small></td>
                <td><a href="{{ $link($a) }}">{{ $a->label() }}</a>
                    <div><span class="badge plain warn">{{ $a->direction === 'up' ? ($a->metric === 'latency' ? 'naik' : 'melonjak') : 'anjlok' }}</span> <span class="muted">{{ $a->ratio() }}</span></div></td>
                <td class="num nowrap"><b>{{ $a->fmt($a->value) }}</b></td>
                <td class="num nowrap">{{ $a->fmt($a->baseline) }}</td>
                <td class="nowrap">{{ $a->started_at->format('d/m H.i') }}<small class="muted" style="display:block">{{ $a->started_at->diffForHumans(short: true) }}</small></td>
                @if($canEdit)<td class="right">
                    <form method="post" action="{{ route('anomalies.dismiss', $a) }}" class="inline-form">
                        @csrf
                        <label class="sr-only" for="h{{ $a->id }}">Abaikan selama</label>
                        <select class="input sm" id="h{{ $a->id }}" name="hours">@foreach(\App\Models\Anomaly::MUTES as $h => $l)<option value="{{ $h }}" @selected($h === 24)>{{ $l }}</option>@endforeach</select>
                        <button class="btn sm" title="Tutup anomali ini dan abaikan yang sama selama waktu terpilih"><x-icon name="check"/>Wajar</button>
                    </form></td>@endif
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>

<div class="grid g-main" style="margin-top:var(--space-xl)">
    <section class="card">
        <div class="hd"><h2>Riwayat 30 hari</h2></div>
        <div class="bd flush">
        @if($history->isEmpty())
            <div class="empty">Belum ada anomali yang selesai dalam 30 hari terakhir.</div>
        @else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Waktu</th><th>Perangkat</th><th>Apa</th><th class="num">Puncak</th><th>Lama</th><th>Ditutup</th></tr></thead>
            <tbody>
            @foreach($history as $a)
                <tr>
                    <td class="nowrap">{{ $a->started_at->format('d/m H.i') }}</td>
                    <td class="name">{{ $a->device?->name }}</td>
                    <td><a href="{{ $link($a) }}">{{ $a->label() }}</a> <span class="muted">{{ $a->direction === 'up' ? 'naik' : 'turun' }}, biasanya {{ $a->fmt($a->baseline) }}</span></td>
                    <td class="num nowrap">{{ $a->fmt($a->value) }}</td>
                    <td class="nowrap">{{ \App\Support\Fmt::duration(max(60, (int) $a->started_at->diffInSeconds($a->ended_at))) }}</td>
                    <td>@if($a->dismissed_by)<span class="badge plain">ditandai wajar</span><small class="muted" style="display:block">{{ $a->dismisser?->name }}{{ $a->dismiss_note ? ', ' . $a->dismiss_note : '' }}</small>@else kembali normal @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
        </div>
    </section>

    <div class="stack">
        @can('admin')
        <section class="card"><div class="hd"><h2>Setelan</h2></div><div class="bd">
            <form method="post" action="{{ route('anomalies.settings') }}" class="form">
                @csrf @method('PUT')
                <label class="check"><input type="checkbox" name="enabled" value="1" @checked($enabled)><span>Deteksi anomali aktif</span></label>
                <div class="field"><label for="problem_severity">Anomali jadi problem</label>
                    <select class="input" id="problem_severity" name="problem_severity">
                        @foreach(\App\Models\Anomaly::SEVERITIES as $k => $v)<option value="{{ $k }}" @selected($severity === $k)>{{ $v }}</option>@endforeach
                    </select>
                    <div class="hint">Problem anomali ikut antrean, insiden, dan notifikasi sesuai severity minimal kanal. Warning biasanya cukup: terlihat di antrean tanpa membangunkan orang.</div></div>
                <div class="form-foot"><button class="btn primary">Simpan</button></div>
            </form>
        </div></section>
        @endcan
        <section class="card"><div class="hd"><h2>Sedang diabaikan</h2></div><div class="bd flush">
            @if($mutes->isEmpty())
                <div class="empty">Tidak ada. Tombol <b>Wajar</b> menutup anomali dan mengabaikan yang sama untuk sementara.</div>
            @else
            <table class="t"><tbody>
                @foreach($mutes as $m)
                <tr><td class="name">{{ $m->name }}<small>{{ \App\Models\Anomaly::METRICS[$m->metric] ?? $m->metric }}{{ $m->if_index >= 0 ? ', port ' . $m->if_index : '' }}, sampai {{ \Illuminate\Support\Carbon::parse($m->until)->format('d/m H.i') }}</small></td>
                    @if($canEdit)<td class="right"><form method="post" action="{{ route('anomalies.unmute') }}">@csrf @method('DELETE')
                        <input type="hidden" name="device_id" value="{{ $m->device_id }}"><input type="hidden" name="metric" value="{{ $m->metric }}"><input type="hidden" name="if_index" value="{{ $m->if_index }}">
                        <button class="btn sm">Cabut</button></form></td>@endif</tr>
                @endforeach
            </tbody></table>
            @endif
        </div></section>
        <section class="card"><div class="hd"><h2>Cara kerja</h2></div><div class="bd notes">
            <p>Tiap 5 menit, rata-rata 15 menit terakhir dibandingkan dengan nilai di jam yang sama pada 14 hari sebelumnya (median dan sebarannya). Satu dua hari yang aneh di masa lalu tidak menggeser patokan.</p>
            <p>Dicatat bila menyimpang jauh dan bertahan 10 menit: latensi minimal 1,5 kali biasanya dan naik 5 ms; trafik minimal 2 kali biasanya dan naik 5 Mbps, atau anjlok di bawah seperempat dari port yang biasanya di atas 2 Mbps.</p>
            <p>Perangkat down dan port putus tidak dinilai karena sudah punya problem sendiri.</p>
        </div></section>
    </div>
</div>
@endsection
