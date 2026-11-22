<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Audit;
use App\Services\Capacity;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Perencanaan kapasitas port yang dipantau. */
class CapacityController extends Controller
{
    private const ORDER = ['over' => 0, 'soon' => 1, 'watch' => 2, 'ok' => 3, 'short' => 4, 'nospeed' => 5, 'nodata' => 6];

    private function rows(Request $request, Capacity $cap): array
    {
        $days = (int) $request->query('hari', 90);
        $days = array_key_exists($days, Capacity::WINDOWS) ? $days : 90;
        $rows = $cap->ports($days);
        usort($rows, fn ($a, $b) => [self::ORDER[$a['state']], $a['days_left'] ?? INF, -($a['util'] ?? 0)] <=> [self::ORDER[$b['state']], $b['days_left'] ?? INF, -($b['util'] ?? 0)]);

        return [$days, $rows];
    }

    public function index(Request $request, Capacity $cap)
    {
        [$days, $rows] = $this->rows($request, $cap);

        return view('capacity.index', ['rows' => $rows, 'days' => $days, 'threshold' => Capacity::threshold()]);
    }

    public function csv(Request $request, Capacity $cap): StreamedResponse
    {
        [$days, $rows] = $this->rows($request, $cap);
        $n = fn ($v, $d = 1) => $v === null ? '' : str_replace('.', ',', (string) round($v, $d));

        return response()->streamDownload(function () use ($rows, $n) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Perangkat', 'Port', 'Keterangan', 'Site', 'Kecepatan (Mbps)', 'Puncak 7 hari (Mbps)', 'Pemakaian (%)', 'Tren (Mbps per 30 hari)', 'Perkiraan penuh', 'Status', 'Hari data'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['device'], $r['port'], $r['alias'], $r['site'], $n($r['speed'] / 1e6, 0), $n($r['current'] !== null ? $r['current'] / 1e6 : null, 2),
                    $n($r['util']), $n($r['slope'] !== null ? $r['slope'] * 30 / 1e6 : null, 2), $r['full_at'] ? date('Y-m-d', $r['full_at']) : '',
                    Capacity::STATES[$r['state']][0], $r['days']], ';');
            }
            fclose($out);
        }, 'kapasitas-antareja-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function threshold(Request $request)
    {
        $d = $request->validate(['threshold' => ['required', 'numeric', 'between:10,100']], [], ['threshold' => 'batas kapasitas']);
        Setting::put('capacity.threshold_pct', (float) $d['threshold']);
        Audit::log('updated', "Batas kapasitas port diubah menjadi {$d['threshold']}%");

        return back()->with('ok', "Batas kapasitas {$d['threshold']}% disimpan.");
    }
}
