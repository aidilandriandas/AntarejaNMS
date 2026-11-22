@extends('layouts.app')
@section('title', 'Peta lokasi')
@section('content')
<div class="page-head">
    <div><h1>Peta lokasi{{ $plan ? ' · ' . $plan->name : '' }}</h1><p>Status tiap site dan perangkat berkoordinat (radio, menara, NVR). Garis = sambungan antarperangkat (LLDP/CDP, manual, atau perangkat induk); merah bila salah satu ujung down. Diperbarui tiap 30 detik.</p></div>
    <div class="actions">
        <span class="muted" data-map-at style="font-size:12px;align-self:center"></span>
        @can('manage-devices')<button type="button" class="btn" data-map-edit><x-icon name="map-pin"/>Atur posisi</button>@endcan
    </div>
</div>

@if($plans->isNotEmpty())
<div class="seg" style="margin-bottom:12px;flex-wrap:wrap" role="tablist" aria-label="Pilih peta">
    <a class="btn sm {{ $plan ? '' : 'primary' }}" href="{{ route('map.index') }}">Peta dunia</a>
    @foreach($plans as $pl)<a class="btn sm {{ $plan && $plan->id === $pl->id ? 'primary' : '' }}" href="{{ route('map.index', ['denah' => $pl->id]) }}">{{ $pl->name }}</a>@endforeach
</div>
@endif
<div class="map-wrap">
    <section class="card map-card">
        <div class="map" data-map data-url="{{ route('map.data', $plan ? ['denah' => $plan->id] : []) }}" data-pos-url="{{ route('map.position') }}"
            @if($plan) data-plan="{{ $plan->id }}" data-plan-w="{{ $plan->width }}" data-plan-h="{{ $plan->height }}" data-plan-img="{{ route('map.plan.image', $plan->id) }}?v={{ strtotime($plan->updated_at) }}"
            @else data-layers='@json($layers)' @endif></div>
        <div class="map-legend">
            <span><i class="dot up"></i>Normal</span><span><i class="dot problem"></i>Ada problem</span><span><i class="dot down"></i>Ada yang down</span>
            <span><i class="dot maintenance"></i>Maintenance</span><span><i class="dot idle"></i>Tanpa perangkat</span>
        </div>
        @if($sites->whereNotNull('lat')->isEmpty())
        <div class="map-empty">{{ $plan ? 'Belum ada site di denah ini.' : 'Belum ada site berkoordinat.' }} @can('manage-devices')Tekan <b>Atur posisi</b>, pilih site, lalu klik lokasinya di peta.@else Minta engineer mengisi posisi site.@endcan</div>
        @endif
    </section>

    @can('manage-devices')
    <aside class="card map-panel" data-map-panel @unless($errors->any()) hidden @endunless>
        <div class="hd"><h2>Atur posisi</h2></div>
        <div class="bd stack">
            <div class="field"><label for="map-target">Site / perangkat</label>
                <select class="input" id="map-target" data-map-target>
                    <option value="">— pilih —</option>
                    <optgroup label="Site">@foreach($sites as $s)<option value="site:{{ $s->id }}" data-lat="{{ $s->lat }}" data-lng="{{ $s->lng }}">{{ $s->code }} — {{ $s->name }}{{ $s->lat === null ? ' · belum' : '' }}</option>@endforeach</optgroup>
                    <optgroup label="Perangkat (posisi sendiri, mis. menara radio)">@foreach($devices as $d)<option value="device:{{ $d->id }}" data-lat="{{ $d->lat }}" data-lng="{{ $d->lng }}">{{ $d->name }}{{ $d->site ? ' · ' . $d->site->code : '' }}{{ $d->lat === null ? '' : ' · ✓' }}</option>@endforeach</optgroup>
                </select>
                <div class="hint">Lalu <b>klik lokasinya di peta</b>, atau geser penanda yang sudah ada.</div></div>
            @unless($plan)
            <div class="field"><label for="map-coord">Atau tempel koordinat</label>
                <div style="display:flex;gap:6px"><input class="input mono" id="map-coord" data-map-coord placeholder="-2.123456, 115.654321" autocomplete="off"><button type="button" class="btn sm" data-map-apply>Pakai</button></div>
                <div class="hint">Format lintang, bujur (desimal) — mis. disalin dari Google Maps.</div></div>
            @endunless
            <div><button type="button" class="btn sm danger" data-map-clear disabled>Hapus posisi</button></div>
            <p class="muted" style="font-size:12px;margin:0" data-map-msg>Perangkat tanpa posisi sendiri ikut posisi site-nya.@if($unplaced) {{ $unplaced }} perangkat belum punya posisi (site-nya juga belum).@endif</p>
        </div>
        @can('admin')
        <details class="map-settings" @if($errors->has('image') || $errors->has('name')) open @endif><summary>Denah (gambar latar)</summary>
            <div class="stack" style="padding:0 16px 16px">
                <p class="muted" style="margin:0;font-size:12px">Unggah denah tambang/pit (PNG, JPG, WebP; maks. 15 MB) untuk dipakai sebagai latar pengganti peta dunia. Posisi di tiap denah terpisah dari koordinat peta dunia. Gambar disimpan di database sehingga ikut backup harian.</p>
                <form method="post" action="{{ route('map.plan.store') }}" enctype="multipart/form-data" class="stack">@csrf
                    <div class="field"><label for="plan-name">Nama denah</label><input class="input @error('name') is-err @enderror" id="plan-name" name="name" value="{{ old('name') }}" placeholder="Denah Pit C — Nov 2026" maxlength="128" required><x-field-error name="name"/></div>
                    <div class="field"><label for="plan-image">Gambar</label><input class="input @error('image') is-err @enderror" type="file" id="plan-image" name="image" accept="image/png,image/jpeg,image/webp" required><x-field-error name="image"/></div>
                    <div><button class="btn primary sm">Unggah</button></div>
                </form>
                @if($plan)
                <form method="post" action="{{ route('map.plan.destroy', $plan->id) }}" data-confirm="Hapus denah {{ $plan->name }} beserta semua posisinya?">@csrf @method('delete')
                    <button class="btn sm danger">Hapus denah "{{ $plan->name }}"</button></form>
                @endif
            </div>
        </details>
        @unless($plan)
        <details class="map-settings" @if($errors->hasAny(['tile_url', 'satellite_url', 'satellite_attribution'])) open @endif><summary>Peta dasar</summary>
            <form method="post" action="{{ route('map.settings') }}" class="stack" style="padding:0 16px 16px">@csrf
                <p class="muted" style="margin:0;font-size:12px">Gambar peta diambil langsung oleh browser. Untuk ruang kontrol tanpa internet, isi alamat server tile internal.</p>
                <div class="field"><label for="tile_url">Peta jalan — URL tile</label><input class="input mono @error('tile_url') is-err @enderror" id="tile_url" name="tile_url" value="{{ old('tile_url', $tileUrl) }}" placeholder="https://tile.openstreetmap.org/{z}/{x}/{y}.png"><x-field-error name="tile_url"/></div>
                <div class="field"><label for="attribution">Atribusi</label><input class="input" id="attribution" name="attribution" value="{{ old('attribution', $attribution) }}"></div>
                <label class="check"><input type="checkbox" name="topo" value="1" @checked(old('topo', $topo))><span>Tawarkan peta topografi (OpenTopoMap)<small>Kontur & medan — berguna untuk area tambang. Butuh internet.</small></span></label>
                <div class="field"><label for="satellite_url">Citra satelit — URL tile <span class="muted">(opsional)</span></label><input class="input mono @error('satellite_url') is-err @enderror" id="satellite_url" name="satellite_url" value="{{ old('satellite_url', $satUrl) }}" placeholder="https://…/{z}/{y}/{x}"><x-field-error name="satellite_url"/>
                    <div class="hint">Isi hanya dari penyedia yang izinnya dimiliki perusahaan (umumnya berbayar), sesuai syarat penggunaannya.</div></div>
                <div class="field"><label for="satellite_attribution">Atribusi satelit</label><input class="input @error('satellite_attribution') is-err @enderror" id="satellite_attribution" name="satellite_attribution" value="{{ old('satellite_attribution', $satAttr) }}"><x-field-error name="satellite_attribution"/></div>
                <div><button class="btn primary sm">Simpan</button></div>
            </form>
        </details>
        @endunless
        @endcan
    </aside>
    @endcan
</div>
@endsection
