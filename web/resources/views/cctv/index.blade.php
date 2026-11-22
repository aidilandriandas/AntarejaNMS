@extends('layouts.app')
@section('title', 'CCTV')
@section('content')
<div class="page-head">
    <div><h1>CCTV</h1><p>Gambar diperbarui tiap 10 detik. Klik kamera untuk live view (sub-stream; pilih <em>Kualitas tinggi</em> untuk main-stream).</p></div>
    <div class="actions">
        <form method="get"><select class="input" name="site" data-autosubmit aria-label="Site" style="height:36px">
            <option value="">Semua site</option>@foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }}</option>@endforeach</select></form>
        @can('manage-devices')<a class="btn" href="{{ route('cctv.manage') }}"><x-icon name="edit"/>Kelola kamera</a>@endcan
    </div>
</div>
@if($cams->isEmpty())
    <section class="card"><div class="empty"><x-icon name="camera"/>Belum ada kamera.@can('manage-devices') <a href="{{ route('cctv.manage') }}">Tambahkan kamera atau kanal NVR</a>.@endcan</div></section>
@else
<div class="cams" data-cctv>
    @foreach($cams as $c)
        <button type="button" class="cam" data-cam="{{ $c->id }}" data-cam-name="{{ $c->name }}" title="Klik untuk live view">
            <img data-snap="{{ $c->streamName('sub') }}" alt="{{ $c->name }}" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">
            <span class="cam-nosig">Tidak ada gambar</span>
            <span class="cam-label"><b>{{ $c->name }}</b><small>{{ $c->site?->code ?? '' }}{{ $c->device ? ' · ' . $c->device->name : '' }}</small></span>
            @if($c->device && $c->device->status === 'down')<span class="badge crit cam-badge">perangkat down</span>
            @elseif(($h = $c->healthMeta()) && $c->health !== 'ok')<span class="badge {{ $h[1] }} cam-badge" title="{{ $c->health_detail }} · sejak {{ $c->health_since?->format('d/m H:i') }}">{{ $h[0] }}</span>@endif
        </button>
    @endforeach
</div>
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
