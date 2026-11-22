<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Metrics;
use Illuminate\Http\Request;

/**
 * Kualitas jaringan per perangkat dari ping tiap menit: rata-rata, p95, maksimum latensi, jitter, loss,
 * dan ketersediaan ping pada rentang 24 jam / 7 hari / 30 hari. Terburuk di atas.
 */
class QualityController extends Controller
{
    public const RANGES = ['24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'];

    public function index(Request $request, Metrics $m)
    {
        $range = array_key_exists($request->query('range'), self::RANGES) ? $request->query('range') : '24h';
        $q = fn (string $expr) => $m->instant($expr, 'device_id');
        $avg = $q("avg_over_time(antareja_ping_rtt_ms[$range])");
        $p95 = $q("quantile_over_time(0.95, antareja_ping_rtt_ms[$range])");
        $max = $q("max_over_time(antareja_ping_rtt_max_ms[$range])");
        $jit = $q("avg_over_time(antareja_ping_jitter_ms[$range])");
        $loss = $q("avg_over_time(antareja_ping_loss_pct[$range])");
        $up = $q("avg_over_time(antareja_ping_up[$range]) * 100");

        $defLat = (float) Setting::get('threshold.latency_ms', 150);
        $defLoss = (float) Setting::get('threshold.loss_pct', 20);
        $devices = Device::with('site')->where('enabled', true)->where('ping_enabled', true)
            ->when($request->integer('site'), fn ($x, $s) => $x->where('site_id', $s))->get();

        $rows = $devices->map(function ($d) use ($avg, $p95, $max, $jit, $loss, $up, $defLat, $defLoss) {
            $id = (string) $d->id;
            $lat = (float) ($d->thresholds['latency_ms'] ?? $defLat);
            $lossTh = (float) ($d->thresholds['loss_pct'] ?? $defLoss);
            $r = ['device' => $d, 'avg' => $avg[$id] ?? null, 'p95' => $p95[$id] ?? null, 'max' => $max[$id] ?? null,
                'jitter' => $jit[$id] ?? null, 'loss' => $loss[$id] ?? null, 'up' => $up[$id] ?? null, 'lat_th' => $lat, 'loss_th' => $lossTh];
            // Skor buruk: loss & ketersediaan paling berat, lalu p95 relatif ambang, lalu jitter
            $r['score'] = ($r['loss'] ?? 0) * 3 + (100 - ($r['up'] ?? 100)) * 5 + (($r['p95'] ?? 0) / max(1, $lat)) * 10 + ($r['jitter'] ?? 0) / 5;
            $r['tone'] = match (true) {
                $r['loss'] !== null && $r['loss'] > $lossTh => 'crit', // termasuk perangkat yang tidak menjawab sama sekali
                $r['avg'] === null => 'muted',
                ($r['loss'] ?? 0) > $lossTh || ($r['p95'] ?? 0) > $lat * 1.5 => 'crit',
                ($r['loss'] ?? 0) > 1 || ($r['p95'] ?? 0) > $lat => 'warn',
                default => 'ok',
            };

            return $r;
        })->sortByDesc('score')->values();

        return view('quality.index', ['rows' => $rows, 'range' => $range, 'sites' => Site::orderBy('code')->get(),
            'hasData' => $rows->contains(fn ($r) => $r['avg'] !== null)]);
    }
}
