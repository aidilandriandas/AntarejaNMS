<?php

namespace App\Services;

use App\Models\IspLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SLA link ISP dari sampel ping di VictoriaMetrics.
 *
 * Periode dibagi per langkah (60 detik, lebih besar untuk periode panjang). Satu langkah dihitung gangguan bila rata-rata
 * ping up di langkah itu < 0,5 (sebagian besar ping gagal) atau rata-rata packet loss >= ambang loss link. Langkah tanpa
 * data tidak dihitung (bukan naik, bukan turun) dan ditampilkan sebagai "tanpa data"; langkah di dalam maintenance
 * perangkat dikeluarkan bila link diatur begitu. Ketersediaan = langkah baik / (langkah baik + langkah gangguan).
 * Insiden = langkah gangguan yang berurutan (jeda satu langkah tanpa data masih dianggap insiden yang sama).
 */
class IspSla
{
    public const MAX_DETAIL_POINTS = 1500;

    public function __construct(private Metrics $metrics) {}

    public static function step(int $seconds): int
    {
        return max(60, (int) ceil($seconds / 28000 / 60) * 60); // di bawah batas titik per deret VictoriaMetrics
    }

    public function report(IspLink $link, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $s = $start->getTimestamp();
        $e = min($end->getTimestamp(), time());
        $step = self::step(max(60, $e - $s));
        $sel = '{device_id="' . (int) $link->device_id . '"}';
        $w = '[' . $step . 's]';
        $get = function (string $q) use ($s, $e, $step) {
            $m = $this->metrics->matrix($q, $s + $step, $e, $step);

            return $m[0][1] ?? [];
        };
        $series = [
            'up' => $get("avg_over_time(antareja_ping_up$sel$w)"),
            'loss' => $get("avg_over_time(antareja_ping_loss_pct$sel$w)"),
            'rtt' => $get("avg_over_time(antareja_ping_rtt_ms$sel$w)"),
            'jitter' => $get("avg_over_time(antareja_ping_jitter_ms$sel$w)"),
            'count' => $get("count_over_time(antareja_ping_up$sel$w)"),
        ];

        return self::compute($link, $series, $s, $e, $step, $link->exclude_maintenance ? self::maintenance($link->device_id, $s, $e) : []);
    }

    /** Jendela maintenance yang berlaku untuk perangkat: [[mulai, akhir], ...] (unix). */
    public static function maintenance(int $deviceId, int $s, int $e): array
    {
        return DB::table('maintenances as m')->join('devices as d', 'd.id', '=', DB::raw((int) $deviceId))
            ->where('m.starts_at', '<', date('c', $e))->where('m.ends_at', '>', date('c', $s))
            ->where(fn ($q) => $q->where('m.scope', 'all')->orWhere(fn ($q) => $q->where('m.scope', 'site')->whereColumn('m.site_id', 'd.site_id'))
                ->orWhere(fn ($q) => $q->where('m.scope', 'devices')->whereExists(fn ($x) => $x->from('maintenance_devices as md')
                    ->whereColumn('md.maintenance_id', 'm.id')->where('md.device_id', $deviceId))))
            ->get(['m.starts_at', 'm.ends_at'])->map(fn ($r) => [strtotime($r->starts_at), strtotime($r->ends_at)])->all();
    }

    /**
     * @param  array<string, array<int, float>>  $series  up, loss, rtt, jitter, count: [unix => nilai]
     */
    public static function compute(IspLink $link, array $series, int $s, int $e, int $step, array $maint = []): array
    {
        $tz = config('app.timezone');
        $good = $bad = $nodata = $inMaint = 0;
        $sum = ['rtt' => [0, 0], 'jitter' => [0, 0], 'loss' => [0, 0]];
        $samples = 0;
        $days = [];
        $incidents = [];
        $cur = null;
        $gap = 0;
        $detail = ['t' => [], 'rtt' => [], 'jitter' => [], 'loss' => [], 'bad' => []];
        for ($t = $s + $step; $t <= $e; $t += $step) {
            $day = CarbonImmutable::createFromTimestamp($t - 1, $tz)->format('Y-m-d');
            $days[$day] ??= ['good' => 0, 'bad' => 0, 'rtt' => [0, 0], 'jitter' => [0, 0], 'loss' => [0, 0]];
            $up = $series['up'][$t] ?? null;
            $loss = $series['loss'][$t] ?? null;
            $samples += (int) ($series['count'][$t] ?? 0);
            foreach (['rtt', 'jitter', 'loss'] as $k) {
                if (isset($series[$k][$t])) {
                    $sum[$k][0] += $series[$k][$t];
                    $sum[$k][1]++;
                    $days[$day][$k][0] += $series[$k][$t];
                    $days[$day][$k][1]++;
                }
            }
            $isMaint = false;
            foreach ($maint as [$ms, $me]) {
                if ($t > $ms && $t - $step < $me) {
                    $isMaint = true;
                    break;
                }
            }
            $state = null;
            if ($isMaint) {
                $inMaint++;
            } elseif ($up === null) {
                $nodata++;
            } elseif ($up < 0.5 || ($loss !== null && $loss >= $link->loss_down_pct)) {
                $state = $up < 0.5 ? 'down' : 'loss';
                $bad++;
                $days[$day]['bad']++;
            } else {
                $state = 'good';
                $good++;
                $days[$day]['good']++;
            }
            $detail['t'][] = $t;
            $detail['rtt'][] = $series['rtt'][$t] ?? null;
            $detail['jitter'][] = $series['jitter'][$t] ?? null;
            $detail['loss'][] = $loss;
            $detail['bad'][] = $state === 'down' || $state === 'loss' ? 1 : 0;

            // insiden: langkah gangguan berurutan
            if ($state === 'down' || $state === 'loss') {
                if ($cur) {
                    $cur['end'] = $t;
                    $cur['steps']++;
                    $cur['worst_loss'] = max($cur['worst_loss'], (float) $loss);
                    $cur['down'] = $cur['down'] || $state === 'down';
                } else {
                    $cur = ['start' => $t - $step, 'end' => $t, 'steps' => 1, 'worst_loss' => (float) $loss, 'down' => $state === 'down'];
                }
                $gap = 0;
            } elseif ($cur && $state === null && ! $isMaint && $gap === 0) {
                $gap = 1; // satu langkah tanpa data di tengah gangguan: tunggu langkah berikutnya
            } elseif ($cur) {
                $incidents[] = $cur;
                $cur = null;
                $gap = 0;
            }
        }
        $ongoing = $cur !== null && $e >= time() - 2 * $step;
        if ($cur) {
            $incidents[] = $cur + ['ongoing' => $ongoing];
        }
        $incidents = array_map(fn ($i) => [
            'start' => $i['start'], 'end' => ($i['ongoing'] ?? false) ? null : $i['end'], 'duration_s' => $i['steps'] * $step,
            'cause' => $i['down'] ? 'Down (ping gagal)' : 'Packet loss tinggi (puncak ' . self::num($i['worst_loss'], 0) . '%, ambang ' . self::num($link->loss_down_pct, 0) . '%)',
            'severity' => $i['down'] ? 'high' : 'average',
        ], $incidents);

        $avail = $good + $bad > 0 ? round($good / ($good + $bad) * 100, 3) : null;
        $avg = fn ($p) => $p[1] ? round($p[0] / $p[1], 2) : null;

        return [
            'step' => $step, 'start' => $s, 'end' => $e,
            'availability' => $avail, 'verdict' => $link->verdict($avail),
            'uptime_s' => $good * $step, 'downtime_s' => $bad * $step, 'nodata_s' => $nodata * $step, 'maint_s' => $inMaint * $step,
            'latency' => $avg($sum['rtt']), 'jitter' => $avg($sum['jitter']), 'loss' => $avg($sum['loss']), 'samples' => $samples,
            'days' => collect($days)->map(fn ($d, $k) => ['date' => $k, 'availability' => $d['good'] + $d['bad'] ? round($d['good'] / ($d['good'] + $d['bad']) * 100, 2) : null,
                'downtime_s' => $d['bad'] * $step, 'latency' => $avg($d['rtt']), 'jitter' => $avg($d['jitter']), 'loss' => $avg($d['loss'])])->values()->all(),
            'incidents' => $incidents,
            'detail' => self::downsample($detail),
        ];
    }

    /** Ringkas deret detail menjadi paling banyak MAX_DETAIL_POINTS titik (rata-rata; gangguan diambil maksimum). */
    private static function downsample(array $d): array
    {
        $n = count($d['t']);
        if ($n <= self::MAX_DETAIL_POINTS) {
            return $d;
        }
        $k = (int) ceil($n / self::MAX_DETAIL_POINTS);
        $out = ['t' => [], 'rtt' => [], 'jitter' => [], 'loss' => [], 'bad' => []];
        for ($i = 0; $i < $n; $i += $k) {
            $out['t'][] = $d['t'][min($n - 1, $i + $k - 1)];
            foreach (['rtt', 'jitter', 'loss'] as $c) {
                $vals = array_filter(array_slice($d[$c], $i, $k), fn ($v) => $v !== null);
                $out[$c][] = $vals ? round(array_sum($vals) / count($vals), 3) : null;
            }
            $out['bad'][] = max(array_slice($d['bad'], $i, $k));
        }

        return $out;
    }

    public static function num(?float $v, int $d = 2): string
    {
        return $v === null ? '' : number_format($v, $d, ',', '.');
    }

    /** "6h 23j 40m" untuk tampilan ringkas */
    public static function dur(int $s): string
    {
        $m = intdiv($s, 60);
        $parts = [];
        if ($m >= 1440) {
            $parts[] = intdiv($m, 1440) . ' hari';
            $m %= 1440;
        }
        if ($m >= 60) {
            $parts[] = intdiv($m, 60) . ' jam';
            $m %= 60;
        }
        if ($m || ! $parts) {
            $parts[] = $m . ' menit';
        }

        return implode(' ', $parts);
    }
}
