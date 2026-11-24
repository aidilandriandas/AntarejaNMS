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
