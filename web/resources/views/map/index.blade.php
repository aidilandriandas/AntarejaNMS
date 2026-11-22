@extends('layouts.app')
@section('title', 'Peta lokasi')
@section('content')
<div class="page-head">
    <div><h1>Peta lokasi</h1><p>Status tiap site dan perangkat berkoordinat (radio, menara, NVR). Garis = sambungan antarperangkat (LLDP/CDP, manual, atau perangkat induk); merah bila salah satu ujung down. Diperbarui tiap 30 detik.</p></div>
    <div class="actions">
        <span class="muted" data-map-at style="font-size:12px;align-self:center"></span>
        @can('manage-devices')<button type="button" class="btn" data-map-edit><x-icon name="map-pin"/>Atur posisi</button>@endcan
    </div>
</div>

<div class="map-wrap">
    <section class="card map-card">
        <div class="map" data-map data-url="{{ route('map.data') }}" data-pos-url="{{ route('map.position') }}" data-tiles="{{ $tileUrl }}" data-attr="{{ $attribution }}"></div>
        <div class="map-legend">
            <span><i class="dot up"></i>Normal</span><span><i class="dot problem"></i>Ada problem</span><span><i class="dot down"></i>Ada yang down</span>
            <span><i class="dot maintenance"></i>Maintenance</span><span><i class="dot idle"></i>Tanpa perangkat</span>
        </div>
        @if($sites->whereNotNull('lat')->isEmpty())
        <div class="map-empty">Belum ada site berkoordinat. @can('manage-devices')Tekan <b>Atur posisi</b>, pilih site, lalu klik lokasinya di peta.@else Minta engineer mengisi posisi site.@endcan</div>
        @endif
    </section>

    @can('manage-devices')
    <aside class="card map-panel" data-map-panel hidden>
        <div class="hd"><h2>Atur posisi</h2></div>
        <div class="bd stack">
            <div class="field"><label for="map-target">Site / perangkat</label>
                <select class="input" id="map-target" data-map-target>
                    <option value="">— pilih —</option>
                    <optgroup label="Site">@foreach($sites as $s)<option value="site:{{ $s->id }}" data-lat="{{ $s->lat }}" data-lng="{{ $s->lng }}">{{ $s->code }} — {{ $s->name }}{{ $s->lat === null ? ' · belum' : '' }}</option>@endforeach</optgroup>
                    <optgroup label="Perangkat (posisi sendiri, mis. menara radio)">@foreach($devices as $d)<option value="device:{{ $d->id }}" data-lat="{{ $d->lat }}" data-lng="{{ $d->lng }}">{{ $d->name }}{{ $d->site ? ' · ' . $d->site->code : '' }}{{ $d->lat === null ? '' : ' · ✓' }}</option>@endforeach</optgroup>
                </select>
                <div class="hint">Lalu <b>klik lokasinya di peta</b>, atau geser penanda yang sudah ada.</div></div>
            <div class="field"><label for="map-coord">Atau tempel koordinat</label>
                <div style="display:flex;gap:6px"><input class="input mono" id="map-coord" data-map-coord placeholder="-2.123456, 115.654321" autocomplete="off"><button type="button" class="btn sm" data-map-apply>Pakai</button></div>
                <div class="hint">Format lintang, bujur (desimal) — mis. disalin dari Google Maps.</div></div>
            <div><button type="button" class="btn sm danger" data-map-clear disabled>Hapus posisi</button></div>
            <p class="muted" style="font-size:12px;margin:0" data-map-msg>Perangkat tanpa posisi sendiri ikut posisi site-nya.@if($unplaced) {{ $unplaced }} perangkat belum punya posisi (site-nya juga belum).@endif</p>
        </div>
        @can('admin')
        <details class="map-settings"><summary>Sumber gambar peta</summary>
            <form method="post" action="{{ route('map.settings') }}" class="stack" style="padding:0 16px 16px">@csrf
                <div class="field"><label for="tile_url">URL tile</label><input class="input mono @error('tile_url') is-err @enderror" id="tile_url" name="tile_url" value="{{ old('tile_url', $tileUrl) }}" placeholder="https://tile.openstreetmap.org/{z}/{x}/{y}.png"><x-field-error name="tile_url"/>
                    <div class="hint">Diambil langsung oleh browser. Untuk ruang kontrol tanpa internet, isi alamat server tile internal; kosongkan untuk peta tanpa latar.</div></div>
                <div class="field"><label for="attribution">Atribusi</label><input class="input" id="attribution" name="attribution" value="{{ old('attribution', $attribution) }}"></div>
                <div><button class="btn primary sm">Simpan</button></div>
            </form>
        </details>
        @endcan
    </aside>
    @endcan
</div>
@endsection
