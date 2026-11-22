<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SyslogEntry;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
    public const PERIODS = ['1h' => '1 jam', '24h' => '24 jam', '7d' => '7 hari', 'all' => 'Semua'];

    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'device' => ['nullable', 'integer'],
            'site' => ['nullable', 'integer'],
            'sev' => ['nullable', 'integer', 'between:0,7'],
            'app' => ['nullable', 'string', 'max:64'],
            'src' => ['nullable', 'ip'],
            'unknown' => ['nullable', 'boolean'],
            'period' => ['nullable', 'in:' . implode(',', array_keys(self::PERIODS))],
            'before' => ['nullable', 'integer'],
        ]);
        $period = $f['period'] ?? '24h';
        $q = SyslogEntry::with('device:id,name,host')->orderByDesc('id');
        if ($period !== 'all') {
            $q->where('received_at', '>=', now()->sub(['1h' => '1 hour', '24h' => '24 hours', '7d' => '7 days'][$period]));
        }
        if (isset($f['sev'])) {
            $q->where('severity', '<=', $f['sev']);
        }
        if (! empty($f['device'])) {
            $q->where('device_id', $f['device']);
        }
        if (! empty($f['site'])) {
            $q->whereIn('device_id', Device::where('site_id', $f['site'])->select('id'));
        }
        if (! empty($f['src'])) {
            $q->where('source', $f['src']);
        }
        if (! empty($f['unknown'])) {
            $q->whereNull('device_id');
        }
        if (! empty($f['app'])) {
            $q->where('app', 'ilike', $this->like($f['app']) . '%');
        }
        if (! empty($f['q'])) {
            $q->where('message', 'ilike', '%' . $this->like($f['q']) . '%');
        }
        if (! empty($f['before'])) {
            $q->where('id', '<', $f['before']);
        }
        // Paging berbasis id (tanpa COUNT di tabel besar)
        $rows = $q->limit(101)->get();
        $more = $rows->count() > 100;
        $rows = $rows->take(100);

        $stats = DB::selectOne("SELECT count(*) AS total,
                count(*) FILTER (WHERE severity <= 3) AS errors,
                count(DISTINCT source) AS sources,
                count(DISTINCT source) FILTER (WHERE device_id IS NULL) AS unknown
            FROM syslog WHERE received_at >= now() - interval '1 hour'");
        $top = DB::select("SELECT s.device_id, d.name, count(*) AS n, count(*) FILTER (WHERE s.severity <= 3) AS err
            FROM syslog s JOIN devices d ON d.id = s.device_id
            WHERE s.received_at >= now() - interval '24 hours' GROUP BY s.device_id, d.name ORDER BY err DESC, n DESC LIMIT 8");

        return view('logs.index', [
            'rows' => $rows,
            'more' => $more,
            'f' => $f + ['period' => $period],
            'stats' => $stats,
            'top' => $top,
            'sites' => Site::orderBy('code')->get(),
            'device' => ! empty($f['device']) ? Device::find($f['device']) : null,
            'settings' => ['keep' => (int) Setting::get('syslog.keep_days', 14), 'event' => (int) Setting::get('syslog.event_severity', 2)],
            'port' => config('antareja.syslog_port'),
        ]);
    }

    public function settings(Request $request)
    {
        $d = $request->validate([
            'keep' => ['required', 'integer', 'between:1,365'],
            'event' => ['required', 'integer', 'between:-1,3'],
        ], [], ['keep' => 'lama simpan', 'event' => 'tingkat kejadian']);
        Setting::put('syslog.keep_days', (int) $d['keep']);
        Setting::put('syslog.event_severity', (int) $d['event']);
        Audit::log('updated', "Pengaturan syslog: simpan {$d['keep']} hari, kejadian tingkat ≤ {$d['event']}");

        return back()->with('ok', 'Pengaturan syslog disimpan.');
    }

    private function like(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
