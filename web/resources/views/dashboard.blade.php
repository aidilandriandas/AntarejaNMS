@extends('layouts.app')
@section('title', 'Ringkasan')
@php($W = \App\Support\Dashboard::WIDGETS)
@php($shown = collect($layout)->keyBy('k'))
@php($editList = array_merge($layout, collect($W)->keys()->reject(fn ($k) => $shown->has($k))->map(fn ($k) => ['k' => $k, 'w' => $W[$k][1], 'off' => true])->values()->all()))
@section('content')
<div class="page-head">
    <div><h1>Ringkasan</h1><p>Kondisi seluruh perangkat saat ini · diperbarui otomatis tiap 60 detik</p></div>
    <div class="actions">
        <button type="button" class="btn" data-dash-edit aria-expanded="false"><x-icon name="grid"/>Atur tampilan</button>
        @can('manage-devices')<a class="btn primary" href="{{ route('devices.create') }}"><x-icon name="plus"/>Tambah perangkat</a>@endcan
    </div>
</div>

<section class="card dash-edit" data-dash-panel hidden>
    <div class="hd"><h2>Atur tampilan Ringkasan</h2><span class="sub">hanya untuk akun Anda</span></div>
    <form method="post" action="{{ route('dashboard.layout') }}">@csrf @method('put')
        <ol class="dash-list" data-dash-list>
            @foreach($editList as $w)
            @php([$title, $def, $desc, $canHalf] = $W[$w['k']])
            <li data-k="{{ $w['k'] }}">
                <input type="hidden" name="order[]" value="{{ $w['k'] }}">
                <label class="check"><input type="checkbox" name="show[]" value="{{ $w['k'] }}" @checked(empty($w['off']))><span>{{ $title }}<small>{{ $desc }}</small></span></label>
                @if($canHalf)
                <select class="input" name="size[{{ $w['k'] }}]" aria-label="Lebar {{ $title }}"><option value="half" @selected($w['w'] === 'half')>Setengah</option><option value="full" @selected($w['w'] === 'full')>Penuh</option></select>
                @else<input type="hidden" name="size[{{ $w['k'] }}]" value="full"><span class="muted" style="font-size:12px">Penuh</span>@endif
                <span class="dash-move"><button type="button" class="btn sm" data-up aria-label="Naikkan {{ $title }}">↑</button><button type="button" class="btn sm" data-down aria-label="Turunkan {{ $title }}">↓</button></span>
            </li>
            @endforeach
        </ol>
        <div class="dash-actions">
            <button class="btn primary">Simpan</button>
            <button class="btn" name="reset" value="1" formnovalidate>Kembalikan bawaan</button>
            <button type="button" class="btn" data-dash-cancel>Batal</button>
        </div>
    </form>
</section>

@if($counts->total === 0)
    <div class="card" style="margin-bottom:16px"><div class="empty"><x-icon name="server"/>Belum ada perangkat.
        @can('manage-devices')<div style="margin-top:12px"><a class="btn primary" href="{{ route('devices.create') }}"><x-icon name="plus"/>Tambah perangkat pertama</a></div>@endcan
    </div></div>
@endif

@if(empty($layout))
    <div class="card"><div class="empty">Semua widget disembunyikan. Tekan <b>Atur tampilan</b> untuk memilih yang ingin ditampilkan.</div></div>
@else
<div class="dash">
    @foreach($layout as $w)
        <div class="dash-w {{ $w['w'] === 'full' ? 'w-full' : '' }}" data-w="{{ $w['k'] }}">@include('dashboard.w.' . $w['k'])</div>
    @endforeach
</div>
@endif
@push('scripts')<script>setTimeout(function () { if (document.querySelector('[data-dash-panel]:not([hidden])')) return; if (!document.hidden) location.reload(); else document.addEventListener('visibilitychange', function () { location.reload(); }, { once: true }); }, 60000);</script>@endpush
@endsection
