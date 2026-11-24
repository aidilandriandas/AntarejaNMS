<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Sesi BGP dan tetangga OSPF semua router (dibaca SNMP, hanya baca). Lupakan = engineer & admin. */
class RoutingController extends Controller
{
    public const BGP = [1 => 'idle', 2 => 'connect', 3 => 'active', 4 => 'opensent', 5 => 'openconfirm', 6 => 'established'];

    public const OSPF = [1 => 'down', 2 => 'attempt', 3 => 'init', 4 => '2-way', 5 => 'exstart', 6 => 'exchange', 7 => 'loading', 8 => 'full'];

    public static function bgp(?int $deviceId = null)
    {
        return DB::table('bgp_peers as b')->join('devices as d', 'd.id', '=', 'b.device_id')
            ->when($deviceId, fn ($q) => $q->where('b.device_id', $deviceId))
            ->leftJoin('devices as r', fn ($j) => $j->whereRaw('r.host = host(b.remote_addr)')
                ->when(\App\Support\SiteScope::current(), fn ($j, $ids) => $j->whereIn('r.site_id', $ids)))
            ->orderByRaw('(b.missing OR (b.state <> 6 AND COALESCE(b.admin_status, 2) <> 1)) DESC')->orderBy('d.name')->orderBy('b.remote_addr')
            ->get(['b.*', DB::raw('host(b.remote_addr) AS addr'), 'd.name as device', 'r.id as peer_id', 'r.name as peer_name']);
    }

    public static function ospf(?int $deviceId = null)
    {
        return DB::table('ospf_neighbors as o')->join('devices as d', 'd.id', '=', 'o.device_id')
            ->when($deviceId, fn ($q) => $q->where('o.device_id', $deviceId))
            ->leftJoin('devices as r', fn ($j) => $j->whereRaw('r.host = host(o.addr)')
                ->when(\App\Support\SiteScope::current(), fn ($j, $ids) => $j->whereIn('r.site_id', $ids)))
            ->orderByRaw('(o.missing OR o.state NOT IN (4, 8)) DESC')->orderBy('d.name')->orderBy('o.addr')
            ->get(['o.*', DB::raw('host(o.addr) AS ip'), 'd.name as device', 'r.id as peer_id', 'r.name as peer_name']);
    }

    public static function bgpBad(object $p): bool
    {
        return $p->missing || ($p->state !== 6 && (int) ($p->admin_status ?? 2) !== 1);
    }

    public static function ospfBad(object $n): bool
    {
        return $n->missing || ! in_array((int) $n->state, [4, 8], true);
    }

    public function index()
    {
        return view('routing.index', ['bgp' => self::bgp(), 'ospf' => self::ospf()]);
    }

    public function forget(Request $request, Device $device)
    {
        $d = $request->validate(['proto' => ['required', 'in:bgp,ospf'], 'addr' => ['required', 'ip']]);
        $n = $d['proto'] === 'bgp'
            ? DB::table('bgp_peers')->where('device_id', $device->id)->whereRaw('remote_addr = ?::inet', [$d['addr']])->delete()
            : DB::table('ospf_neighbors')->where('device_id', $device->id)->whereRaw('addr = ?::inet', [$d['addr']])->delete();
        abort_unless($n, 404);
        Audit::log('deleted', strtoupper($d['proto']) . " {$d['addr']} di {$device->name} dilupakan", $device);

        return back()->with('ok', strtoupper($d['proto']) . " {$d['addr']} dilupakan. Bila router masih melaporkannya, baris ini muncul lagi.");
    }

    public static function ago(?int $s): string
    {
        if ($s === null) {
            return '';
        }

        return match (true) {
            $s < 3600 => intdiv($s, 60) . ' menit',
            $s < 86400 => intdiv($s, 3600) . ' jam',
            default => intdiv($s, 86400) . ' hari',
        };
    }
}
