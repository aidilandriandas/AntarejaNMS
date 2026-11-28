<?php

namespace App\Services;

use App\Models\IspLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SLA link ISP dari VictoriaMetrics.
 *
 * Link berbasis port (router + port ke ISP): langkah gangguan bila port down (status port dari SNMP), atau bila ping
 * ke ujung ISP dipilih dan ping gagal / loss tinggi. Trafik masuk dan keluar port dihitung per langkah (rata-rata,
 * puncak, persentil 95) dan dibandingkan dengan bandwidth kontrak. Link lama tanpa port: dari ping perangkatnya.
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

    /** Selang baca SNMP collector (status & trafik port terbaca sekali tiap selang ini). */
    public const SNMP_INTERVAL = 300;

    public function report(IspLink $link, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $s = $start->getTimestamp();
        $e = min($end->getTimestamp(), time());
        $port = $link->if_index !== null;
        $pingDev = $port ? $link->ping_device_id : $link->device_id;
        // Tanpa ping, langkah tidak lebih rapat dari selang SNMP (status port hanya terbaca tiap 5 menit)
        $step = $port && ! $pingDev ? max(self::SNMP_INTERVAL, self::step(max(60, $e - $s))) : self::step(max(60, $e - $s));
        $w = '[' . $step . 's]';
        $get = function (string $q) use ($s, $e, $step) {
            $m = $this->metrics->matrix($q, $s + $step, $e, $step);

            return $m[0][1] ?? [];
        };
        $series = ['up' => [], 'loss' => [], 'rtt' => [], 'jitter' => [], 'count' => []];
        if ($pingDev) {
            $sel = '{device_id="' . (int) $pingDev . '"}';
            $series = [
                'up' => $get("avg_over_time(antareja_ping_up$sel$w)"),
                'loss' => $get("avg_over_time(antareja_ping_loss_pct$sel$w)"),
                'rtt' => $get("avg_over_time(antareja_ping_rtt_ms$sel$w)"),
                'jitter' => $get("avg_over_time(antareja_ping_jitter_ms$sel$w)"),
                'count' => $get("count_over_time(antareja_ping_up$sel$w)"),
            ];
        }
        if ($port) {
            $psel = '{device_id="' . (int) $link->device_id . '",if_index="' . (int) $link->if_index . '"}';
            // jendela sedikit lebih lebar dari selang SNMP supaya tiap langkah memuat minimal satu sampel
            $pw = '[' . max($step, self::SNMP_INTERVAL + 60) . 's]';
            $series['port'] = $get("min_over_time(antareja_if_oper_up$psel$pw)");
            $series['in'] = array_map(fn ($v) => $v * 8 / 1e6, $get("rate(antareja_if_in_octets_total$psel$pw)"));
            $series['out'] = array_map(fn ($v) => $v * 8 / 1e6, $get("rate(antareja_if_out_octets_total$psel$pw)"));
            $speed = $get("max_over_time(antareja_if_speed_bps$psel$pw)");
            $series['speed_mbps'] = $speed ? max($speed) / 1e6 : null;
        }

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
        $detail = ['t' => [], 'rtt' => [], 'jitter' => [], 'loss' => [], 'bad' => []] + (isset($series['in']) ? ['in' => [], 'out' => []] : []);
        for ($t = $s + $step; $t <= $e; $t += $step) {
            $day = CarbonImmutable::createFromTimestamp($t - 1, $tz)->format('Y-m-d');
            $days[$day] ??= ['good' => 0, 'bad' => 0, 'rtt' => [0, 0], 'jitter' => [0, 0], 'loss' => [0, 0]];
            $up = $series['up'][$t] ?? null;
            $loss = $series['loss'][$t] ?? null;
            $portUp = isset($series['port']) ? ($series['port'][$t] ?? null) : null;
            if (isset($series['port'])) {
                // link port: port menentukan ada/tidaknya data; ping (bila dipilih) menambah syarat
                $up = $portUp === null ? $up : ($portUp < 0.5 ? 0.0 : ($up ?? 1.0));
            }
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
                $state = $portUp !== null && $portUp < 0.5 ? 'port' : ($up < 0.5 ? 'down' : 'loss');
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
            $detail['bad'][] = in_array($state, ['down', 'loss', 'port'], true) ? 1 : 0;
            if (isset($series['in'])) {
                $detail['in'][] = isset($series['in'][$t]) ? round($series['in'][$t], 3) : null;
                $detail['out'][] = isset($series['out'][$t]) ? round($series['out'][$t], 3) : null;
            }

            // insiden: langkah gangguan berurutan
            if (in_array($state, ['down', 'loss', 'port'], true)) {
                if ($cur) {
                    $cur['end'] = $t;
                    $cur['steps']++;
                    $cur['worst_loss'] = max($cur['worst_loss'], (float) $loss);
                    $cur['down'] = $cur['down'] || $state !== 'loss';
                    $cur['port'] = $cur['port'] || $state === 'port';
                } else {
                    $cur = ['start' => $t - $step, 'end' => $t, 'steps' => 1, 'worst_loss' => (float) $loss, 'down' => $state !== 'loss', 'port' => $state === 'port'];
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
            'cause' => $i['port'] ? 'Port ke ISP down' : ($i['down'] ? 'Down (ping gagal)' : 'Packet loss tinggi (puncak ' . self::num($i['worst_loss'], 0) . '%, ambang ' . self::num($link->loss_down_pct, 0) . '%)'),
            'severity' => $i['down'] ? 'high' : 'average',
        ], $incidents);
        $traffic = isset($series['in']) ? self::traffic($series, $link) : null;

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
            'traffic' => $traffic,
            'detail' => self::downsample($detail),
        ];
    }

    /**
     * Trafik port (Mbps): rata-rata, puncak, persentil 95 per arah, dan pemakaian persentil 95 terhadap bandwidth
     * kontrak (atau kecepatan port bila kontrak tidak diisi).
     */
    public static function traffic(array $series, IspLink $link): array
    {
        $cap = $link->bandwidth_mbps ?: ($series['speed_mbps'] ?? null);
        $one = function (array $v) use ($cap) {
            $v = array_values(array_filter($v, fn ($x) => $x !== null));
            if (! $v) {
                return null;
            }
            sort($v);
            $p95 = $v[max(0, (int) ceil(count($v) * 0.95) - 1)];

            return ['avg' => round(array_sum($v) / count($v), 2), 'max' => round(max($v), 2), 'p95' => round($p95, 2),
                'util_p95' => $cap ? round($p95 / $cap * 100, 1) : null];
        };

        return ['in' => $one($series['in'] ?? []), 'out' => $one($series['out'] ?? []), 'capacity_mbps' => $cap,
            'capacity_from' => $link->bandwidth_mbps ? 'kontrak' : ($cap ? 'port' : null)];
    }

    /** Ringkas deret detail menjadi paling banyak MAX_DETAIL_POINTS titik (rata-rata; gangguan diambil maksimum). */
    private static function downsample(array $d): array
    {
        $n = count($d['t']);
        if ($n <= self::MAX_DETAIL_POINTS) {
            return $d;
        }
        $k = (int) ceil($n / self::MAX_DETAIL_POINTS);
        $cols = array_values(array_intersect(['rtt', 'jitter', 'loss', 'in', 'out'], array_keys($d)));
        $out = ['t' => [], 'bad' => []] + array_fill_keys($cols, []);
        for ($i = 0; $i < $n; $i += $k) {
            $out['t'][] = $d['t'][min($n - 1, $i + $k - 1)];
            foreach ($cols as $c) {
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
