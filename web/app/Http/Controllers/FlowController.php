<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Flow trafik (NetFlow/IPFIX, ringkasan 5 menit): siapa dan aplikasi apa yang memakai bandwidth. Semua peran. */
class FlowController extends Controller
{
    public const RANGES = ['1h' => ['1 jam', 3600], '6h' => ['6 jam', 21600], '24h' => ['24 jam', 86400], '7d' => ['7 hari', 604800]];

    public const PROTOS = [1 => 'ICMP', 6 => 'TCP', 17 => 'UDP', 47 => 'GRE', 50 => 'ESP', 58 => 'ICMPv6'];

    public const PORTS = [20 => 'FTP data', 21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 67 => 'DHCP', 80 => 'HTTP', 110 => 'POP3',
        123 => 'NTP', 143 => 'IMAP', 161 => 'SNMP', 162 => 'SNMP trap', 389 => 'LDAP', 443 => 'HTTPS', 445 => 'SMB', 465 => 'SMTPS', 502 => 'Modbus',
        514 => 'Syslog', 554 => 'RTSP (CCTV)', 587 => 'SMTP', 636 => 'LDAPS', 993 => 'IMAPS', 1194 => 'OpenVPN', 1433 => 'SQL Server', 1883 => 'MQTT',
        1935 => 'RTMP', 3306 => 'MySQL', 3389 => 'Remote Desktop', 5060 => 'SIP', 5432 => 'PostgreSQL', 5900 => 'VNC', 8000 => 'HTTP 8000',
        8080 => 'HTTP 8080', 8443 => 'HTTPS 8443', 8883 => 'MQTT TLS', 9000 => 'Port 9000'];

    public static function app(int $proto, int $port): string
    {
        $p = self::PROTOS[$proto] ?? 'IP ' . $proto;
        if (! $port) {
            return in_array($proto, [6, 17], true) ? $p . ' port acak' : $p;
        }

        return isset(self::PORTS[$port]) ? self::PORTS[$port] . ' (' . $p . ' ' . $port . ')' : $p . ' ' . $port;
    }

    /** Nama tampilan kunci seri: "6/443" untuk aplikasi, IP (dengan nama perangkat bila dikenal) untuk sumber/tujuan. */
    private function label(string $by, string $key, array $names): string
    {
        if ($key === 'lainnya' || $key === '') {
            return 'Lainnya';
        }
        if ($by === 'app') {
            [$p, $port] = array_map('intval', explode('/', $key) + [1 => 0]);

            return self::app($p, $port);
        }

        return isset($names[$key]) ? $names[$key] . ' (' . $key . ')' : $key;
    }

    private function params(Request $r): array
    {
        $d = $r->validate(['perangkat' => ['required', 'integer'], 'port' => ['required', 'integer', 'min:0'], 'arah' => ['required', 'in:i,o'],
            'per' => ['required', 'in:app,src,dst'], 'rentang' => ['nullable', 'in:' . implode(',', array_keys(self::RANGES))]]);
        abort_unless(\App\Models\Device::whereKey($d['perangkat'])->exists(), 404);

        return $d;
    }

    /** Grafik riwayat: laju (bit/s) per bucket 5 menit, 8 kunci teratas + lainnya. */
    public function data(Request $request)
    {
        $d = $this->params($request);
        $secs = self::RANGES[$d['rentang'] ?? '1h'][1];
        $base = fn () => DB::table('flow_stats')->where('device_id', $d['perangkat'])->where('if_index', $d['port'])->where('dir', $d['arah'])
            ->where('bucket', '>=', now()->subSeconds($secs));
        $col = ['app' => "proto || '/' || port", 'src' => 'host(src)', 'dst' => 'host(dst)'][$d['per']];
        $top = $base()->whereNotNull('src')->groupByRaw($col)->orderByRaw('sum(bytes) DESC')->limit(8)->pluck(DB::raw("$col AS k"))->all();
        // percakapan di luar 50 teratas per bucket tersimpan tanpa src (NULL): ikut "lainnya"
        $rows = $base()->groupBy('bucket')->groupByRaw("CASE WHEN src IS NULL THEN 'lainnya' ELSE $col END")->orderBy('bucket')
            ->get(['bucket', DB::raw("CASE WHEN src IS NULL THEN 'lainnya' ELSE $col END AS k"), DB::raw('sum(bytes) AS b')]);
        $isTop = array_flip($top);
        $names = $this->names();
        $times = [];
        for ($t = (int) (floor((time() - $secs) / 300) * 300); $t <= time() - 300; $t += 300) {
            $times[] = $t;
        }
        $keys = array_merge($top, $rows->contains(fn ($r) => ! isset($isTop[$r->k])) ? ['lainnya'] : []);
        $vals = array_fill_keys($keys, array_fill_keys($times, 0.0));
        foreach ($rows as $r) {
            $t = (int) strtotime($r->bucket);
            $k = isset($isTop[$r->k]) ? $r->k : 'lainnya';
            if (array_key_exists($t, $vals[$k] ?? [])) {
                $vals[$k][$t] += $r->b * 8 / 300;
            }
        }

        return response()->json(['t' => $times, 'series' => collect($keys)->map(fn ($k) => ['key' => (string) $k, 'label' => $this->label($d['per'], (string) $k, $names),
            'values' => array_values($vals[$k])])->values()]);
    }

    /** Grafik live: diteruskan dari collector (token collector tidak pernah sampai ke browser). */
    public function live(Request $request, \App\Services\Collector $collector)
    {
        $d = $this->params($request);
        try {
            $r = $collector->flowLive((int) $d['perangkat'], (int) $d['port'], $d['arah'], $d['per']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Collector tidak dapat dihubungi: ' . mb_substr($e->getMessage(), 0, 150)], 502);
        }
        $names = $this->names();
        $pts = $r['points'] ?? [];
        $keys = collect($pts)->flatMap(fn ($p) => array_keys($p['by'] ?? []))->unique()->sortBy(fn ($k) => $k === 'lainnya' ? 1 : 0)->values();

        return response()->json(['t' => array_column($pts, 't'), 'series' => $keys->map(fn ($k) => ['key' => (string) $k, 'label' => $this->label($d['per'], (string) $k, $names),
            'values' => array_map(fn ($p) => (float) ($p['by'][$k] ?? 0), $pts)])->values()]);
    }

    public function index(Request $request)
    {
        $range = array_key_exists($request->query('rentang'), self::RANGES) ? $request->query('rentang') : '1h';
        $secs = self::RANGES[$range][1];
        $exporters = DB::table('flow_stats as f')->join('devices as d', 'd.id', '=', 'f.device_id')->where('f.bucket', '>=', now()->subDays(7))
            ->groupBy('d.id', 'd.name')->orderBy('d.name')->get(['d.id', 'd.name']);
        $dev = $request->integer('perangkat') ?: $exporters->first()?->id;
        $ports = $dev ? DB::table('flow_stats as f')->leftJoin('interfaces as i', fn ($j) => $j->on('i.device_id', '=', 'f.device_id')->on('i.if_index', '=', 'f.if_index'))
            ->where('f.device_id', $dev)->where('f.bucket', '>=', now()->subSeconds($secs))
            ->groupBy('f.if_index', 'i.name', 'i.alias')->orderByRaw('sum(f.bytes) DESC')
            ->get(['f.if_index', 'i.name', 'i.alias', DB::raw('sum(f.bytes) AS bytes')]) : collect();
        $if = $request->integer('port') ?: $ports->first()?->if_index;
        $dir = $request->query('arah') === 'o' ? 'o' : 'i';

        $base = fn () => DB::table('flow_stats')->where('device_id', $dev)->where('if_index', $if)->where('dir', $dir)->where('bucket', '>=', now()->subSeconds($secs));
        $total = $dev && $if !== null ? (int) $base()->sum('bytes') : 0;
        $names = $this->names();
        $top = fn (string $col) => $base()->whereNotNull($col)->groupBy($col)->orderByRaw('sum(bytes) DESC')->limit(15)
            ->get([DB::raw("host($col) AS ip"), DB::raw('sum(bytes) AS bytes')])->map(fn ($r) => ['ip' => $r->ip, 'name' => $names[$r->ip] ?? null, 'bytes' => (int) $r->bytes]);

        return view('flow.index', [
            'exporters' => $exporters, 'dev' => $dev, 'ports' => $ports, 'if' => $if, 'dir' => $dir, 'range' => $range, 'secs' => $secs, 'total' => $total,
            'sources' => $total ? $top('src') : collect(), 'dests' => $total ? $top('dst') : collect(),
            'apps' => $total ? $base()->whereNotNull('src')->groupBy('proto', 'port')->orderByRaw('sum(bytes) DESC')->limit(15)->get(['proto', 'port', DB::raw('sum(bytes) AS bytes')]) : collect(),
            'convs' => $total ? $base()->whereNotNull('src')->groupBy('src', 'dst', 'proto', 'port')->orderByRaw('sum(bytes) DESC')->limit(20)
                ->get([DB::raw('host(src) AS src'), DB::raw('host(dst) AS dst'), 'proto', 'port', DB::raw('sum(bytes) AS bytes')]) : collect(),
            'other' => $total ? (int) $base()->whereNull('src')->sum('bytes') : 0,
            'names' => $names,
        ]);
    }

    /** IP → nama perangkat terdaftar (host atau IP interface). */
    public function names(): array
    {
        $m = [];
        foreach (DB::select('SELECT host AS ip, name FROM devices UNION ALL SELECT host(di.ip), d.name FROM device_ips di JOIN devices d ON d.id = di.device_id') as $r) {
            $m[$r->ip] ??= $r->name;
        }

        return $m;
    }
}
