<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Discovery jaringan: engineer meminta pemindaian subnet (ping + SNMP GET grup system, hanya baca),
 * collector mengerjakannya, lalu engineer memilih hasil yang ingin ditambahkan sebagai perangkat.
 */
class DiscoveryController extends Controller
{
    public const STATUS = ['queued' => ['Antre', 'info'], 'running' => ['Berjalan', 'info'], 'done' => ['Selesai', 'ok'],
        'failed' => ['Gagal', 'crit'], 'cancelled' => ['Dibatalkan', '']];

    public function index()
    {
        $scans = DB::table('discovery_scans as s')->leftJoin('sites', 'sites.id', '=', 's.site_id')->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->select('s.*', 'sites.code as site_code', 'u.name as user_name',
                DB::raw("(SELECT count(*) FROM discovery_results r WHERE r.scan_id = s.id AND r.state = 'new') AS new_count"))
            ->orderByDesc('s.id')->limit(30)->get();

        return view('discovery.index', ['scans' => $scans, 'sites' => Site::orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'cidr' => ['required', 'string', 'max:18', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'communities' => ['nullable', 'string', 'max:400'],
            'snmp_port' => ['required', 'integer', 'between:1,65535'],
        ], ['cidr.regex' => 'Tulis subnet dalam format CIDR, mis. 10.20.30.0/24.'], ['cidr' => 'subnet', 'communities' => 'community']);

        [$ip, $bits] = explode('/', $d['cidr']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (int) $bits > 32) {
            throw ValidationException::withMessages(['cidr' => 'Alamat IP pada subnet tidak valid.']);
        }
        if ((int) $bits < 22) {
            throw ValidationException::withMessages(['cidr' => 'Subnet terlalu besar, maksimal /22 (1.022 alamat) per pemindaian agar jaringan tidak terbebani.']);
        }
        $comms = collect(preg_split('/[\r\n,]+/', $d['communities'] ?? ''))->map(fn ($c) => trim($c))->filter()->unique()->values();
        if ($comms->count() > 5 || $comms->contains(fn ($c) => mb_strlen($c) > 64)) {
            throw ValidationException::withMessages(['communities' => 'Maksimal 5 community, masing-masing maks. 64 karakter.']);
        }
        if (DB::table('discovery_scans')->whereIn('status', ['queued', 'running'])->count() >= 3) {
            throw ValidationException::withMessages(['cidr' => 'Sudah ada 3 pemindaian yang antre/berjalan. Tunggu hingga selesai.']);
        }
        $id = DB::table('discovery_scans')->insertGetId([
            'cidr' => $d['cidr'],
            'site_id' => $d['site_id'] ?? null,
            'snmp_port' => $d['snmp_port'],
            'communities_enc' => $comms->isNotEmpty() ? Crypt::encryptString($comms->toJson()) : null,
            'snmp_all' => $request->boolean('snmp_all'),
            'created_by' => $request->user()->id,
        ]);
        Audit::log('created', "Discovery subnet {$d['cidr']} diminta (" . $comms->count() . ' community, nilai tidak dicatat)');

        return redirect()->route('discovery.show', $id)->with('ok', 'Pemindaian masuk antrean, biasanya mulai dalam 5 detik.');
    }

    public function show(int $scan)
    {
        $s = DB::table('discovery_scans as s')->leftJoin('sites', 'sites.id', '=', 's.site_id')->select('s.*', 'sites.code as site_code')->where('s.id', $scan)->first();
        abort_unless($s, 404);
        $rows = DB::table('discovery_results as r')->leftJoin('devices as d', 'd.id', '=', 'r.device_id')
            ->select('r.*', 'd.name as device_name')->where('r.scan_id', $scan)->orderBy('r.ip')->get();

        return view('discovery.show', [
            's' => $s, 'rows' => $rows, 'sites' => Site::orderBy('code')->get(),
            'parents' => Device::orderBy('name')->get(['id', 'name', 'host']),
            'canAddSnmp' => $s->communities_enc !== null,
        ]);
    }

    /** Status untuk pembaruan otomatis halaman selagi pemindaian berjalan. */
    public function status(int $scan)
    {
        $s = DB::table('discovery_scans')->where('id', $scan)->first(['status', 'total', 'progress', 'found', 'error']);
        abort_unless($s, 404);

        return response()->json($s);
    }

    public function cancel(int $scan)
    {
        $n = DB::table('discovery_scans')->where('id', $scan)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'cancelled', 'finished_at' => now()]);
        if ($n) {
            Audit::log('updated', "Discovery #{$scan} dibatalkan");
        }

        return back()->with('ok', $n ? 'Pemindaian dibatalkan.' : 'Pemindaian sudah selesai.');
    }

    /** Tambahkan hasil terpilih sebagai perangkat (setelah engineer meninjau nama/kategori/site). */
    public function add(Request $request, int $scan, Collector $collector)
    {
        $s = DB::table('discovery_scans')->where('id', $scan)->first();
        abort_unless($s, 404);
        $d = $request->validate([
            'pick' => ['required', 'array', 'min:1', 'max:500'],
            'pick.*' => ['integer'],
            'name' => ['array'], 'name.*' => ['nullable', 'string', 'max:128'],
            'category' => ['array'], 'category.*' => ['nullable', 'in:' . implode(',', array_keys(Device::CATEGORIES))],
            'site_id' => ['nullable', 'exists:sites,id'],
            'parent_id' => ['nullable', 'exists:devices,id'],
        ], ['pick.required' => 'Pilih minimal satu alamat untuk ditambahkan.']);

        $comms = [];
        if ($s->communities_enc) {
            try {
                $comms = json_decode(Crypt::decryptString($s->communities_enc), true) ?: [];
            } catch (\Throwable) {
                $comms = [];
            }
        }
        $rows = DB::table('discovery_results')->where('scan_id', $scan)->where('state', 'new')->whereIn('id', $d['pick'])->get();
        $created = DB::transaction(function () use ($rows, $d, $comms, $s) {
            $ids = [];
            foreach ($rows as $r) {
                $name = trim($d['name'][$r->id] ?? '') ?: ($r->sys_name ?: $r->ip);
                $community = $r->snmp_ok && $r->community_idx !== null ? ($comms[$r->community_idx] ?? null) : null;
                $dev = new Device([
                    'name' => mb_substr($name, 0, 128), 'host' => $r->ip, 'category' => $d['category'][$r->id] ?? $r->category,
                    'site_id' => $d['site_id'] ?? $s->site_id, 'parent_id' => $d['parent_id'] ?? null, 'enabled' => true,
                    // Ping dimatikan bila alamat hanya menjawab SNMP (ICMP diblok)
                    'ping_enabled' => (bool) $r->ping_ok, 'snmp_enabled' => $community !== null, 'snmp_version' => '2c', 'snmp_port' => $s->snmp_port,
                ]);
                $dev->setSecret('snmp_community_enc', $community);
                $dev->save();
                DB::table('discovery_results')->where('id', $r->id)->update(['state' => 'added', 'device_id' => $dev->id]);
                $ids[] = $dev->id;
            }

            return $ids;
        });
        Audit::log('created', count($created) . " perangkat ditambahkan dari discovery #{$scan} ({$s->cidr})");
        foreach (array_slice($created, 0, 50) as $id) {
            $collector->pollNow($id);
        }

        return back()->with('ok', count($created) . ' perangkat ditambahkan. Status muncul dalam 1 sampai 2 menit.');
    }

    public function ignore(Request $request, int $scan)
    {
        $ids = $request->validate(['pick' => ['required', 'array', 'min:1'], 'pick.*' => ['integer']], ['pick.required' => 'Pilih alamat yang ingin diabaikan.'])['pick'];
        $n = DB::table('discovery_results')->where('scan_id', $scan)->where('state', 'new')->whereIn('id', $ids)->update(['state' => 'ignored']);

        return back()->with('ok', "{$n} alamat diabaikan.");
    }
}
