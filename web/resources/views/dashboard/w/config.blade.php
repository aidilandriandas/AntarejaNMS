<section class="card">
    <div class="hd"><h2>Perubahan konfigurasi</h2><span class="sub">7 hari, backup SSH</span></div>
    <div class="bd flush">
    @if($configs->isEmpty())<div class="empty">Tidak ada perubahan konfigurasi terdeteksi.</div>@else
    <ul class="feed">
        @foreach($configs as $c)
        <li><span class="ic info"><x-icon name="edit"/></span>
            <div class="msg">@can('manage-devices')<a href="{{ route('devices.config', [$c->device_id, $c->id]) }}"><b>{{ $c->name }}</b></a>@else<b>{{ $c->name }}</b>@endcan
                <small><span style="color:var(--ok)">+{{ $c->added }}</span> / <span style="color:var(--crit)">−{{ $c->removed }}</span> baris</small></div>
            <time>{{ \Illuminate\Support\Carbon::parse($c->taken_at)->diffForHumans(short: true) }}</time></li>
        @endforeach
    </ul>
    @endif
    </div>
</section>
