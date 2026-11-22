<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Site;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan ketersediaan (SLA) bulanan.
 * Downtime = durasi problem "down" (termasuk yang terdampak induk) yang beririsan dengan bulan tersebut,
 * dikurangi bagian yang jatuh di dalam jendela maintenance perangkat itu. Waktu dipantau dihitung sejak perangkat ditambahkan.
 */
class ReportController extends Controller
{
    private function compute(Carbon $start, Carbon $end, ?int $siteId): array
    {
        $rows = DB::select(<<<'SQL'
            WITH p AS (SELECT ?::timestamptz AS s, LEAST(?::timestamptz, now()) AS e),
            -- irisan tiap gangguan "down" dengan periode laporan
            outage AS (
                SELECT pr.id, pr.device_id, pr.started_at, pr.resolved_at,
                       GREATEST(pr.started_at, p.s) AS os, LEAST(COALESCE(pr.resolved_at, now()), p.e) AS oe
                FROM problems pr CROSS JOIN p
                WHERE pr.kind = 'down' AND pr.started_at < p.e AND COALESCE(pr.resolved_at, now()) > p.s
            ),
            -- dikurangi bagian yang jatuh di dalam jendela maintenance perangkat tsb (berdasarkan waktu, bukan status terakhir)
            net AS (
                SELECT o.*, GREATEST(0, EXTRACT(EPOCH FROM (o.oe - o.os)) - COALESCE((
                    SELECT SUM(EXTRACT(EPOCH FROM (LEAST(o.oe, m.ends_at) - GREATEST(o.os, m.starts_at))))
                    FROM maintenances m JOIN devices dd ON dd.id = o.device_id
                    WHERE m.starts_at < o.oe AND m.ends_at > o.os
                      AND (m.scope = 'all' OR (m.scope = 'site' AND m.site_id = dd.site_id)
                           OR (m.scope = 'devices' AND EXISTS (SELECT 1 FROM maintenance_devices md WHERE md.maintenance_id = m.id AND md.device_id = o.device_id)))
                ), 0)) AS down_s
                FROM outage o
            )
            SELECT d.id, d.name, d.host, d.category, s.code AS site, s.id AS site_id,
                EXTRACT(EPOCH FROM (p.e - GREATEST(p.s, d.created_at))) AS monitored,
                COALESCE(SUM(n.down_s), 0) AS down_s,
                COUNT(n.id) FILTER (WHERE n.down_s > 0) AS incidents,
                AVG(EXTRACT(EPOCH FROM (n.resolved_at - n.started_at))) FILTER (WHERE n.resolved_at IS NOT NULL AND n.down_s > 0) AS mttr
            FROM devices d CROSS JOIN p
            LEFT JOIN sites s ON s.id = d.site_id
            LEFT JOIN net n ON n.device_id = d.id
            WHERE d.created_at < p.e AND (?::bigint IS NULL OR d.site_id = ?::bigint)
            GROUP BY d.id, d.name, d.host, d.category, s.code, s.id, p.s, p.e
            ORDER BY d.name
        SQL, [$start, $end, $siteId, $siteId]);

        $devices = collect($rows)->map(function ($r) {
            $mon = max(0.0, (float) $r->monitored);
            $down = min($mon, max(0.0, (float) $r->down_s));

            return ['id' => $r->id, 'name' => $r->name, 'host' => $r->host, 'site' => $r->site, 'site_id' => $r->site_id,
                'monitored' => $mon, 'down' => $down, 'incidents' => (int) $r->incidents, 'mttr' => $r->mttr !== null ? (float) $r->mttr : null,
                'availability' => $mon > 0 ? round((1 - $down / $mon) * 100, 3) : null];
        })->filter(fn ($d) => $d['monitored'] > 0)->values();

        $agg = function ($list) {
            $mon = $list->sum('monitored');

            return ['devices' => $list->count(), 'down' => $list->sum('down'), 'incidents' => $list->sum('incidents'),
                'availability' => $mon > 0 ? round((1 - $list->sum('down') / $mon) * 100, 3) : null];
        };

        return [
            'devices' => $devices,
            'sites' => $devices->groupBy(fn ($d) => $d['site'] ?? '—')->map($agg)->sortKeys(),
            'total' => $agg($devices),
        ];
    }

    private function period(Request $request): array
    {
        $m = $request->query('bulan');
        $start = preg_match('/^\d{4}-\d{2}$/', (string) $m) ? Carbon::createFromFormat('Y-m-d H:i:s', "$m-01 00:00:00", config('app.timezone')) : now()->startOfMonth();

        return [$start->copy()->startOfMonth(), $start->copy()->addMonthNoOverflow()->startOfMonth()];
    }

    public function sla(Request $request)
    {
        [$start, $end] = $this->period($request);
        $siteId = $request->integer('site') ?: null;
        $data = $this->compute($start, $end, $siteId);
        $months = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i));

        return view('reports.sla', $data + [
            'start' => $start, 'end' => $end, 'months' => $months, 'sitesAll' => Site::orderBy('code')->get(),
            'target' => (float) Setting::get('sla.target_pct', 99.5),
            'partial' => $end->isFuture(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$start, $end] = $this->period($request);
        $data = $this->compute($start, $end, $request->integer('site') ?: null);
        $name = 'sla-antareja-' . $start->format('Y-m') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, ['Perangkat', 'Alamat', 'Site', 'Ketersediaan (%)', 'Downtime (menit)', 'Jumlah gangguan', 'Rata-rata pemulihan (menit)'], ';');
            foreach ($data['devices'] as $d) {
                fputcsv($out, [$d['name'], $d['host'], $d['site'] ?? '', str_replace('.', ',', (string) $d['availability']), str_replace('.', ',', (string) round($d['down'] / 60, 1)),
                    $d['incidents'], $d['mttr'] !== null ? str_replace('.', ',', (string) round($d['mttr'] / 60, 1)) : ''], ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function target(Request $request)
    {
        $d = $request->validate(['target' => ['required', 'numeric', 'between:50,100']]);
        Setting::put('sla.target_pct', (float) $d['target']);
        Audit::log('updated', "Target SLA diubah menjadi {$d['target']}%");

        return back()->with('ok', "Target SLA {$d['target']}% disimpan.");
    }
}
