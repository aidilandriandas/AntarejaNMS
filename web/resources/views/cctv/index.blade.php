@extends('layouts.app')
@section('title', 'CCTV')
@section('content')
<div class="page-head">
    <div><h1>CCTV</h1><p>Gambar diperbarui tiap 10 detik. Klik kamera untuk live view (sub-stream; pilih <em>Kualitas tinggi</em> untuk main-stream).</p></div>
    <div class="actions">
        <form method="get" style="display:flex;gap:var(--space-xs);align-items:center">@if($cams->isNotEmpty())<input class="input" type="search" name="q" value="{{ $q }}" placeholder="Cari kamera, mis. ruang meeting" aria-label="Cari kamera" data-cam-filter style="height:36px;width:260px;max-width:100%">@endif<select class="input" name="site" data-autosubmit aria-label="Site" style="height:36px;width:auto">
            <option value="">Semua site</option>@foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }}</option>@endforeach</select></form>
        @can('manage-devices')<a class="btn" href="{{ route('cctv.manage') }}"><x-icon name="edit"/>Kelola kamera</a>@endcan
    </div>
</div>
@if($cams->isEmpty())
    <section class="card"><div class="empty"><x-icon name="camera"/>Belum ada kamera.@can('manage-devices') <a href="{{ route('cctv.manage') }}">Tambahkan kamera atau kanal NVR</a>.@endcan</div></section>
@else
<p class="muted" data-cam-count hidden style="margin:0 0 var(--space-sm)"></p>
<div class="cams" data-cctv @if($openCam) data-open="{{ $openCam }}" @endif>
    @foreach($cams as $c)
        <button type="button" class="cam" data-cam="{{ $c->id }}" data-cam-name="{{ $c->name }}" title="Klik untuk live view"
            data-cam-text="{{ mb_strtolower(implode(' ', array_filter([$c->name, $c->site?->code, $c->site?->name, $c->device?->name, $c->host]))) }}">
            <img data-snap="{{ $c->streamName('sub') }}" alt="{{ $c->name }}" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">
            <span class="cam-nosig">Tidak ada gambar</span>
            <span class="cam-label"><b>{{ $c->name }}</b><small>{{ collect([$c->site?->code, $c->device && $c->device->name !== $c->name ? $c->device->name : null])->filter()->implode(', ') }}</small></span>
            @if($c->device && $c->device->status === 'down')<span class="badge crit cam-badge">Perangkat down</span>
            @elseif(($h = $c->healthMeta()) && $c->health !== 'ok')<span class="badge {{ $h[1] }} cam-badge" title="{{ $c->health_detail }}, sejak {{ $c->health_since?->format('d/m H:i') }}">{{ $h[0] }}</span>@endif
        </button>
    @endforeach
</div>
<div class="empty" data-cam-none hidden>Tidak ada kamera yang cocok. Coba kata lain, mis. sebagian nama, nama site, atau IP.</div>
@endif
<div class="cam-modal" data-cam-modal hidden>
    <div class="cam-box">
        <div class="cam-head"><b data-cam-title></b>
            <span style="display:flex;gap:6px">
                <button type="button" class="btn sm" data-q="sub">Hemat (sub)</button>
                <button type="button" class="btn sm" data-q="main">Kualitas tinggi</button>
                <button type="button" class="btn sm" data-cam-close aria-label="Tutup">✕</button>
            </span></div>
        <div class="cam-video"><video muted playsinline autoplay></video><div class="cam-status" data-cam-status></div></div>
    </div>
</div>
@endsection
