<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Pembaca data grafik dari VictoriaMetrics (API kueri kompatibel Prometheus).
 * Dipanggil hanya dari server; VictoriaMetrics tidak dibuka ke browser.
 */
class Metrics
{
    public const RANGES = ['6h' => 6 * 3600, '24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400];

    private function http()
    {
        return Http::baseUrl((string) config('antareja.metrics_url'))->timeout(15)->connectTimeout(3);
    }

    /**
     * @return array{t: int[], series: array<string, array<int, float|null>>}
     *   Deret waktu yang diselaraskan ke stempel waktu yang sama (siap untuk grafik).
     */
    public function range(array $queries, int $seconds, ?int $end = null): array
    {
        $end ??= time();
        $start = $end - $seconds;
        $step = max(60, intdiv($seconds, 360)); // ±360 titik per grafik
        $times = range($start - $start % $step + $step, $end - $end % $step, $step);
        $index = array_flip($times);
        $out = ['t' => $times, 'series' => []];
        foreach ($queries as $label => $q) {
            $vals = array_fill(0, count($times), null);
            try {
                $res = $this->http()->get('/api/v1/query_range', ['query' => $q, 'start' => $start, 'end' => $end, 'step' => $step . 's']);
                foreach ($res->json('data.result.0.values') ?? [] as [$ts, $v]) {
                    $i = $index[(int) $ts] ?? null;
                    if ($i !== null && is_numeric($v)) {
                        $vals[$i] = round((float) $v, 3);
                    }
                }
            } catch (\Throwable) {
                // VictoriaMetrics tidak terjangkau: grafik kosong, halaman tetap tampil
            }
            $out['series'][$label] = $vals;
        }

        return $out;
    }

    /** Nilai terkini per label (mis. trafik per port): [nilai label => angka]. */
    public function instant(string $query, string $byLabel): array
    {
        try {
            $res = $this->http()->get('/api/v1/query', ['query' => $query]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($res->json('data.result') ?? [] as $r) {
            if (isset($r['metric'][$byLabel]) && is_numeric($r['value'][1] ?? null)) {
                $out[$r['metric'][$byLabel]] = (float) $r['value'][1];
            }
        }

        return $out;
    }
}
