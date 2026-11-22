<section class="card">
    <div class="hd"><h2>Maintenance</h2><a class="sub" href="{{ route('maintenance.index') }}">Lihat jadwal</a></div>
    <div class="bd flush">
    @if($maint->isEmpty())<div class="empty">Tidak ada maintenance berjalan atau dalam 7 hari ke depan.</div>@else
    <ul class="feed">
        @foreach($maint as $m)
        @php($s = \Illuminate\Support\Carbon::parse($m->starts_at))
        @php($e = \Illuminate\Support\Carbon::parse($m->ends_at))
        @php($now = $s->isPast())
        <li><span class="ic {{ $now ? 'warn' : 'muted' }}"><x-icon name="wrench"/></span>
            <div class="msg"><b>{{ $m->title }}</b><small>{{ ['all' => 'Semua perangkat', 'site' => 'Site ' . $m->site, 'devices' => 'Perangkat terpilih'][$m->scope] ?? $m->scope }}, {{ $s->format('d/m H.i') }} sampai {{ $e->isSameDay($s) ? $e->format('H.i') : $e->format('d/m H.i') }}</small></div>
            <time>{{ $now ? 'berjalan' : $s->diffForHumans(short: true) }}</time></li>
        @endforeach
    </ul>
    @endif
    </div>
</section>
