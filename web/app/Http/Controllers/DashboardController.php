<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\Problem;
use App\Models\Site;
use App\Models\SyslogEntry;
use App\Support\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $layout = Dashboard::normalize($request->user()->dashboard);
        $on = array_flip(array_column($layout, 'k'));
        $active = Device::where('enabled', true);
        $counts = (clone $active)->selectRaw("count(*) total, count(*) filter (where status = 'up') up, count(*) filter (where status = 'down') down,
            count(*) filter (where status = 'unknown') unknown, count(*) filter (where snmp_enabled and snmp_ok = false) snmp_fail")->first();
        $judged = $counts->up + $counts->down;

        // Data dihitung hanya untuk widget yang ditampilkan
        $data = ['counts' => $counts, 'availability' => $judged ? round($counts->up / $judged * 100, 2) : null,
            'disabled' => Device::where('enabled', false)->count()];
        $load = [
            'problems' => fn () => ['problems' => Problem::open()->with(['device.site', 'acker'])->triage()->limit(15)->get(), 'problemCount' => Problem::open()->count()],
            'slow' => fn () => ['slow' => (clone $active)->where('status', 'up')->whereNotNull('last_rtt_ms')
                ->orderByRaw('last_loss_pct desc nulls last, last_rtt_ms desc')->with('site')->limit(8)->get()],
            'events' => fn () => ['events' => DeviceEvent::with('device')->where('at', '>=', now()->subDay())->orderByDesc('at')->limit(15)->get()],
            'sites' => fn () => ['sites' => Site::withCount([
                'devices as total' => fn ($q) => $q->where('enabled', true),
                'devices as up' => fn ($q) => $q->where('enabled', true)->where('status', 'up'),
                'devices as down' => fn ($q) => $q->where('enabled', true)->where('status', 'down'),
            ])->orderBy('code')->get()],
            'sensors' => fn () => ['sensors' => DB::table('device_sensors as s')->join('devices as d', 'd.id', '=', 's.device_id')
                ->where('d.enabled', true)->whereIn('s.status', ['warn', 'crit'])
                ->orderByRaw("s.status = 'crit' desc")->orderBy('d.name')->limit(10)
                ->get(['s.device_id', 's.label', 's.value', 's.text', 's.unit', 's.status', 'd.name'])],
            'cameras' => fn () => ['cameras' => Camera::where('enabled', true)->whereNotNull('health')->where('health', '<>', 'ok')
                ->with('site:id,code')->orderBy('health_since')->limit(10)->get()],
            'logs' => fn () => ['logs' => SyslogEntry::with('device:id,name')->where('received_at', '>=', now()->subDay())
                ->where('severity', '<=', 3)->orderByDesc('id')->limit(10)->get()],
            'config' => fn () => ['configs' => DB::table('device_configs as c')->join('devices as d', 'd.id', '=', 'c.device_id')
                ->where('c.taken_at', '>=', now()->subDays(7))->where(fn ($q) => $q->where('c.added', '>', 0)->orWhere('c.removed', '>', 0))
                ->orderByDesc('c.taken_at')->limit(10)->get(['c.id', 'c.device_id', 'c.taken_at', 'c.added', 'c.removed', 'd.name'])],
            'maintenance' => fn () => ['maint' => DB::table('maintenances as m')->leftJoin('sites as s', 's.id', '=', 'm.site_id')
                ->where('m.ends_at', '>', now())->where('m.starts_at', '<', now()->addDays(7))->orderBy('m.starts_at')->limit(8)
                ->get(['m.id', 'm.title', 'm.scope', 'm.starts_at', 'm.ends_at', 's.code as site'])],
            'map' => fn () => ['mapLayers' => MapController::layers()],
            'oncall' => fn () => ['oncall' => \App\Models\OncallSlot::brief()],
            'services' => function () {
                $svc = \App\Models\Service::with(['components.devices:id,status,created_at'])->orderBy('name')->get();
                $sla = (new \App\Services\ServiceSla)->compute($svc, now()->startOfMonth(), now()->startOfMonth()->addMonthNoOverflow());

                return ['services' => $svc, 'serviceSla' => $sla];
            },
        ];
        foreach ($load as $k => $fn) {
            if (isset($on[$k])) {
                $data += $fn();
            }
        }

        return view('dashboard', $data + ['layout' => $layout]);
    }

    /** Simpan susunan widget pengguna (atau kembalikan ke bawaan). */
    public function layout(Request $request)
    {
        $user = $request->user();
        if ($request->boolean('reset')) {
            $user->forceFill(['dashboard' => null])->save();

            return redirect()->route('dashboard')->with('ok', 'Susunan Ringkasan dikembalikan ke bawaan.');
        }
        $d = $request->validate([
            'order' => ['required', 'array', 'max:30'], 'order.*' => ['string', 'max:32'],
            'show' => ['array'], 'show.*' => ['string', 'max:32'],
            'size' => ['array'], 'size.*' => ['in:half,full'],
        ]);
        $show = array_flip($d['show'] ?? []);
        $layout = [];
        foreach ($d['order'] as $k) {
            if (isset($show[$k])) {
                $layout[] = ['k' => $k, 'w' => $d['size'][$k] ?? 'half'];
            }
        }
        $layout = Dashboard::normalize($layout);
        $user->forceFill(['dashboard' => $layout])->save();

        return redirect()->route('dashboard')->with('ok', $layout ? 'Susunan Ringkasan disimpan.' : 'Semua widget disembunyikan, tekan "Atur tampilan" untuk menampilkannya lagi.');
    }
}
