<section class="card map-card">
    <div class="hd"><h2>Peta lokasi</h2><a class="sub" href="{{ route('map.index') }}">Buka peta →</a></div>
    <div class="map dash-map" data-map data-url="{{ route('map.data') }}" data-pos-url="{{ route('map.position') }}" data-layers='@json($mapLayers)'></div>
</section>
