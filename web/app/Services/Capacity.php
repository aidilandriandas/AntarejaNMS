<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Perencanaan kapasitas port.
 *
 * Untuk tiap port yang dipantau: puncak harian = persentil 95 trafik (bit/s, arah masuk & keluar, diambil yang lebih
 * besar per hari) dari VictoriaMetrics. Tren = regresi linear kuadrat terkecil atas puncak harian dalam jendela
 * (30/90 hari). Perkiraan penuh = hari ketika garis tren mencapai batas kapasitas (capacity.threshold_pct dari
 * kecepatan port). Butuh minimal MIN_DAYS hari data.
 */
class Capacity
{
    public const MIN_DAYS = 7;

    public const WINDOWS = [30 => '30 hari', 90 => '90 hari'];

    public function __construct(private Metrics $metrics) {}

    public static function threshold(): float
    {
        return (float) Setting::get('capacity.threshold_pct', 80);
    }

    /** @return array<int, array> baris per port, belum diurutkan */
    public function ports(int $days): array
    {
        $ports = DB::select("SELECT i.device_id, i.if_index, COALESCE(NULLIF(i.name, ''), NULLIF(i.descr, ''), 'if' || i.if_index) AS port, i.alias, i.speed_bps,
                d.name AS device, s.code AS site
            FROM monitored_ports mp JOIN interfaces i ON i.device_id = mp.device_id AND i.if_index = mp.if_index
            JOIN devices d ON d.id = mp.device_id LEFT JOIN sites s ON s.id = d.site_id
            ORDER BY d.name, i.if_index");
        if (! $ports) {
            return [];
        }
        // cache 10 menit; hasil kosong (VictoriaMetrics tak terjangkau / belum ada data) tidak disimpan
        $key = 'capacity:' . $days . ':' . md5(json_encode(array_map(fn ($p) => [$p->device_id, $p->if_index], $ports)));
        $series = Cache::get($key);
        if ($series === null) {
            $series = $this->daily(array_unique(array_column($ports, 'device_id')), $days);
            if ($series) {
                Cache::put($key, $series, 600);
            }
        }
        $thr = self::threshold();

        return array_map(function ($p) use ($series, $thr) {
            $pts = $series["{$p->device_id}:{$p->if_index}"] ?? [];

            return ['device_id' => (int) $p->device_id, 'if_index' => (int) $p->if_index, 'device' => $p->device, 'site' => $p->site,
                'port' => $p->port, 'alias' => $p->alias, 'speed' => (float) $p->speed_bps] + self::forecast($pts, (float) $p->speed_bps, $thr);
        }, $ports);
    }

    /**
     * Puncak harian per port: ["dev:if" => [unix hari => bps]].
     *
     * @param  int[]  $devices
     */
    private function daily(array $devices, int $days): array
    {
        $sel = 'device_id=~"' . implode('|', array_map('intval', $devices)) . '"';
        $end = now()->startOfDay()->getTimestamp(); // hari yang sudah lengkap saja
        $out = [];
        foreach (['in', 'out'] as $dir) {
            $q = "quantile_over_time(0.95, (rate(antareja_if_{$dir}_octets_total{{$sel}}[15m]) * 8)[1d:5m])";
            foreach ($this->metrics->matrix($q, $end - ($days - 1) * 86400, $end, 86400) as [$labels, $values]) {
                $k = ($labels['device_id'] ?? '') . ':' . ($labels['if_index'] ?? '');
                foreach ($values as $ts => $v) {
                    $out[$k][$ts - 86400] = max($out[$k][$ts - 86400] ?? 0, $v); // nilai di T = puncak hari sebelumnya
                }
            }
        }
        $first = $end - $days * 86400; // awal hari pertama jendela
        foreach ($out as &$v) {
            ksort($v);
            // hari pertama data yang tidak di awal jendela kemungkinan tidak lengkap (perangkat/port baru) → puncaknya
            // terlalu rendah dan membuat tren tampak naik; dibuang
            if (count($v) > 1 && array_key_first($v) > $first) {
                unset($v[array_key_first($v)]);
            }
        }
        unset($v);

        return $out;
    }

    /**
     * Tren & perkiraan dari puncak harian. $pts = [unix => bps].
     *
     * @return array{points: array, days: int, current: ?float, util: ?float, slope: ?float, r2: ?float, days_left: ?float, full_at: ?int, state: string}
     */
    public static function forecast(array $pts, float $speed, float $thrPct): array
    {
        $n = count($pts);
        $r = ['points' => $pts, 'days' => $n, 'current' => null, 'util' => null, 'slope' => null, 'intercept' => null, 'r2' => null, 'days_left' => null, 'full_at' => null, 'state' => 'nodata'];
        if ($n === 0) {
            return $r;
        }
        $vals = array_values($pts);
        $r['current'] = array_sum(array_slice($vals, -7)) / min(7, $n); // rata-rata puncak 7 hari terakhir
        $r['util'] = $speed > 0 ? $r['current'] / $speed * 100 : null;
        if ($n < self::MIN_DAYS) {
            $r['state'] = 'short';

            return $r;
        }
        $xs = array_map(fn ($t) => ($t - array_key_first($pts)) / 86400, array_keys($pts));
        $mx = array_sum($xs) / $n;
        $my = array_sum($vals) / $n;
        $sxx = $sxy = $syy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($vals[$i] - $my);
            $syy += ($vals[$i] - $my) ** 2;
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0.0;
        $a = $my - $b * $mx;
        $r['slope'] = $b;                                           // bps per hari
        $r['intercept'] = $a;                                       // bps pada hari pertama
        $r['r2'] = $syy > 0 && $sxx > 0 ? ($sxy ** 2) / ($sxx * $syy) : null;
        if ($speed <= 0) {
            $r['state'] = 'nospeed';

            return $r;
        }
        $limit = $speed * $thrPct / 100;
        $now = $a + $b * end($xs);
        if ($r['current'] >= $limit || $now >= $limit) {
            $r['state'] = 'over';
            $r['days_left'] = 0;
        } elseif ($b > 0) {
            $r['days_left'] = ($limit - $now) / $b;
            $r['full_at'] = (int) (array_key_last($pts) + $r['days_left'] * 86400);
            $r['state'] = $r['days_left'] <= 30 ? 'soon' : ($r['days_left'] <= 180 ? 'watch' : 'ok');
        } else {
            $r['state'] = 'ok';
        }

        return $r;
    }

    public const STATES = [
        'over' => ['Melewati batas', 'crit'], 'soon' => ['≤ 30 hari', 'crit'], 'watch' => ['≤ 6 bulan', 'warn'],
        'ok' => ['Aman', 'ok'], 'short' => ['Data belum cukup', ''], 'nodata' => ['Belum ada data', ''], 'nospeed' => ['Kecepatan port tak diketahui', ''],
    ];

    /** Bit/s → teks singkat. */
    public static function bps(?float $v): string
    {
        if ($v === null) {
            return '–';
        }
        $u = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
        $i = 0;
        $neg = $v < 0;
        $v = abs($v);
        while ($v >= 1000 && $i < 4) {
            $v /= 1000;
            $i++;
        }

        return ($neg ? '−' : '') . number_format($v, $v >= 100 || $i === 0 ? 0 : 1, ',', '.') . ' ' . $u[$i];
    }
}
