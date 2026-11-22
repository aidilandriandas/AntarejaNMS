@php($S = \App\Services\ServiceSla::STATES)
<section class="card">
    <div class="hd"><h2>Layanan</h2><a class="sub" href="{{ route('services.index') }}">SLA layanan →</a></div>
    <div class="bd flush">
    @if($services->isEmpty())<div class="empty">Belum ada layanan. Buat di <a href="{{ route('services.index') }}">SLA layanan</a>.</div>@else
    <div class="table-wrap"><table class="t">
        <tbody>
        @foreach($services->map(fn ($s) => [$s, \App\Services\ServiceSla::liveStatus($s)[0]])->sortBy(fn ($x) => ['down' => 0, 'degraded' => 1, 'up' => 2, 'empty' => 3][$x[1]])->take(12) as [$s, $st])
            @php($a = $serviceSla[$s->id]['availability'])
            <tr class="{{ $st === 'down' ? 'is-down' : '' }}">
                <td class="name"><a href="{{ route('services.show', $s) }}">{{ $s->name }}</a></td>
                <td><span class="badge {{ $S[$st][1] }}">{{ $S[$st][0] }}</span></td>
                <td class="num">@if($s->components->isNotEmpty() && $a !== null)<span class="badge plain {{ $a >= $s->target() ? 'ok' : 'crit' }}">{{ number_format($a, $a >= 99.995 ? 2 : 3, ',', '.') }}%</span>@else–@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>
