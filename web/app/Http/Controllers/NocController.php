<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\Problem;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Layar NOC: tampilan layar penuh untuk TV ruang kontrol; cek perubahan tiap 5 detik, data lengkap paling lambat tiap 15 detik. */
class NocController extends Controller
{
    public function index()
    {
        return view('noc.index', ['sites' => Site::orderBy('code')->get(), 'mapLayers' => MapController::layers(),
            'hasGeo' => Site::whereNotNull('lat')->exists() || \App\Models\Device::whereNotNull('lat')->exists()]);
    }

    /**
     * Sidik ringan keadaan saat ini. Layar memeriksanya tiap 5 detik dan baru memuat data lengkap bila berubah,
     * jadi problem baru, ACK, pulih, atau perubahan status perangkat tampil dalam hitungan detik.
     */
    public function state(Request $request): JsonResponse
    {
        return response()->json(['sig' => self::signature($request->integer('site') ?: null)]);
    }

    private static function signature(?int $site): string
    {
        $dev = $site ? 'AND d.site_id = ' . (int) $site : '';
        $r = DB::selectOne("SELECT
            (SELECT concat_ws(':', max(p.id), count(*) FILTER (WHERE p.resolved_at IS NULL), count(*) FILTER (WHERE p.resolved_at IS NULL AND p.acked_at IS NOT NULL),
                count(*) FILTER (WHERE p.resolved_at IS NULL AND p.suppressed IS NOT NULL), max(p.resolved_at), max(p.acked_at))
             FROM problems p JOIN devices d ON d.id = p.device_id WHERE (p.resolved_at IS NULL OR p.resolved_at > now() - interval '1 day') {$dev}) AS p,
            (SELECT concat_ws(':', max(d.status_changed_at), count(*) FILTER (WHERE d.status = 'up'), count(*)) FROM devices d WHERE d.enabled {$dev}) AS d,
            (SELECT max(e.id) FROM events e JOIN devices d ON d.id = e.device_id WHERE e.at > now() - interval '1 hour' {$dev}) AS e");

        return md5($r->p . '|' . $r->d . '|' . $r->e . '|' . now()->format('YmdHi'));
    }

    public function data(Request $request): JsonResponse
    {
        $site = $request->integer('site') ?: null;
        $devQ = Device::where('enabled', true)->when($site, fn ($q) => $q->where('site_id', $site));
        $devices = (clone $devQ)->with('site:id,code')->orderBy('name')
            ->get(['id', 'name', 'host', 'site_id', 'category', 'status', 'status_reason', 'last_rtt_ms', 'last_loss_pct', 'status_changed_at']);
        $ids = $devices->pluck('id')->all() ?: [0];

        // Problem baru (belum ACK, muncul 15 menit terakhir) selalu di atas walau severity-nya rendah, lalu urutan triase biasa
        $problems = Problem::open()->with(['device.site', 'acker'])->whereIn('device_id', $ids)
            ->orderByRaw("CASE WHEN acked_at IS NULL AND suppressed IS NULL AND started_at >= now() - interval '15 minutes' THEN 0 ELSE 1 END")
            ->triage()->limit(40)->get();
        // Hitungan dari SEMUA problem terbuka, bukan dari daftar yang dipotong
        $cnt = Problem::open()->whereIn('device_id', $ids)->selectRaw("severity, count(*) AS total,
            count(*) FILTER (WHERE acked_at IS NULL AND suppressed IS NULL) AS unacked")->groupBy('severity')->get()->keyBy('severity');
        $totalOpen = (int) $cnt->sum('total');
        $unackedN = (int) $cnt->sum('unacked');
        $critN = (int) (($cnt['high']->unacked ?? 0) + ($cnt['disaster']->unacked ?? 0));
        // Problem High/Disaster baru 10 menit terakhir (untuk bunyi alarm, tidak terbatas daftar yang tampil)
        $fresh = Problem::open()->whereIn('device_id', $ids)->whereNull('acked_at')->whereNull('suppressed')
            ->whereIn('severity', ['high', 'disaster'])->where('started_at', '>=', now()->subMinutes(10))->pluck('id');
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

        $alarm = match (true) {
            $critN > 0 => ['level' => 'crit', 'text' => $critN . ' problem High/Disaster belum di-ACK'],
            $unackedN > 0 => ['level' => 'warn', 'text' => $unackedN . ' problem belum di-ACK'],
            $totalOpen > 0 => ['level' => 'ok', 'text' => 'Terkendali, ' . $totalOpen . ' problem sudah di-ACK atau ditekan'],
            default => ['level' => 'ok', 'text' => 'Semua sistem normal'],
        };

        $up = $devices->where('status', 'up')->count();
        $down = $devices->where('status', 'down')->count();
        $sev = collect(Problem::SEVERITIES)->map(fn ($m, $k) => ['key' => $k, 'label' => $m[0], 'tone' => $m[1],
            'n' => (int) ($cnt[$k]->unacked ?? 0)])->values();

        // Collector tidak melapor: semua status di layar usang — peringatan ini mengalahkan alarm lain
        [$stale, , $beat] = SystemController::brief();
        if ($stale) {
            $alarm = ['level' => 'crit', 'text' => 'COLLECTOR TIDAK MELAPOR' . ($beat ? ' SEJAK ' . $beat->format('H.i') : '') . ', DATA USANG'];
        }

        return response()->json([
            'time' => now()->toIso8601String(),
            'sig' => self::signature($site),
            'fresh' => $fresh,
            'alarm' => $alarm,
            'oncall' => ($oc = \App\Models\OncallSlot::brief())['now']->map(fn ($o) => ['name' => $o['name'], 'until' => $o['until']->format('H.i')])->values(),
            'oncall_next' => $oc['next']->map(fn ($o) => ['name' => $o['name'], 'from' => $o['from']->format('H.i')])->values(),
            'kpi' => ['total' => $devices->count(), 'up' => $up, 'down' => $down, 'unknown' => $devices->where('status', 'unknown')->count(),
                'availability' => ($up + $down) ? round($up / ($up + $down) * 100, 2) : null, 'problems' => $totalOpen, 'shown' => min(20, $problems->count())],
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
