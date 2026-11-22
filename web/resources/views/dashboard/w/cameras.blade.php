<section class="card">
    <div class="hd"><h2>Kamera bermasalah</h2><a class="sub" href="{{ route('cctv.index') }}">CCTV →</a></div>
    <div class="bd flush">
    @if($cameras->isEmpty())<div class="empty">Semua kamera yang dicek normal.</div>@else
    <ul class="feed">
        @foreach($cameras as $c)
        @php([$label, $tone] = $c->healthMeta() ?? ['?', 'muted'])
        <li><span class="ic {{ $tone }}"><x-icon name="camera"/></span>
            <div class="msg"><b>{{ $c->name }}</b><small>{{ $label }}{{ $c->site ? ' · ' . $c->site->code : '' }}{{ $c->health_detail ? ' — ' . \Illuminate\Support\Str::limit($c->health_detail, 70) : '' }}</small></div>
            <time>{{ $c->health_since ? \Illuminate\Support\Carbon::parse($c->health_since)->diffForHumans(short: true) : '' }}</time></li>
        @endforeach
    </ul>
    @endif
    </div>
</section>
