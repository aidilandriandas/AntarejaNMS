<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\Site;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $active = Device::where('enabled', true);
        $counts = (clone $active)->selectRaw("count(*) total, count(*) filter (where status = 'up') up, count(*) filter (where status = 'down') down,
            count(*) filter (where status = 'unknown') unknown, count(*) filter (where snmp_enabled and snmp_ok = false) snmp_fail")->first();
        $judged = $counts->up + $counts->down;

        return view('dashboard', [
            'counts' => $counts,
            'availability' => $judged ? round($counts->up / $judged * 100, 2) : null,
            'disabled' => Device::where('enabled', false)->count(),
            'problems' => \App\Models\Problem::open()->with(['device.site', 'acker'])->triage()->limit(15)->get(),
            'problemCount' => \App\Models\Problem::open()->count(),
            'slow' => (clone $active)->where('status', 'up')->whereNotNull('last_rtt_ms')
                ->orderByRaw('last_loss_pct desc nulls last, last_rtt_ms desc')->with('site')->limit(8)->get(),
            'events' => DeviceEvent::with('device')->where('at', '>=', now()->subDay())->orderByDesc('at')->limit(15)->get(),
            'sites' => Site::withCount([
                'devices as total' => fn ($q) => $q->where('enabled', true),
                'devices as up' => fn ($q) => $q->where('enabled', true)->where('status', 'up'),
                'devices as down' => fn ($q) => $q->where('enabled', true)->where('status', 'down'),
            ])->orderBy('code')->get(),
        ]);
    }
}
