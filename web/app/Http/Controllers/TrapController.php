<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** SNMP trap yang diterima collector (UDP 162). */
class TrapController extends Controller
{
    public const SEVERITIES = ['high' => ['High', 'crit'], 'average' => ['Average', 'warn'], 'warning' => ['Warning', 'warn'], 'info' => ['Info', '']];

    public const PERIODS = ['1h' => '1 jam', '24h' => '24 jam', '7d' => '7 hari', 'all' => 'Semua'];

    private const PER_PAGE = 100;

    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'device' => ['nullable', 'integer'],
            'sev' => ['nullable', Rule::in(array_keys(self::SEVERITIES))],
            'known' => ['nullable', 'boolean'],
            'unknown' => ['nullable', 'boolean'],
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'before' => ['nullable', 'integer'],
        ]);
        $period = $f['period'] ?? '24h';
        $q = DB::table('traps as t')->leftJoin('devices as d', 'd.id', '=', 't.device_id')
            ->select(['t.id', 't.received_at', 't.device_id', 'd.name as device', 't.source', 't.version', 't.trap_oid', 't.name', 't.severity', 't.if_index', 't.summary', 't.varbinds'])
            ->when($period !== 'all', fn ($q) => $q->where('t.received_at', '>=', now()->sub(['1h' => '1 hour', '24h' => '24 hours', '7d' => '7 days'][$period])))
            ->when(! empty($f['device']), fn ($q) => $q->where('t.device_id', $f['device']))
            ->when(! empty($f['sev']), fn ($q) => $q->whereIn('t.severity', array_slice(array_keys(self::SEVERITIES), 0, array_search($f['sev'], array_keys(self::SEVERITIES), true) + 1)))
            ->when(! empty($f['known']), fn ($q) => $q->whereNotNull('t.name'))
            ->when(! empty($f['unknown']), fn ($q) => $q->whereNull('t.device_id'))
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($w) => $w->where('t.summary', 'ilike', '%' . addcslashes($f['q'], '%_\\') . '%')
                ->orWhere('t.trap_oid', 'like', addcslashes($f['q'], '%_\\') . '%')))
            ->when(! empty($f['before']), fn ($q) => $q->where('t.id', '<', $f['before']))
            ->orderByDesc('t.id')->limit(self::PER_PAGE + 1);
        $rows = $q->get();
        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);

        $day = DB::table('traps')->where('received_at', '>=', now()->subDay());
        $stats = (object) [
            'day' => (clone $day)->count(),
            'link' => (clone $day)->whereIn('name', ['linkDown', 'linkUp'])->count(),
            'unknown' => (clone $day)->whereNull('device_id')->count(),
            'last' => DB::table('traps')->max('received_at'),
        ];
        $top = (clone $day)->whereNotNull('device_id')->select('device_id', DB::raw('count(*) n'))->groupBy('device_id')->orderByDesc('n')->limit(5)->get();
        $names = Device::whereIn('id', $top->pluck('device_id'))->pluck('name', 'id');

        return view('traps.index', [
            'rows' => $rows, 'more' => $more, 'f' => $f, 'period' => $period, 'stats' => $stats,
            'top' => $top->map(fn ($t) => (object) ['id' => $t->device_id, 'name' => $names[$t->device_id] ?? '#' . $t->device_id, 'n' => $t->n]),
            'devices' => Device::orderBy('name')->get(['id', 'name']),
            'keep' => (int) Setting::get('trap.keep_days', 30),
            'port' => (int) config('antareja.trap_port', 162),
        ]);
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['keep' => ['required', 'integer', 'between:1,365']], [], ['keep' => 'lama simpan']);
        Setting::put('trap.keep_days', (int) $d['keep']);
        Audit::log('updated', "Pengaturan SNMP trap: simpan {$d['keep']} hari");

        return back()->with('ok', 'Pengaturan trap disimpan.');
    }
}
