<section class="card">
    <div class="hd"><h2>Syslog error terbaru</h2><a class="sub" href="{{ route('logs.index', ['sev' => 3]) }}">Buka log</a></div>
    <div class="bd flush">
    @if($logs->isEmpty())<div class="empty">Tidak ada log Error ke atas dalam 24 jam.</div>@else
    <ul class="feed logfeed">
        @foreach($logs as $l)
        <li><span class="badge plain {{ $l->sevTone() }}">{{ $l->sevLabel() }}</span>
            <div class="msg mono">{{ \Illuminate\Support\Str::limit($l->message, 120) }}<small>{{ $l->device?->name ?? $l->source }}{{ $l->app ? ', ' . $l->app : '' }}</small></div>
            <time>{{ $l->received_at->diffForHumans(short: true) }}</time></li>
        @endforeach
    </ul>
    @endif
    </div>
</section>
