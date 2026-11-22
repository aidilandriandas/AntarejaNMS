<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Kesehatan ANTAREJA sendiri (khusus admin). */
class SystemController extends Controller
{
    public const LABELS = [
        'backup_failed' => 'Backup gagal', 'backup_late' => 'Backup terlambat', 'backup_service' => 'Layanan backup berhenti',
        'backup_disk' => 'Disk backup hampir penuh', 'notify_failed' => 'Notifikasi gagal terkirim', 'syslog_dropped' => 'Syslog dibuang',
    ];

    /** Status ringkas untuk banner & halaman: [collectorStale, activeAlerts]. */
    public static function brief(): array
    {
        $hb = DB::table('system_status')->where('key', 'collector')->value('updated_at');
        $stale = ! $hb || Carbon::parse($hb)->lt(now()->subMinutes(3));

        return [$stale, DB::table('system_alerts')->where('active', true)->count(), $hb ? Carbon::parse($hb) : null];
    }

    public function index()
    {
        $status = DB::table('system_status')->get()->keyBy('key')->map(fn ($r) => ['v' => json_decode($r->value, true), 'at' => Carbon::parse($r->updated_at)]);
        [$stale] = self::brief();
        $metricsOk = rescue(fn () => Http::timeout(3)->get(rtrim(config('antareja.metrics_url'), '/') . '/health')->successful(), false, false);

        return view('system.index', [
            'c' => $status['collector'] ?? null,
            'b' => $status['backup'] ?? null,
            'stale' => $stale,
            'metricsOk' => $metricsOk,
            'alerts' => DB::table('system_alerts')->orderByDesc('active')->orderByDesc('since')->get(),
            'backups' => DB::table('backups')->orderByDesc('id')->limit(5)->get(),
            'dbSize' => DB::selectOne('SELECT pg_database_size(current_database()) AS b')->b,
            'tables' => DB::select("SELECT relname AS name, pg_total_relation_size(relid) AS b, n_live_tup AS rows FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 6"),
            'notify' => DB::table('notifications as n')->join('notify_channels as c', 'c.id', '=', 'n.channel_id')->where('n.at', '>=', now()->subDay())
                ->groupBy('c.name')->selectRaw("c.name, count(*) FILTER (WHERE n.status = 'sent') AS sent, count(*) FILTER (WHERE n.status = 'failed') AS failed, max(n.error) FILTER (WHERE n.status = 'failed') AS err")->orderBy('c.name')->get(),
            'counts' => DB::selectOne("SELECT (SELECT count(*) FROM devices WHERE enabled) AS devices, (SELECT count(*) FROM devices WHERE enabled AND snmp_enabled) AS snmp,
                (SELECT count(*) FROM syslog WHERE received_at > now() - interval '1 hour') AS logs_1h, (SELECT count(*) FROM problems WHERE resolved_at IS NULL) AS problems"),
        ]);
    }

    public function hideOnboarding()
    {
        \App\Models\Setting::put('onboarding.hidden', true);

        return back()->with('ok', 'Daftar langkah awal disembunyikan. Tampilkan lagi dari halaman Panduan.');
    }

    public function showOnboarding()
    {
        \App\Models\Setting::put('onboarding.hidden', false);

        return redirect()->route('dashboard');
    }
}
