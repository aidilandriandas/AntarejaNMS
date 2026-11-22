<?php

namespace App\Http\Controllers;

use App\Models\NotifyChannel;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Laporan terjadwal: ringkasan harian/mingguan/bulanan dikirim collector lewat kanal notifikasi. */
class ReportScheduleController extends Controller
{
    public const FREQ = ['daily' => 'Harian (kemarin)', 'weekly' => 'Mingguan (Senin–Minggu lalu)', 'monthly' => 'Bulanan (bulan lalu)'];

    public function index()
    {
        $rows = DB::table('report_schedules as r')->join('notify_channels as c', 'c.id', '=', 'r.channel_id')->leftJoin('sites as s', 's.id', '=', 'r.site_id')
            ->select('r.*', 'c.name as channel_name', 'c.type as channel_type', 's.code as site_code')->orderBy('r.name')->get();

        return view('reports.schedules', [
            'rows' => $rows,
            'channels' => NotifyChannel::orderBy('name')->get(['id', 'name', 'type']),
            'sites' => Site::orderBy('code')->get(),
            'edit' => request()->integer('ubah') ? $rows->firstWhere('id', request()->integer('ubah')) : null,
        ]);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $id = DB::table('report_schedules')->insertGetId($d);
        Audit::log('created', "Laporan terjadwal \"{$d['name']}\" dibuat");

        return redirect()->route('reports.schedules')->with('ok', 'Jadwal laporan dibuat. Coba "Pratinjau" atau "Kirim sekarang".');
    }

    public function update(Request $request, int $schedule)
    {
        $d = $this->validated($request);
        abort_unless(DB::table('report_schedules')->where('id', $schedule)->update($d + ['updated_at' => now()]), 404);
        Audit::log('updated', "Laporan terjadwal \"{$d['name']}\" diubah");

        return redirect()->route('reports.schedules')->with('ok', 'Jadwal laporan disimpan.');
    }

    public function destroy(int $schedule)
    {
        $name = DB::table('report_schedules')->where('id', $schedule)->value('name');
        abort_unless($name !== null, 404);
        DB::table('report_schedules')->where('id', $schedule)->delete();
        Audit::log('deleted', "Laporan terjadwal \"{$name}\" dihapus");

        return redirect()->route('reports.schedules')->with('ok', 'Jadwal laporan dihapus.');
    }

    public function sendNow(int $schedule)
    {
        abort_unless(DB::table('report_schedules')->where('id', $schedule)->update(['requested' => true]), 404);
        Audit::log('updated', "Laporan terjadwal #{$schedule} dikirim manual");

        return back()->with('ok', 'Laporan akan dikirim dalam 1 menit (periode penuh terakhir).');
    }

    /** Pratinjau HTML laporan; ditampilkan dalam iframe sandbox (tanpa skrip). */
    public function preview(int $schedule, Collector $collector)
    {
        try {
            $r = $collector->reportPreview($schedule);
        } catch (\Throwable $e) {
            return response('<p style="font-family:sans-serif;color:#b91c1c;padding:16px">Collector tidak bisa dihubungi: ' . e($e->getMessage()) . '</p>', 502);
        }
        if (! ($r['ok'] ?? false)) {
            return response('<p style="font-family:sans-serif;color:#b91c1c;padding:16px">' . e($r['error'] ?? 'gagal') . '</p>', 422);
        }

        return response($r['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
            'X-Subject' => rawurlencode($r['subject'] ?? ''),
        ]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'frequency' => ['required', 'in:' . implode(',', array_keys(self::FREQ))],
            'send_hour' => ['required', 'integer', 'between:0,23'],
            'channel_id' => ['required', 'exists:notify_channels,id'],
            'site_id' => ['nullable', 'exists:sites,id'],
        ], [], ['name' => 'nama', 'frequency' => 'frekuensi', 'send_hour' => 'jam kirim', 'channel_id' => 'kanal']);

        return $d + ['site_id' => null, 'enabled' => $request->boolean('enabled')];
    }
}
