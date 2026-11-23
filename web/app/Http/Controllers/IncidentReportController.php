<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Site;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan insiden bulanan: insiden yang DIBUKA dalam bulan itu (filter site lewat site insiden).
 * MTTA = dibuka sampai ditangani, MTTR = dibuka sampai pulih. Lama gangguan = dibuka sampai pulih/ditutup/sekarang.
 */
class IncidentReportController extends Controller
{
    private function period(Request $request): array
    {
        $m = (string) $request->query('bulan');
        $start = preg_match('/^\d{4}-\d{2}$/', $m) ? Carbon::createFromFormat('Y-m-d H:i:s', "$m-01 00:00:00", config('app.timezone')) : now()->startOfMonth();

        return [$start->copy()->startOfMonth(), $start->copy()->addMonthNoOverflow()->startOfMonth()];
    }

    private const DUR = "EXTRACT(EPOCH FROM (COALESCE(recovered_at, closed_at, now()) - opened_at))";

    private function base(Carbon $start, Carbon $end, ?int $site)
    {
        return DB::table('incidents')->where('opened_at', '>=', $start)->where('opened_at', '<', $end)->when($site, fn ($q) => $q->where('site_id', $site));
    }

    public function index(Request $request)
    {
        [$start, $end] = $this->period($request);
        $site = $request->integer('site') ?: null;
        $d = self::DUR;

        $sum = $this->base($start, $end, $site)->selectRaw("count(*) AS n,
                count(*) FILTER (WHERE status IN ('open','handling')) AS active,
                avg(EXTRACT(EPOCH FROM (handled_at - opened_at))) FILTER (WHERE handled_at IS NOT NULL) AS mtta,
                avg(EXTRACT(EPOCH FROM (recovered_at - opened_at))) FILTER (WHERE recovered_at IS NOT NULL) AS mttr,
                sum($d) AS total,
                count(*) FILTER (WHERE status IN ('recovered','closed')) AS done,
                count(*) FILTER (WHERE status IN ('recovered','closed') AND COALESCE(cause,'') <> '' AND COALESCE(action_taken,'') <> '') AS rca,
                count(*) FILTER (WHERE auto) AS auto")->first();
        $bySev = $this->base($start, $end, $site)->selectRaw('severity, count(*) AS n')->groupBy('severity')->pluck('n', 'severity');
        $bySite = $this->base($start, $end, $site)->leftJoin('sites', 'sites.id', '=', 'incidents.site_id')
            ->selectRaw("COALESCE(sites.code, 'Tanpa site') AS site, count(*) AS n, avg(EXTRACT(EPOCH FROM (recovered_at - opened_at))) FILTER (WHERE recovered_at IS NOT NULL) AS mttr, sum($d) AS total")
            ->groupByRaw("COALESCE(sites.code, 'Tanpa site')")->orderByDesc('n')->get();
        $byOwner = $this->base($start, $end, $site)->join('users', 'users.id', '=', 'incidents.owner_id')
            ->selectRaw('users.name AS owner, count(*) AS n, avg(EXTRACT(EPOCH FROM (handled_at - opened_at))) AS mtta, avg(EXTRACT(EPOCH FROM (recovered_at - opened_at))) FILTER (WHERE recovered_at IS NOT NULL) AS mttr')
            ->groupBy('users.name')->orderByDesc('n')->get();
        $unowned = $this->base($start, $end, $site)->whereNull('owner_id')->count();

        $list = Incident::with(['site', 'owner'])->withCount('problems')->where('opened_at', '>=', $start)->where('opened_at', '<', $end)
            ->when($site, fn ($q) => $q->where('site_id', $site))->orderByRaw("$d DESC")->get();

        $trend = DB::table('incidents')->where('opened_at', '>=', now()->startOfMonth()->subMonthsNoOverflow(11))->when($site, fn ($q) => $q->where('site_id', $site))
            ->selectRaw("to_char(date_trunc('month', opened_at AT TIME ZONE ?), 'YYYY-MM') AS m, count(*) AS n, avg(EXTRACT(EPOCH FROM (recovered_at - opened_at))) FILTER (WHERE recovered_at IS NOT NULL) AS mttr", [config('app.timezone')])
            ->groupBy('m')->pluck('mttr', 'm')->map(fn ($v) => $v);
        $trendN = DB::table('incidents')->where('opened_at', '>=', now()->startOfMonth()->subMonthsNoOverflow(11))->when($site, fn ($q) => $q->where('site_id', $site))
            ->selectRaw("to_char(date_trunc('month', opened_at AT TIME ZONE ?), 'YYYY-MM') AS m, count(*) AS n", [config('app.timezone')])->groupBy('m')->pluck('n', 'm');
        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i));

        return view('reports.incidents', [
            'start' => $start, 'partial' => $end->isFuture(), 'sum' => $sum, 'bySev' => $bySev, 'bySite' => $bySite, 'byOwner' => $byOwner, 'unowned' => $unowned,
            'list' => $list, 'trend' => $months->map(fn ($m) => ['m' => $m, 'n' => (int) ($trendN[$m->format('Y-m')] ?? 0), 'mttr' => $trend[$m->format('Y-m')] ?? null]),
            'monthsSel' => $months->reverse()->values(), 'sites' => Site::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$start, $end] = $this->period($request);
        $site = $request->integer('site') ?: null;
        $rows = Incident::with(['site', 'owner'])->withCount('problems')->where('opened_at', '>=', $start)->where('opened_at', '<', $end)
            ->when($site, fn ($q) => $q->where('site_id', $site))->orderBy('opened_at')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Judul', 'Severity', 'Status', 'Site', 'Penanggung jawab', 'Otomatis', 'Problem', 'Dibuka', 'Ditangani', 'Pulih', 'Ditutup',
                'Sampai ditangani (menit)', 'Lama gangguan (menit)', 'Penyebab', 'Tindakan'], ';');
            $min = fn ($a, $b) => $a && $b ? str_replace('.', ',', (string) round($a->diffInSeconds($b) / 60, 1)) : '';
            foreach ($rows as $i) {
                fputcsv($out, [$i->id, $i->title, $i->sevLabel(), $i->statusLabel(), $i->site?->code, $i->owner?->name, $i->auto ? 'ya' : 'tidak', $i->problems_count,
                    $i->opened_at->format('Y-m-d H:i'), $i->handled_at?->format('Y-m-d H:i'), $i->recovered_at?->format('Y-m-d H:i'), $i->closed_at?->format('Y-m-d H:i'),
                    $min($i->opened_at, $i->handled_at), str_replace('.', ',', (string) round($i->downtimeSeconds() / 60, 1)), $i->cause, $i->action_taken], ';');
            }
            fclose($out);
        }, 'insiden-antareja-' . $start->format('Y-m') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function dur($s): string
    {
        return $s === null ? 'belum ada' : Fmt::duration((int) round((float) $s));
    }
}
