<section class="card">
    <div class="hd"><h2>Piket</h2><a class="sub" href="{{ route('oncall.index') }}">Lihat jadwal</a></div>
    <div class="bd flush">
    <ul class="feed">
        @forelse($oncall['now'] as $o)
            <li><span class="ic ok"><x-icon name="clock"/></span><div class="msg"><b>{{ $o['name'] }}</b><small>sedang piket</small></div><time>sampai {{ $o['until']->format('H.i') }}</time></li>
        @empty
            <li><span class="ic warn"><x-icon name="alert"/></span><div class="msg"><b>Tidak ada yang piket</b><small>notifikasi piket ke tujuan cadangan kanal</small></div><time></time></li>
        @endforelse
        @foreach($oncall['next'] as $o)
            <li><span class="ic muted"><x-icon name="clock"/></span><div class="msg"><b>{{ $o['name'] }}</b><small>berikutnya</small></div><time>{{ $o['from']->isToday() ? '' : $o['from']->translatedFormat('D') . ' ' }}{{ $o['from']->format('H.i') }}</time></li>
        @endforeach
    </ul>
    </div>
</section>
