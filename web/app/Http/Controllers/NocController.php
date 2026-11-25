<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\Problem;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Layar NOC: tampilan layar penuh untuk TV ruang kontrol, diperbarui tiap 15 detik. */
class NocController extends Controller
{
    public function index()
    {
        return view('noc.index', ['sites' => Site::orderBy('code')->get(), 'mapLayers' => MapController::layers(),
            'hasGeo' => Site::whereNotNull('lat')->exists() || \App\Models\Device::whereNotNull('lat')->exists()]);
    }

    public function data(Request $request): JsonResponse
    {
        $site = $request->integer('site') ?: null;
        $devQ = Device::where('enabled', true)->when($site, fn ($q) => $q->where('site_id', $site));
        $devices = (clone $devQ)->with('site:id,code')->orderBy('name')
            ->get(['id', 'name', 'host', 'site_id', 'category', 'status', 'status_reason', 'last_rtt_ms', 'last_loss_pct', 'status_changed_at']);
        $ids = $devices->pluck('id')->all() ?: [0];

        $problems = Problem::open()->with(['device.site', 'acker'])->whereIn('device_id', $ids)->triage()->limit(30)->get();
        $openByDev = Problem::open()->whereIn('device_id', $ids)->get(['device_id', 'severity', 'suppressed', 'acked_at'])->groupBy('device_id');

        $wall = $devices->map(function ($d) use ($openByDev) {
            $ps = $openByDev[$d->id] ?? collect();
            $state = match (true) {
                $d->status === 'down' && $ps->every(fn ($p) => $p->suppressed === 'maintenance') && $ps->isNotEmpty() => 'maintenance',
                $d->status === 'down' && $ps->contains(fn ($p) => $p->suppressed === 'parent') => 'impacted',
                $d->status === 'down' => 'down',
                $d->status === 'unknown' => 'unknown',
                $ps->contains(fn ($p) => ! $p->suppressed) => 'problem',
                default => 'ok',
            };

            return ['id' => $d->id, 'name' => $d->name, 'host' => $d->host, 'site' => $d->site?->code ?? 'Tanpa site', 'state' => $state,
                'rtt' => $d->last_rtt_ms, 'loss' => $d->last_loss_pct, 'reason' => $d->status === 'down' ? $d->status_reason : null,
                'since' => $d->status_changed_at?->toIso8601String()];
        })->values();

        $unacked = $problems->filter(fn ($p) => ! $p->acked_at && ! $p->suppressed);
        $crit = $unacked->filter(fn ($p) => in_array($p->severity, ['high', 'disaster'], true));
        $alarm = match (true) {
            $crit->isNotEmpty() => ['level' => 'crit', 'text' => $crit->count() . ' problem High/Disaster belum di-ACK'],
            $unacked->isNotEmpty() => ['level' => 'warn', 'text' => $unacked->count() . ' problem belum di-ACK'],
            $problems->isNotEmpty() => ['level' => 'ok', 'text' => 'Terkendali, ' . $problems->count() . ' problem sudah di-ACK / ditekan'],
            default => ['level' => 'ok', 'text' => 'Semua sistem normal'],
        };

        $up = $devices->where('status', 'up')->count();
        $down = $devices->where('status', 'down')->count();
        $sev = collect(Problem::SEVERITIES)->map(fn ($m, $k) => ['key' => $k, 'label' => $m[0], 'tone' => $m[1],
            'n' => $problems->where('severity', $k)->whereNull('acked_at')->whereNull('suppressed')->count()])->values();

        // Collector tidak melapor: semua status di layar usang — peringatan ini mengalahkan alarm lain
        [$stale, , $beat] = SystemController::brief();
        if ($stale) {
            $alarm = ['level' => 'crit', 'text' => 'COLLECTOR TIDAK MELAPOR' . ($beat ? ' SEJAK ' . $beat->format('H.i') : '') . ', DATA USANG'];
        }

        return response()->json([
            'time' => now()->toIso8601String(),
            'alarm' => $alarm,
            'oncall' => ($oc = \App\Models\OncallSlot::brief())['now']->map(fn ($o) => ['name' => $o['name'], 'until' => $o['until']->format('H.i')])->values(),
            'oncall_next' => $oc['next']->map(fn ($o) => ['name' => $o['name'], 'from' => $o['from']->format('H.i')])->values(),
            'kpi' => ['total' => $devices->count(), 'up' => $up, 'down' => $down, 'unknown' => $devices->where('status', 'unknown')->count(),
                'availability' => ($up + $down) ? round($up / ($up + $down) * 100, 2) : null, 'problems' => $problems->count()],
            'severity' => $sev,
            'problems' => $problems->take(20)->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'severity' => $p->severity, 'sev_label' => $p->sevLabel(),
                'tone' => $p->sevTone(), 'device' => $p->device?->name, 'site' => $p->device?->site?->code, 'since' => $p->started_at->toIso8601String(),
                'acked' => (bool) $p->acked_at, 'acked_by' => $p->acker?->username, 'suppressed' => $p->suppressed_note,
                'url' => $p->device ? route('devices.show', $p->device_id) : null])->values(),
            'events' => DeviceEvent::with('device:id,name')->whereIn('device_id', $ids)->where('at', '>=', now()->subHour())->orderByDesc('at')->limit(25)->get()
                ->map(fn ($e) => ['kind' => $e->kind, 'label' => $e->meta()[0], 'tone' => $e->meta()[1], 'message' => $e->message, 'at' => $e->at->toIso8601String()])->values(),
            'wall' => $wall,
            'ports' => DB::table('interfaces as i')->join('devices as d', 'd.id', '=', 'i.device_id')->whereIn('i.device_id', $ids)
                ->where('i.oper_up', true)->whereNotNull('i.util_pct')->where('i.speed_bps', '>=', 10_000_000)
                ->where(fn ($q) => $q->whereNull('i.if_type')->orWhere('i.if_type', '<>', 24)) // tanpa loopback
                ->orderByDesc('i.util_pct')->limit(6)->get(['d.id', 'd.name', 'i.name as port', 'i.util_pct', 'i.in_bps', 'i.out_bps', 'i.speed_bps'])
                ->map(fn ($r) => ['device' => $r->name, 'port' => $r->port, 'util' => round((float) $r->util_pct, 1), 'in' => (float) $r->in_bps,
                    'out' => (float) $r->out_bps, 'speed' => (int) $r->speed_bps, 'url' => route('devices.show', $r->id)])->values(),
        ]);
    }
}
