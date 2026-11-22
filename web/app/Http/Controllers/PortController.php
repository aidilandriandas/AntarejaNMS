<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Port;
use App\Services\Metrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Halaman detail port: grafik trafik (bit/s), pemakaian, paket, error & discard, statistik per rentang
 * (sekarang, rata-rata, maks, persentil 95, total volume), tetangga LLDP/CDP, alamat IP, dan trafik live.
 */
class PortController extends Controller
{
    public const RANGES = ['6h' => [6 * 3600, '6 jam'], '24h' => [86400, '24 jam'], '7d' => [7 * 86400, '7 hari'],
        '30d' => [30 * 86400, '30 hari'], '1y' => [365 * 86400, '1 tahun']];

    private function port(Device $device, int $ifIndex): Port
    {
        return $device->ports()->where('if_index', $ifIndex)->firstOrFail();
    }

    public function show(Device $device, int $ifIndex)
    {
        $port = $this->port($device, $ifIndex);
        $device->load('site');

        return view('ports.show', [
            'device' => $device, 'port' => $port,
            'monitored' => in_array($ifIndex, array_map('intval', $device->monitoredPorts())),
            'neighbors' => DB::table('neighbors as n')->leftJoin('devices as d', 'd.id', '=', 'n.remote_device_id')
                ->where('n.device_id', $device->id)->where('n.local_if_index', $ifIndex)
                ->get(['n.protocol', 'n.remote_name', 'n.remote_port', 'n.remote_ip', 'n.remote_platform', 'n.remote_device_id', 'd.name as dev_name']),
            'ips' => DB::table('device_ips')->where('device_id', $device->id)->where('if_index', $ifIndex)->orderBy('ip')->get(['ip', 'prefix']),
            'links' => DB::table('device_links as l')->join('devices as d', fn ($j) => $j->on('d.id', '=', DB::raw("CASE WHEN l.a_device_id = {$device->id} THEN l.b_device_id ELSE l.a_device_id END")))
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('l.a_device_id', $device->id)->where('l.a_if_index', $ifIndex))
                    ->orWhere(fn ($q) => $q->where('l.b_device_id', $device->id)->where('l.b_if_index', $ifIndex)))
                ->get(['d.id', 'd.name', 'l.note']),
            'ports' => $device->ports()->orderBy('if_index')->get(['if_index', 'name', 'descr', 'alias']),
        ]);
    }

    /** Data grafik & statistik untuk satu rentang. */
    public function data(Request $request, Device $device, int $ifIndex, Metrics $metrics): JsonResponse
    {
        $port = $this->port($device, $ifIndex);
        [$range] = self::RANGES[$request->query('range', '24h')] ?? self::RANGES['24h'];
        $s = 'device_id="' . $device->id . '",if_index="' . $ifIndex . '"';
        $step = max(60, intdiv($range, 400));
        $win = max(2 * $step, 600) . 's';
        $r = fn ($m) => "rate(antareja_if_{$m}{{$s}}[{$win}])";

        $g = $metrics->range([
            'in' => $r('in_octets_total') . ' * 8', 'out' => $r('out_octets_total') . ' * 8',
            'in_ucast' => $r('in_ucast_pkts_total'), 'out_ucast' => $r('out_ucast_pkts_total'),
            'in_mcast' => $r('in_mcast_pkts_total'), 'out_mcast' => $r('out_mcast_pkts_total'),
            'in_bcast' => $r('in_bcast_pkts_total'), 'out_bcast' => $r('out_bcast_pkts_total'),
            'in_err' => $r('in_errors_total'), 'out_err' => $r('out_errors_total'),
            'in_disc' => $r('in_discards_total'), 'out_disc' => $r('out_discards_total'),
        ], $range);

        // Statistik dari sampel 5 menit (bukan dari titik grafik yang sudah dirata-rata) agar puncak & persentil 95 akurat
        $stat = function (string $dir) use ($metrics, $s, $range) {
            $sub = "(rate(antareja_if_{$dir}_octets_total{{$s}}[10m]) * 8)[{$range}s:5m]";

            return [
                'now' => $metrics->scalar("rate(antareja_if_{$dir}_octets_total{{$s}}[15m]) * 8"),
                'avg' => $metrics->scalar("avg_over_time({$sub})"),
                'max' => $metrics->scalar("max_over_time({$sub})"),
                'p95' => $metrics->scalar("quantile_over_time(0.95, {$sub})"),
                'bytes' => $metrics->scalar("increase(antareja_if_{$dir}_octets_total{{$s}}[{$range}s])"),
                'errors' => $metrics->scalar("increase(antareja_if_{$dir}_errors_total{{$s}}[{$range}s])"),
                'discards' => $metrics->scalar("increase(antareja_if_{$dir}_discards_total{{$s}}[{$range}s])"),
            ];
        };

        return response()->json($g + ['speed' => (int) $port->speed_bps, 'stats' => ['in' => $stat('in'), 'out' => $stat('out')]]);
    }
}
