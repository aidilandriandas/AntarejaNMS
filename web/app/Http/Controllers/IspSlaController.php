<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\IspLink;
use App\Services\Audit;
use App\Services\IspSla;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laporan SLA link ISP (semua peran) dan pengaturan link (engineer & admin). */
class IspSlaController extends Controller
{
    public const PERIODS = ['minggu-lalu' => 'Minggu lalu', 'minggu-ini' => 'Minggu ini', 'bulan-lalu' => 'Bulan lalu', 'bulan-ini' => 'Bulan ini', 'kustom' => 'Pilih tanggal'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: string} mulai, akhir (eksklusif), kunci, label */
    public static function period(Request $r): array
    {
        $tz = config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $p = array_key_exists($r->query('periode'), self::PERIODS) ? $r->query('periode') : 'minggu-lalu';
        [$s, $e] = match ($p) {
            'minggu-ini' => [$now->startOfWeek(), $now->startOfWeek()->addWeek()],
            'bulan-lalu' => [$now->startOfMonth()->subMonthNoOverflow(), $now->startOfMonth()],
            'bulan-ini' => [$now->startOfMonth(), $now->startOfMonth()->addMonthNoOverflow()],
            'kustom' => [null, null],
            default => [$now->startOfWeek()->subWeek(), $now->startOfWeek()],
        };
        if ($p === 'kustom') {
            $d = $r->validate(['dari' => ['required', 'date_format:Y-m-d'], 'sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:dari']]);
            $s = CarbonImmutable::createFromFormat('Y-m-d', $d['dari'], $tz)->startOfDay();
            $e = CarbonImmutable::createFromFormat('Y-m-d', $d['sampai'], $tz)->startOfDay()->addDay();
            if ($s->diffInDays($e) > 92) {
                throw ValidationException::withMessages(['sampai' => 'Rentang paling panjang 92 hari.']);
            }
        }
        $label = $s->translatedFormat('j M Y') . ' sampai ' . $e->subSecond()->translatedFormat('j M Y');
        if (str_starts_with($p, 'minggu')) {
            $label = 'Minggu ' . $s->isoWeek() . ', ' . $label;
        }

        return [$s, $e, $p, $label];
    }

    public function index(Request $request, IspSla $sla)
    {
        [$s, $e, $p, $label] = self::period($request);
        $links = IspLink::with('device.site')->orderBy('name')->get();
        $rows = $links->map(fn ($l) => ['link' => $l, 'r' => $sla->report($l, $s, $e)]);

        return view('isp.index', ['rows' => $rows, 'periode' => $p, 'label' => $label, 'start' => $s, 'end' => $e]);
    }

    public function show(Request $request, IspLink $isp, IspSla $sla)
    {
        [$s, $e, $p, $label] = self::period($request);
        $isp->load('device.site');

        return view('isp.show', ['link' => $isp, 'r' => $sla->report($isp, $s, $e), 'periode' => $p, 'label' => $label, 'start' => $s, 'end' => $e]);
    }

    public function csv(Request $request, IspLink $isp, IspSla $sla): StreamedResponse
    {
        [$s, $e] = self::period($request);
        $r = $sla->report($isp, $s, $e);
        $n = fn ($v, $d = 2) => $v === null ? '' : number_format((float) $v, $d, ',', '');

        return response()->streamDownload(function () use ($r, $isp, $n) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Link', $isp->name, 'Ketersediaan (%)', $n($r['availability'], 3), 'Target (%)', $n($isp->target_pct)], ';');
            fputcsv($out, [], ';');
            fputcsv($out, ['Tanggal', 'Ketersediaan (%)', 'Gangguan (menit)', 'Latensi rata-rata (ms)', 'Jitter rata-rata (ms)', 'Packet loss rata-rata (%)'], ';');
            foreach ($r['days'] as $d) {
                fputcsv($out, [$d['date'], $n($d['availability']), $n($d['downtime_s'] / 60, 0), $n($d['latency']), $n($d['jitter']), $n($d['loss'])], ';');
            }
            fputcsv($out, [], ';');
            fputcsv($out, ['No', 'Mulai', 'Pulih', 'Durasi (menit)', 'Severity', 'Penyebab'], ';');
            foreach ($r['incidents'] as $i => $x) {
                fputcsv($out, [$i + 1, date('Y-m-d H:i', $x['start']), $x['end'] ? date('Y-m-d H:i', $x['end']) : 'berlangsung', $n($x['duration_s'] / 60, 0), $x['severity'], $x['cause']], ';');
            }
            fclose($out);
        }, 'sla-isp-' . \Illuminate\Support\Str::slug($isp->name) . '-' . $s->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---- pengaturan link (engineer & admin) ----

    public function manage(Request $request)
    {
        $links = IspLink::with('device')->orderBy('name')->get();

        return view('isp.manage', ['links' => $links, 'edit' => $request->integer('ubah') ? $links->firstWhere('id', $request->integer('ubah')) : null,
            'devices' => Device::where('ping_enabled', true)->orderBy('name')->get(['id', 'name', 'host'])]);
    }

    public function store(Request $request)
    {
        $l = IspLink::create($this->validated($request));
        Audit::log('created', "Link ISP \"{$l->name}\" ditambahkan", $l);

        return redirect()->route('isp.index')->with('ok', "Link {$l->name} ditambahkan. Laporan memakai data ping yang sudah tersimpan, jadi minggu lalu pun langsung terisi bila perangkatnya sudah di-ping.");
    }

    public function update(Request $request, IspLink $isp)
    {
        $isp->fill($this->validated($request, $isp));
        $changes = Audit::diff($isp);
        $isp->save();
        Audit::log('updated', "Link ISP \"{$isp->name}\" diubah", $isp, $changes);

        return redirect()->route('isp.manage')->with('ok', "Link {$isp->name} disimpan.");
    }

    public function destroy(IspLink $isp)
    {
        $isp->delete();
        Audit::log('deleted', "Link ISP \"{$isp->name}\" dihapus", $isp);

        return redirect()->route('isp.manage')->with('ok', "Link {$isp->name} dihapus dari laporan. Data ping perangkatnya tidak ikut terhapus.");
    }

    private function validated(Request $r, ?IspLink $l = null): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('isp_links', 'name')->ignore($l)],
            'provider' => ['nullable', 'string', 'max:80'],
            'device_id' => ['required', 'integer', 'exists:devices,id', Rule::unique('isp_links', 'device_id')->ignore($l)],
            'target_pct' => ['required', 'numeric', 'between:50,100'],
            'warn_pct' => ['required', 'numeric', 'between:50,100', 'lte:target_pct'],
            'loss_down_pct' => ['required', 'numeric', 'between:1,100'],
            'latency_target_ms' => ['required', 'numeric', 'between:1,100000'],
            'jitter_target_ms' => ['required', 'numeric', 'between:0.1,100000'],
            'loss_target_pct' => ['required', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['device_id.unique' => 'Perangkat ini sudah dipakai link lain.', 'warn_pct.lte' => 'Batas peringatan harus di bawah atau sama dengan target.']);
        $d['exclude_maintenance'] = $r->boolean('exclude_maintenance');

        return $d;
    }
}
