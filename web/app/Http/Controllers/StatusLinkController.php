<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Problem;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Halaman status read-only lewat tautan rahasia (untuk manajemen tanpa akun).
 * Token hanya disimpan dalam bentuk hash; halaman tidak menampilkan IP, konfigurasi, maupun kredensial.
 */
class StatusLinkController extends Controller
{
    // ---------- Administrasi ----------

    public function index()
    {
        $links = DB::table('status_links as l')->leftJoin('sites as s', 's.id', '=', 'l.site_id')->leftJoin('users as u', 'u.id', '=', 'l.created_by')
            ->select('l.*', 's.code as site_code', 'u.name as user_name')->orderByRaw('l.revoked_at IS NOT NULL')->orderByDesc('l.id')->get();

        return view('status.links', ['links' => $links, 'sites' => Site::orderBy('code')->get(), 'newUrl' => session('status_url')]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'days' => ['required', 'integer', 'in:0,7,30,90,180,365'],
        ], [], ['name' => 'nama', 'days' => 'masa berlaku']);
        $token = Str::random(40);
        DB::table('status_links')->insert([
            'name' => $d['name'], 'token_hash' => hash('sha256', $token), 'site_id' => $d['site_id'] ?? null,
            'show_devices' => $request->boolean('show_devices'), 'expires_at' => $d['days'] ? now()->addDays((int) $d['days']) : null,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('created', "Tautan status \"{$d['name']}\" dibuat" . ($d['days'] ? " (berlaku {$d['days']} hari)" : ' (tanpa batas waktu)'));

        // Tautan lengkap hanya ditampilkan sekali (lewat sesi), tidak disimpan
        return redirect()->route('status.links')->with('status_url', route('status.public', $token))
            ->with('ok', 'Tautan dibuat. Salin sekarang, tautan tidak bisa ditampilkan lagi.');
    }

    public function revoke(int $link)
    {
        $name = DB::table('status_links')->where('id', $link)->value('name');
        abort_unless($name !== null, 404);
        DB::table('status_links')->where('id', $link)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Audit::log('updated', "Tautan status \"{$name}\" dicabut");

        return back()->with('ok', 'Tautan dicabut, langsung tidak bisa dibuka lagi.');
    }

    // ---------- Halaman publik ----------

    public function show(string $token, ReportController $report)
    {
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private'];
        $link = strlen($token) === 40 ? DB::table('status_links')->where('token_hash', hash('sha256', $token))->first() : null;
        if (! $link || $link->revoked_at || ($link->expires_at && now()->greaterThan($link->expires_at))) {
            return response()->view('status.invalid', [], 404, $headers);
        }
        DB::table('status_links')->where('id', $link->id)->update(['last_used_at' => now(), 'use_count' => DB::raw('use_count + 1')]);

        $siteId = $link->site_id;
        $devices = Device::where('enabled', true)->when($siteId, fn ($q) => $q->where('site_id', $siteId));
        $c = (clone $devices)->selectRaw("count(*) total, count(*) filter (where status = 'up') up, count(*) filter (where status = 'down') down")->first();
        $sites = Site::when($siteId, fn ($q) => $q->where('id', $siteId))->withCount([
            'devices as total' => fn ($q) => $q->where('enabled', true),
            'devices as up' => fn ($q) => $q->where('enabled', true)->where('status', 'up'),
            'devices as down' => fn ($q) => $q->where('enabled', true)->where('status', 'down'),
        ])->orderBy('code')->get()->filter(fn ($s) => $s->total > 0);

        $sla = $report->compute(now()->startOfMonth(), now()->startOfMonth()->addMonthNoOverflow(), $siteId);
        $problems = Problem::open()->whereNull('suppressed')->whereHas('device', fn ($q) => $q->where('enabled', true)->when($siteId, fn ($qq) => $qq->where('site_id', $siteId)))
            ->selectRaw('severity, count(*) n')->groupBy('severity')->pluck('n', 'severity');

        return response()->view('status.public', [
            'link' => $link,
            'scope' => $siteId ? Site::find($siteId) : null,
            'c' => $c,
            'sites' => $sites,
            'sla' => $sla,
            'target' => (float) Setting::get('sla.target_pct', 99.5),
            'problems' => $problems,
            'down' => $link->show_devices ? (clone $devices)->where('status', 'down')->with('site:id,code')->orderBy('name')->limit(30)->get(['id', 'name', 'site_id', 'category', 'updated_at']) : collect(),
        ], 200, $headers);
    }
}
