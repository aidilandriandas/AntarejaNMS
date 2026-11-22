{{-- Sparkline puncak harian + garis tren + batas. $r = baris Capacity, $threshold --}}
@php
    $pts = $r['points']; $W = 180; $H = 40;
    $limit = $r['speed'] > 0 ? $r['speed'] * $threshold / 100 : null;
    $max = max(array_merge(array_values($pts), [$limit ?? 0, 1])) * 1.08;
    $t0 = array_key_first($pts); $t1 = array_key_last($pts); $span = max(1, $t1 - $t0);
    $X = fn ($t) => round(($t - $t0) / $span * $W, 1);
    $Y = fn ($v) => round($H - $v / $max * $H, 1);
    $line = collect($pts)->map(fn ($v, $t) => $X($t) . ',' . $Y($v))->implode(' ');
@endphp
@if(count($pts) >= 2)
<svg class="spark" viewBox="0 0 {{ $W }} {{ $H }}" width="{{ $W }}" height="{{ $H }}" role="img" aria-label="Puncak harian {{ count($pts) }} hari">
    @if($limit)<line class="lim" x1="0" x2="{{ $W }}" y1="{{ $Y($limit) }}" y2="{{ $Y($limit) }}"/>@endif
    <polyline class="val" points="{{ $line }}"/>
    @if($r['slope'] !== null)
        @php($days = ($t1 - $t0) / 86400)
        <line class="trend" x1="0" y1="{{ $Y(max(0, $r['intercept'])) }}" x2="{{ $W }}" y2="{{ $Y(max(0, $r['intercept'] + $r['slope'] * $days)) }}"/>
    @endif
</svg>
@else<span class="muted">–</span>@endif
