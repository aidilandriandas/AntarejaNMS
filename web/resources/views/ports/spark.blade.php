{{-- Grafik mini trafik 24 jam: masuk ke atas, keluar ke bawah. $sp = ['in' => [ts => bps], 'out' => [...]] --}}
@php
    $W = 120; $H = 30; $mid = $H / 2;
    $in = $sp['in'] ?? []; $out = $sp['out'] ?? [];
    $all = array_merge(array_keys($in), array_keys($out));
@endphp
@if(count($all) >= 2)
    @php
        $t0 = min($all); $span = max(1, max($all) - $t0);
        $max = max(1, max(array_merge(array_values($in), array_values($out), [0])));
        $X = fn ($t) => round(($t - $t0) / $span * $W, 1);
        $poly = function ($vals, $sign) use ($X, $max, $mid) {
            if (! $vals) { return ''; }
            ksort($vals);
            $pts = [];
            foreach ($vals as $t => $v) { $pts[] = $X($t) . ',' . round($mid - $sign * $v / $max * ($mid - 1), 1); }
            return $X(array_key_first($vals)) . ',' . $mid . ' ' . implode(' ', $pts) . ' ' . $X(array_key_last($vals)) . ',' . $mid;
        };
    @endphp
    <svg class="pspark" viewBox="0 0 {{ $W }} {{ $H }}" width="{{ $W }}" height="{{ $H }}" role="img" aria-label="Trafik 24 jam, puncak {{ \App\Support\Fmt::bps($max) }}">
        <title>24 jam · puncak {{ \App\Support\Fmt::bps($max) }}</title>
        <polygon class="i" points="{{ $poly($in, 1) }}"/><polygon class="o" points="{{ $poly($out, -1) }}"/>
        <line class="z" x1="0" x2="{{ $W }}" y1="{{ $mid }}" y2="{{ $mid }}"/>
    </svg>
@else<span class="muted">–</span>@endif
