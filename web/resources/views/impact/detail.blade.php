{{-- Rincian dampak. $r = hasil App\Services\Impact::of() --}}
@php($cats = \App\Models\Device::CATEGORIES)
@if(\App\Services\Impact::line($r) === '')
    <p class="muted" style="margin:0">Tidak ada perangkat lain, kamera, atau layanan yang bergantung pada perangkat ini. Isi <i>perangkat induk</i> di perangkat yang terhubung lewatnya agar dampaknya terhitung.</p>
@else
<div class="impact-grid">
    @if($r['services'])
    <div><h3>Layanan</h3><ul class="impact-list">
        @foreach($r['services'] as $s)
            @php([$lbl, $tone] = \App\Services\ServiceSla::STATES[$s['after']])
            <li><a href="{{ route('services.show', $s['service']) }}">{{ $s['service']->name }}</a> <span class="badge plain {{ $tone }}">{{ $lbl }}</span></li>
        @endforeach
    </ul></div>
    @endif
    @if($r['sites'])
    <div><h3>Site</h3><ul class="impact-list">
        @foreach($r['sites'] as $s)
            <li>{{ $s['code'] }}@if($s['name'] && $s['name'] !== $s['code']) <span class="muted">({{ $s['name'] }})</span>@endif: {{ $s['hit'] }} dari {{ $s['total'] }} perangkat @if($s['all'])<span class="badge plain crit">putus total</span>@endif</li>
        @endforeach
    </ul></div>
    @endif
    @if($r['devices'])
    <div><h3>Perangkat di bawahnya</h3>
        <p class="muted" style="margin:0 0 var(--space-2xs)">{{ collect($r['categories'])->map(fn ($n, $k) => $n . ' ' . mb_strtolower($cats[$k] ?? $k))->implode(', ') }}</p>
        <ul class="impact-list">
        @foreach(array_slice($r['devices'], 0, 30) as $d)
            <li><a href="{{ route('devices.show', $d->id) }}">{{ $d->name }}</a> <span class="muted mono">{{ $d->host }}</span>@if($d->site_code) <span class="muted">{{ $d->site_code }}</span>@endif</li>
        @endforeach
        @if(count($r['devices']) > 30)<li class="muted">dan {{ count($r['devices']) - 30 }} lainnya</li>@endif
        </ul></div>
    @endif
    @if($r['cameras']->isNotEmpty())
    <div><h3>Kamera</h3><ul class="impact-list">
        @foreach($r['cameras']->take(30) as $c)<li>{{ $c->name }}</li>@endforeach
        @if($r['cameras']->count() > 30)<li class="muted">dan {{ $r['cameras']->count() - 30 }} lainnya</li>@endif
    </ul></div>
    @endif
</div>
@endif
