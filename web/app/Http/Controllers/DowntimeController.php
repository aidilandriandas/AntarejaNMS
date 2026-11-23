<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Persentase down per perangkat per bulan dari tabel downtime_monthly (diisi collector tiap jam, rumus sama dengan
 * laporan SLA). % down = waktu down (di luar maintenance) dibagi waktu dipantau dalam bulan itu.
 */
class DowntimeController extends Controller
{
    private function filters(Request $r): array
    {
        $f = $r->validate([
            'sampai' => ['nullable', 'date_format:Y-m'],
            'bulan' => ['nullable', 'integer', 'in:3,6,12,24'],
            'site' => ['nullable', 'string', 'max:64'],
            'kategori' => ['nullable', 'string', 'max:32'],
            'pernah_down' => ['nullable', 'boolean'],
        ]);
        $end = isset($f['sampai']) ? Carbon::createFromFormat('Y-m-d', $f['sampai'] . '-01')->startOfMonth() : now()->startOfMonth();
        $n = (int) ($f['bulan'] ?? 12);

        return [$f, $end->copy()->subMonthsNoOverflow($n - 1), $end, $n];
    }

    /** Baris downtime_monthly dalam rentang, dengan nama/kategori terkini bila perangkat masih ada. */
    private function rows(array $f, Carbon $from, Carbon $to): Collection
    {
        return DB::table('downtime_monthly as m')->leftJoin('devices as d', 'd.id', '=', 'm.device_id')
            ->whereBetween('m.month', [$from->toDateString(), $to->toDateString()])
            ->when(! empty($f['site']), fn ($q) => $q->where('m.site', $f['site']))
            ->when(! empty($f['kategori']), fn ($q) => $q->where('d.category', $f['kategori']))
            ->orderBy('m.month')
            ->get(['m.month', 'm.device_id', 'm.device_name', 'm.host', 'm.site', 'm.monitored_s', 'm.down_s', 'm.outages', 'm.final',
                'd.name as cur_name', 'd.category']);
    }

    public function index(Request $request)
    {
        [$f, $from, $to, $n] = $this->filters($request);
        $months = collect(range(0, $n - 1))->map(fn ($i) => $from->copy()->addMonthsNoOverflow($i));
        $target = (float) Setting::get('sla.target_pct', 99.5);
        $rows = $this->rows($f, $from, $to);

        // matriks perangkat x bulan
        $devices = $rows->groupBy(fn ($r) => $r->device_id ? 'd' . $r->device_id : 'x' . $r->device_name . '|' . $r->host)->map(function ($g) {
            $last = $g->last();
            $cells = $g->keyBy(fn ($r) => substr($r->month, 0, 7));
            $mon = $g->sum('monitored_s');
            $down = $g->sum('down_s');

            return (object) ['id' => $last->device_id, 'name' => $last->cur_name ?? $last->device_name, 'deleted' => ! $last->device_id,
                'host' => $last->host, 'site' => $last->site, 'cells' => $cells, 'pct' => $mon > 0 ? $down / $mon * 100 : null,
                'down' => $down, 'outages' => (int) $g->sum('outages')];
        });
        if (! empty($f['pernah_down'])) {
            $devices = $devices->filter(fn ($d) => $d->down > 0);
        }
        $devices = $devices->sortBy([fn ($a, $b) => ($b->pct ?? -1) <=> ($a->pct ?? -1), fn ($a, $b) => $b->down <=> $a->down, fn ($a, $b) => strcmp($a->name, $b->name)])->values();

        // total per site & keseluruhan per bulan
        $agg = fn ($g) => (object) ['mon' => $g->sum('monitored_s'), 'down' => $g->sum('down_s'), 'outages' => (int) $g->sum('outages'), 'devices' => $g->count()];
        $bySite = $rows->groupBy('site')->map(fn ($g) => $g->groupBy(fn ($r) => substr($r->month, 0, 7))->map($agg))->sortKeys();
        $total = $rows->groupBy(fn ($r) => substr($r->month, 0, 7))->map($agg);

        return view('reports.downtime', [
            'f' => $f, 'months' => $months, 'n' => $n, 'to' => $to, 'target' => $target, 'devices' => $devices, 'bySite' => $bySite, 'total' => $total,
            'sites' => Site::orderBy('code')->pluck('code'), 'categories' => Device::CATEGORIES,
            'monthsSel' => collect(range(0, 23))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i)),
            'lastComputed' => DB::table('downtime_monthly')->max('computed_at'),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$f, $from, $to] = $this->filters($request);
        $rows = $this->rows($f, $from, $to);
        $name = 'down-bulanan-' . $from->format('Y-m') . '-sd-' . $to->format('Y-m') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Bulan', 'Perangkat', 'Host', 'Site', 'Jam dipantau', 'Menit down', 'Persen down', 'Ketersediaan', 'Gangguan', 'Status'], ';');
            foreach ($rows as $r) {
                $pct = $r->monitored_s > 0 ? $r->down_s / $r->monitored_s * 100 : 0;
                fputcsv($o, [substr($r->month, 0, 7), $r->cur_name ?? $r->device_name, $r->host, $r->site, number_format($r->monitored_s / 3600, 1, ',', ''),
                    number_format($r->down_s / 60, 1, ',', ''), number_format($pct, 3, ',', ''), number_format(100 - $pct, 3, ',', ''), $r->outages,
                    $r->final ? 'final' : 'sementara'], ';');
            }
            fclose($o);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Teks persen down: "0", "<0,01", "0,25", "12,5". */
    public static function pct(?float $v): string
    {
        if ($v === null) {
            return 'tidak dipantau';
        }
        if ($v <= 0) {
            return '0';
        }
        if ($v < 0.01) {
            return '<0,01';
        }

        return rtrim(rtrim(number_format($v, $v < 1 ? 2 : 1, ',', '.'), '0'), ',');
    }
}
