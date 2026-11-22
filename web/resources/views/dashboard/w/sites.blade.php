@if($sites->isEmpty())<section class="card"><div class="hd"><h2>Per site</h2></div><div class="empty">Belum ada site.</div></section>@else
<section class="card">
    <div class="hd"><h2>Per site</h2></div>
    <div class="sites">
        @foreach($sites as $s)
            <a class="site {{ $s->down ? 'bad' : '' }}" href="{{ route('devices.index', ['site' => $s->id]) }}">
                <b>{{ $s->code }}</b><small>{{ $s->name }}</small>
                <div style="margin-top:6px;font-size:13px">@if($s->down)<strong style="color:var(--crit)">{{ $s->down }} down</strong>, @endif{{ $s->up }}/{{ $s->total }} online</div>
                <div class="stack-bar">
                    @if($s->up)<span style="flex:{{ $s->up }};background:var(--ok)"></span>@endif
                    @if($s->down)<span style="flex:{{ $s->down }};background:var(--crit)"></span>@endif
                    @if($s->total - $s->up - $s->down > 0)<span style="flex:{{ $s->total - $s->up - $s->down }};background:var(--off)"></span>@endif
                </div>
            </a>
        @endforeach
    </div>
</section>
@endif
