<?php

namespace App\Http\Controllers;

use App\Models\Problem;
use App\Models\Site;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * IPAM: subnet (otomatis dari IP interface perangkat + manual), pemakaian IP dari tabel ARP router,
 * pencarian IP/MAC, IP kosong, dan konflik IP. Hanya membaca data yang dikumpulkan collector.
 */
class IpamController extends Controller
{
    public function index(Request $request)
    {
        $subnets = DB::select(<<<'SQL'
            SELECT s.id, s.cidr::text AS cidr, s.name, s.vlan, s.note, s.source, st.code AS site,
                CASE WHEN masklen(s.cidr) >= 31 THEN 2 ^ (32 - masklen(s.cidr)) ELSE 2 ^ (32 - masklen(s.cidr)) - 2 END AS size,
                (SELECT count(*) FROM (
                    SELECT ip FROM arp_entries a WHERE a.ip << s.cidr AND a.last_seen > now() - interval '7 days'
                    UNION SELECT ip FROM device_ips di WHERE di.ip << s.cidr) u) AS used
            FROM subnets s LEFT JOIN sites st ON st.id = s.site_id
            WHERE family(s.cidr) = 4
            ORDER BY s.cidr
        SQL);

        return view('ipam.index', [
            'subnets' => $subnets,
            'sites' => Site::orderBy('code')->get(),
            'conflicts' => Problem::open()->where('kind', 'ip_conflict')->with('device')->orderByDesc('started_at')->get(),
            'changes' => DB::table('arp_changes as c')->join('devices as d', 'd.id', '=', 'c.reporter_id')->where('c.at', '>', now()->subDay())
                ->orderByDesc('c.at')->limit(30)->get(['c.at', DB::raw('host(c.ip) as ip'), 'c.old_mac', 'c.new_mac', 'd.name as reporter']),
            'moves' => DB::select(<<<'SQL'
                SELECT m.at, m.mac, fd.name AS from_name, fi.name AS from_port, td.name AS to_name, m.to_device_id, m.to_if_index, ti.name AS to_port,
                    (SELECT host(a.ip) FROM arp_entries a WHERE a.mac = m.mac ORDER BY a.last_seen DESC LIMIT 1) AS ip
                FROM mac_moves m LEFT JOIN devices fd ON fd.id = m.from_device_id LEFT JOIN devices td ON td.id = m.to_device_id
                LEFT JOIN interfaces fi ON fi.device_id = m.from_device_id AND fi.if_index = m.from_if_index
                LEFT JOIN interfaces ti ON ti.device_id = m.to_device_id AND ti.if_index = m.to_if_index
                WHERE m.at > now() - interval '7 days' ORDER BY m.at DESC LIMIT 30
            SQL),
            'tracked' => (int) DB::table('mac_locations')->where('last_seen', '>', now()->subDay())->count(),
            'stats' => DB::selectOne("SELECT (SELECT count(DISTINCT ip) FROM arp_entries WHERE last_seen > now() - interval '7 days') AS ips,
                (SELECT count(DISTINCT mac) FROM arp_entries WHERE last_seen > now() - interval '7 days') AS macs,
                (SELECT count(DISTINCT reporter_id) FROM arp_entries) AS routers"),
        ]);
    }

    public function show(int $subnet)
    {
        $s = DB::selectOne('SELECT s.*, s.cidr::text AS cidr_text, masklen(s.cidr) AS bits, host(network(s.cidr)) AS net, st.code AS site
            FROM subnets s LEFT JOIN sites st ON st.id = s.site_id WHERE s.id = ?', [$subnet]);
        abort_unless($s, 404);
        $bits = (int) $s->bits;
        $big = $bits < 22; // > 1024 alamat: tampilkan daftar terpakai saja
        $rows = DB::select(<<<'SQL'
            SELECT host(x.ip) AS ip, x.kind, x.mac, x.last_seen, x.reporter, x.device_id, x.device_name
            FROM (
                SELECT a.ip, 'arp' AS kind, a.mac, a.last_seen, r.name AS reporter, NULL::bigint AS device_id, NULL AS device_name
                FROM arp_entries a JOIN devices r ON r.id = a.reporter_id WHERE a.ip << ?::cidr
                UNION ALL
                SELECT di.ip, 'iface', NULL, di.updated_at, NULL, d.id, d.name FROM device_ips di JOIN devices d ON d.id = di.device_id WHERE di.ip << ?::cidr
                UNION ALL
                SELECT d.host::inet, 'device', NULL, d.last_ping_at, NULL, d.id, d.name FROM devices d
                WHERE d.host ~ '^\d{1,3}(\.\d{1,3}){3}$' AND d.host::inet << ?::cidr
            ) x ORDER BY x.ip, x.last_seen DESC NULLS LAST
        SQL, [$s->cidr_text, $s->cidr_text, $s->cidr_text]);

        // Gabung per IP: perangkat terdaftar > IP interface > ARP terbaru
        $ips = [];
        foreach ($rows as $r) {
            $e = $ips[$r->ip] ?? ['ip' => $r->ip, 'device_id' => null, 'device' => null, 'macs' => [], 'last_seen' => null, 'reporter' => null, 'iface' => false];
            if ($r->kind !== 'arp') {
                $e['device_id'] ??= $r->device_id;
                $e['device'] ??= $r->device_name;
                $e['iface'] = $e['iface'] || $r->kind === 'iface';
            } else {
                $e['macs'][$r->mac] = true;
                $e['reporter'] ??= $r->reporter;
            }
            if ($r->last_seen && (! $e['last_seen'] || $r->last_seen > $e['last_seen'])) {
                $e['last_seen'] = $r->last_seen;
            }
            $ips[$r->ip] = $e;
        }
        $week = now()->subDays(7);
        foreach ($ips as &$e) {
            $e['macs'] = array_keys($e['macs']);
            $e['state'] = $e['device'] ? 'device' : ($e['last_seen'] && \Illuminate\Support\Carbon::parse($e['last_seen'])->gt($week) ? 'used' : 'stale');
        }
        unset($e);

        $grid = [];
        $free = [];
        if (! $big) {
            $start = ip2long($s->net);
            $count = 2 ** (32 - $bits);
            for ($i = 0; $i < $count; $i++) {
                $ip = long2ip($start + $i);
                $edge = $bits <= 30 && ($i === 0 || $i === $count - 1);
                $state = $edge ? 'reserved' : ($ips[$ip]['state'] ?? 'free');
                $grid[] = ['ip' => $ip, 'state' => $state, 'info' => $ips[$ip] ?? null];
                if ($state === 'free' && count($free) < 20) {
                    $free[] = $ip;
                }
            }
        }
        $conflictIps = Problem::open()->where('kind', 'ip_conflict')->pluck('sensor_key')->map(fn ($k) => substr($k, 3))->flip();

        return view('ipam.show', ['s' => $s, 'ips' => collect($ips)->values(), 'grid' => $grid, 'free' => $free, 'big' => $big, 'conflictIps' => $conflictIps,
            'sites' => Site::orderBy('code')->get()]);
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $results = collect();
        if ($q !== '') {
            $mac = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $q));
            $isMac = strlen($mac) >= 4 && ! preg_match('/^\d{1,3}(\.\d{1,3}){0,3}\.?$/', $q);
            $results = collect(DB::select(<<<'SQL'
                SELECT host(a.ip) AS ip, a.mac, a.last_seen, a.first_seen, r.name AS reporter, r.id AS reporter_id, i.name AS port,
                    (SELECT d.name FROM devices d WHERE d.host = host(a.ip) LIMIT 1) AS device,
                    (SELECT s.cidr::text FROM subnets s WHERE a.ip << s.cidr ORDER BY masklen(s.cidr) DESC LIMIT 1) AS subnet,
                    ml.device_id AS sw_id, sw.name AS sw_name, ml.if_index AS sw_if, si.name AS sw_port, ml.vlan AS sw_vlan, ml.first_seen AS sw_since
                FROM arp_entries a JOIN devices r ON r.id = a.reporter_id
                LEFT JOIN interfaces i ON i.device_id = a.reporter_id AND i.if_index = a.if_index
                LEFT JOIN mac_locations ml ON ml.mac = a.mac
                LEFT JOIN devices sw ON sw.id = ml.device_id
                LEFT JOIN interfaces si ON si.device_id = ml.device_id AND si.if_index = ml.if_index
                WHERE (?::boolean AND replace(a.mac, ':', '') LIKE ?) OR (NOT ?::boolean AND host(a.ip) LIKE ?)
                ORDER BY a.last_seen DESC LIMIT 200
            SQL, [$isMac, '%' . $mac . '%', $isMac, $q . '%']));
            if ($isMac) {
                // MAC yang hanya terlihat di switch (perangkat L2, atau router-nya tidak dipantau)
                $l2 = collect(DB::select(<<<'SQL'
                    SELECT ml.mac, ml.device_id AS sw_id, sw.name AS sw_name, ml.if_index AS sw_if, si.name AS sw_port, ml.vlan AS sw_vlan,
                        ml.first_seen AS sw_since, ml.last_seen
                    FROM mac_locations ml JOIN devices sw ON sw.id = ml.device_id
                    LEFT JOIN interfaces si ON si.device_id = ml.device_id AND si.if_index = ml.if_index
                    WHERE replace(ml.mac, ':', '') LIKE ? AND NOT EXISTS (SELECT 1 FROM arp_entries a WHERE a.mac = ml.mac)
                    ORDER BY ml.last_seen DESC LIMIT 200
                SQL, ['%' . $mac . '%']));
                if (strlen($mac) === 12) {
                    $full = implode(':', str_split($mac, 2));
                    $moves = collect(DB::select(<<<'SQL'
                        SELECT m.at, fd.name AS from_name, m.from_device_id, fi.name AS from_port, td.name AS to_name, m.to_device_id, ti.name AS to_port
                        FROM mac_moves m LEFT JOIN devices fd ON fd.id = m.from_device_id LEFT JOIN devices td ON td.id = m.to_device_id
                        LEFT JOIN interfaces fi ON fi.device_id = m.from_device_id AND fi.if_index = m.from_if_index
                        LEFT JOIN interfaces ti ON ti.device_id = m.to_device_id AND ti.if_index = m.to_if_index
                        WHERE m.mac = ? ORDER BY m.at DESC LIMIT 50
                    SQL, [$full]));
                }
            }
        }

        return view('ipam.search', ['q' => $q, 'results' => $results, 'l2' => $l2 ?? collect(), 'moves' => $moves ?? collect()]);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $id = DB::table('subnets')->insertGetId($d + ['source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('created', "Subnet {$d['cidr']} ditambahkan");

        return redirect()->route('ipam.show', $id)->with('ok', "Subnet {$d['cidr']} ditambahkan.");
    }

    public function update(Request $request, int $subnet)
    {
        $d = $request->validate([
            'name' => ['nullable', 'string', 'max:128'], 'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'vlan' => ['nullable', 'integer', 'between:1,4094'], 'note' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_unless(DB::table('subnets')->where('id', $subnet)->update($d + ['updated_at' => now()]), 404);
        Audit::log('updated', "Subnet #{$subnet} diubah");

        return back()->with('ok', 'Subnet disimpan.');
    }

    public function destroy(int $subnet)
    {
        $cidr = DB::table('subnets')->where('id', $subnet)->value('cidr');
        abort_unless($cidr, 404);
        DB::table('subnets')->where('id', $subnet)->delete();
        Audit::log('deleted', "Subnet {$cidr} dihapus");

        return redirect()->route('ipam.index')->with('ok', "Subnet {$cidr} dihapus. Subnet otomatis akan muncul lagi bila masih ada di interface perangkat.");
    }

    private function validated(Request $r): array
    {
        $d = $r->validate([
            'cidr' => ['required', 'string', 'max:43', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/([8-9]|[12]\d|3[0-2])$/'],
            'name' => ['nullable', 'string', 'max:128'], 'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'vlan' => ['nullable', 'integer', 'between:1,4094'], 'note' => ['nullable', 'string', 'max:1000'],
        ], ['cidr.regex' => 'Format CIDR IPv4, mis. 10.10.5.0/24.']);
        [$ip, $bits] = explode('/', $d['cidr']);
        if (ip2long($ip) === false) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cidr' => 'Alamat IP tidak valid.']);
        }
        $d['cidr'] = long2ip(ip2long($ip) & (-1 << (32 - (int) $bits))) . '/' . $bits; // normalkan ke alamat jaringan
        if (DB::table('subnets')->where('cidr', $d['cidr'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cidr' => "Subnet {$d['cidr']} sudah ada."]);
        }

        return $d;
    }
}
