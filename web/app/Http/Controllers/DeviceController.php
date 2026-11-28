<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Collector;
use App\Services\Metrics;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $q = Device::with('site')->search($request->query('q'));
        match ($request->query('status')) {
            'up', 'down', 'unknown' => $q->where('enabled', true)->where('status', $request->query('status')),
            'off' => $q->where('enabled', false),
            'snmp_fail' => $q->where('snmp_enabled', true)->where('snmp_ok', false),
            default => null,
        };
        if ($s = $request->query('site')) {
            $q->where('site_id', $s);
        }
        if ($c = $request->query('category')) {
            $q->where('category', $c);
        }
        // Bermasalah di atas: down, menunggu, lalu online; nonaktif paling bawah
        $q->orderByRaw("enabled desc, case status when 'down' then 0 when 'unknown' then 1 else 2 end, name");

        $devices = $q->paginate(100)->withQueryString();

        return view('devices.index', [
            'devices' => $devices,
            // Kamera CCTV per perangkat di halaman ini: ikon live view di samping nama
            'camOf' => \App\Support\SiteScope::current() === null
                ? \App\Models\Camera::where('enabled', true)->whereIn('device_id', $devices->pluck('id'))->orderBy('sort')->get(['id', 'device_id'])->unique('device_id')->pluck('id', 'device_id')
                : collect(),
            'sites' => Site::orderBy('code')->get(),
            'parents' => $request->user()->can('manage-devices') ? Device::orderBy('name')->get(['id', 'name', 'host']) : collect(),
            'collectors' => \App\Models\SiteCollector::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return view('devices.form', ['device' => new Device(['enabled' => true, 'ping_enabled' => true, 'snmp_version' => '2c', 'snmp_port' => 161, 'category' => 'other']),
            'sites' => Site::orderBy('code')->get(), 'parents' => Device::orderBy('name')->get(['id', 'name', 'host']),
            'collectors' => \App\Models\SiteCollector::with('site')->orderBy('name')->get(), 'templates' => \App\Models\DeviceTemplate::orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request, Collector $collector)
    {
        $device = new Device;
        $this->fill($device, $request);
        $device->save();
        Audit::log('created', "Perangkat {$device->name} ({$device->host}) ditambahkan", $device);
        $warn = \App\Services\TemplateSync::apply($device);
        $collector->pollNow($device->id);

        return redirect()->route('devices.show', $device)->with('ok', "{$device->name} ditambahkan. Data pertama muncul dalam beberapa detik.")
            ->with('error', $warn ? implode(' ', $warn) : null);
    }

    public function show(Device $device, Metrics $metrics)
    {
        $device->load('site', 'ports', 'parent', 'children');
        $traffic = [];
        if ($device->snmp_enabled && $device->ports->isNotEmpty()) {
            $sel = '{device_id="' . $device->id . '"}';
            $traffic = [
                'in' => $metrics->instant("rate(antareja_if_in_octets_total{$sel}[15m]) * 8", 'if_index'),
                'out' => $metrics->instant("rate(antareja_if_out_octets_total{$sel}[15m]) * 8", 'if_index'),
            ];
            // Grafik mini 24 jam per port (satu kueri per arah untuk semua port perangkat)
            $end = time() - time() % 1800;
            foreach (['in', 'out'] as $dir) {
                foreach ($metrics->matrix("rate(antareja_if_{$dir}_octets_total{$sel}[30m]) * 8", $end - 86400, $end, 1800) as [$l, $vals]) {
                    $traffic['spark'][(int) ($l['if_index'] ?? 0)][$dir] = $vals;
                }
            }
        }

        $impact = $device->enabled && \App\Support\SiteScope::current() === null ? (new \App\Services\Impact)->of([$device->id]) : null;

        return view('devices.show', [
            'device' => $device,
            'impact' => $impact,
            'pluggedIn' => \Illuminate\Support\Facades\DB::select(<<<'SQL'
                SELECT DISTINCT ON (ml.device_id, ml.if_index) ml.device_id, ml.if_index, sw.name AS sw_name, si.name AS sw_port, ml.vlan, ml.first_seen
                FROM interfaces i JOIN mac_locations ml ON ml.mac = i.mac JOIN devices sw ON sw.id = ml.device_id
                LEFT JOIN interfaces si ON si.device_id = ml.device_id AND si.if_index = ml.if_index
                WHERE i.device_id = ? AND i.mac IS NOT NULL AND ml.device_id <> ? AND ml.last_seen > now() - interval '1 day'
            SQL . \App\Support\SiteScope::sqlIn('sw.site_id') . ' ORDER BY ml.device_id, ml.if_index, ml.first_seen', [$device->id, $device->id]),
            'checks' => \App\Models\ServiceCheck::where('device_id', $device->id)->orderBy('name')->get(),
            // Kamera yang tertaut ke perangkat ini (atau berhost sama): tombol Lihat CCTV langsung ke live view
            'cams' => \App\Support\SiteScope::current() === null
                ? \App\Models\Camera::where('enabled', true)->where(fn ($q) => $q->where('device_id', $device->id)->orWhere('host', $device->host))->orderBy('sort')->orderBy('name')->get(['id', 'name'])
                : collect(),
            'runbooks' => \App\Models\Runbook::where('enabled', true)->with('site', 'device')->get()->filter(fn ($r) => $r->matches($device, null))
                ->sortByDesc(fn ($r) => $r->score())->values(),
            'traffic' => $traffic,
            'events' => $device->events()->limit(20)->get(),
            'logs' => \App\Models\SyslogEntry::where('device_id', $device->id)->where('received_at', '>=', now()->subDay())->orderByDesc('id')->limit(10)->get(),
            'monitored' => $device->monitoredPorts(),
            'problems' => $device->problems()->whereNull('resolved_at')->triage()->get(),
            'sensors' => $device->sensors()->get()->groupBy('grp'),
            'bgp' => RoutingController::bgp($device->id),
            'vlans' => \Illuminate\Support\Facades\DB::table('device_vlans')->where('device_id', $device->id)->orderBy('vlan_id')->pluck('vlan_id'),
            'ospf' => RoutingController::ospf($device->id),
            'configs' => \Illuminate\Support\Facades\DB::table('device_configs')->where('device_id', $device->id)->orderByDesc('taken_at')->limit(8)
                ->get(['id', 'taken_at', 'lines', 'added', 'removed']),
        ]);
    }

    public function edit(Device $device)
    {
        return view('devices.form', ['device' => $device, 'sites' => Site::orderBy('code')->get(),
            'parents' => Device::whereKeyNot($device->id)->orderBy('name')->get(['id', 'name', 'host']),
            'collectors' => \App\Models\SiteCollector::with('site')->orderBy('name')->get(), 'templates' => \App\Models\DeviceTemplate::orderBy('name')->get(['id', 'name'])]);
    }

    public function update(Request $request, Device $device, Collector $collector)
    {
        $this->fill($device, $request);
        $changes = Audit::diff($device);
        $device->save();
        Audit::log('updated', "Perangkat {$device->name} diubah", $device, $changes);
        $warn = \App\Services\TemplateSync::apply($device);
        $collector->pollNow($device->id);

        return redirect()->route('devices.show', $device)->with('ok', "Perubahan {$device->name} disimpan.")
            ->with('error', $warn ? implode(' ', $warn) : null);
    }

    public function destroy(Device $device)
    {
        $device->delete();
        Audit::log('deleted', "Perangkat {$device->name} ({$device->host}) dihapus beserta riwayat kejadiannya", $device);

        return redirect()->route('devices.index')->with('ok', "{$device->name} dihapus.");
    }

    public function poll(Device $device, Collector $collector)
    {
        if ($device->collector_id) {
            return back()->with('error', 'Perangkat ini dipantau collector site, jadi pemeriksaan berjalan dari site sesuai jadwalnya (ping tiap menit).');
        }
        $ok = $collector->pollNow($device->id);

        return back()->with($ok ? 'ok' : 'error', $ok ? "Pemeriksaan {$device->name} dijalankan. Muat ulang halaman dalam beberapa detik." : 'Collector tidak dapat dihubungi.');
    }

    /** Alat diagnosa dari collector ke host perangkat ini (JSON untuk kartu Alat diagnosa). */
    public function tool(Request $request, Device $device, Collector $collector): JsonResponse
    {
        $d = $request->validate(['tool' => ['required', Rule::in(['ping', 'traceroute', 'port'])], 'port' => ['required_if:tool,port', 'nullable', 'integer', 'between:1,65535']]);
        $label = ['ping' => 'Ping', 'traceroute' => 'Traceroute', 'port' => 'Cek port TCP ' . ($d['port'] ?? '')][$d['tool']];
        try {
            $r = $collector->tool($device->id, $d['tool'], isset($d['port']) ? (int) $d['port'] : null);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Collector tidak dapat dihubungi: ' . $e->getMessage()], 502);
        }
        Audit::log('viewed', "{$label} ke {$device->name} ({$device->host}) dari collector", $device);

        return response()->json(['tool' => $d['tool'], 'result' => $r]);
    }

    /** Unduh berkas diagnosa SNMP (JSON) untuk membuat atau memperbaiki profil perangkat yang belum dikenal. */
    public function diagnose(Device $device, Collector $collector)
    {
        abort_unless($device->snmp_enabled, 422, 'SNMP tidak aktif untuk perangkat ini.');
        try {
            $data = $collector->diagnose($device->id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Diagnosa gagal: ' . $e->getMessage());
        }
        Audit::log('viewed', "Diagnosa SNMP {$device->name} diunduh", $device);
        $file = 'diagnosa-snmp-' . \Illuminate\Support\Str::slug($device->name) . '-' . now()->format('Ymd-His') . '.json';

        return response()->streamDownload(fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $file,
            ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** Uji koneksi dari form. Kredensial kosong pada perangkat yang sudah ada = pakai yang tersimpan. */
    public function test(Request $request, Collector $collector): JsonResponse
    {
        $d = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'ping_enabled' => ['boolean'],
            'snmp_enabled' => ['boolean'],
            'snmp_version' => ['nullable', Rule::in(['1', '2c', '3'])],
            'snmp_port' => ['nullable', 'integer', 'between:1,65535'],
            'snmp_community' => ['nullable', 'string', 'max:128'],
            'snmp_v3_user' => ['nullable', 'string', 'max:64'],
            'snmp_v3_level' => ['nullable', Rule::in(['noAuthNoPriv', 'authNoPriv', 'authPriv'])],
            'snmp_v3_auth_proto' => ['nullable', Rule::in(['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'])],
            'snmp_v3_auth_pass' => ['nullable', 'string', 'max:128'],
            'snmp_v3_priv_proto' => ['nullable', Rule::in(['DES', 'AES', 'AES192', 'AES256'])],
            'snmp_v3_priv_pass' => ['nullable', 'string', 'max:128'],
        ]);
        $stored = isset($d['device_id']) ? Device::find($d['device_id']) : null;
        $snmp = null;
        if (! empty($d['snmp_enabled'])) {
            $snmp = [
                'version' => $d['snmp_version'] ?? '2c', 'port' => (int) ($d['snmp_port'] ?? 161),
                'community' => ($d['snmp_community'] ?? '') ?: $stored?->secret('snmp_community_enc') ?? '',
                'user' => $d['snmp_v3_user'] ?? '', 'level' => $d['snmp_v3_level'] ?? 'authPriv',
                'auth_proto' => $d['snmp_v3_auth_proto'] ?? 'SHA', 'auth_pass' => ($d['snmp_v3_auth_pass'] ?? '') ?: $stored?->secret('snmp_v3_auth_enc') ?? '',
                'priv_proto' => $d['snmp_v3_priv_proto'] ?? 'AES', 'priv_pass' => ($d['snmp_v3_priv_pass'] ?? '') ?: $stored?->secret('snmp_v3_priv_enc') ?? '',
            ];
        }
        try {
            $res = $collector->test(trim($d['host']), (bool) ($d['ping_enabled'] ?? false), $snmp);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Collector tidak dapat dihubungi: ' . $e->getMessage()], 502);
        }
        if (isset($res['snmp']['uptime_s'])) {
            $res['snmp']['uptime'] = Fmt::duration((int) $res['snmp']['uptime_s']);
        }

        return response()->json($res); // tidak memuat kredensial
    }

    /**
     * Trafik live: counter oktet port saat ini langsung dari perangkat (SNMP GET lewat collector, maks. 60 port).
     * Laju dihitung di browser dari selisih dua pembacaan. Hanya membaca; semua peran boleh.
     */
    public function live(Request $request, Device $device, \App\Services\Collector $collector): JsonResponse
    {
        $d = $request->validate(['ports' => ['required', 'array', 'min:1', 'max:60'], 'ports.*' => ['integer', 'min:1']]);
        if (! $device->snmp_enabled) {
            return response()->json(['samples' => [], 'error' => 'SNMP perangkat ini dimatikan.']);
        }
        try {
            $samples = $collector->live(array_map(fn ($i) => ['device_id' => $device->id, 'if_index' => (int) $i], array_values(array_unique($d['ports']))));
        } catch (\Throwable) {
            return response()->json(['samples' => [], 'error' => 'Collector tidak terjangkau.']);
        }

        return response()->json(['samples' => array_map(fn ($x) => ['if' => $x['if_index'], 'in' => $x['in'], 'out' => $x['out'], 'at' => $x['at']], $samples),
            'error' => $samples ? null : 'Perangkat tidak menjawab SNMP.']);
    }

    /** Data grafik perangkat (dibaca server dari VictoriaMetrics). */
    public function metrics(Request $request, Device $device, Metrics $metrics): JsonResponse
    {
        $range = Metrics::RANGES[$request->query('range', '24h')] ?? Metrics::RANGES['24h'];
        $sel = 'device_id="' . $device->id . '"';
        $step = max(60, intdiv($range, 360));
        $win = max(2 * $step, 600) . 's';

        $data = match ($request->query('type', 'ping')) {
            'port' => (function () use ($request, $device, $metrics, $sel, $range, $win) {
                $if = (int) $request->query('if');
                abort_unless($device->ports()->where('if_index', $if)->exists(), 404);
                $s = $sel . ',if_index="' . $if . '"';

                return $metrics->range([
                    'in' => "rate(antareja_if_in_octets_total{{$s}}[{$win}]) * 8",
                    'out' => "rate(antareja_if_out_octets_total{{$s}}[{$win}]) * 8",
                ], $range);
            })(),
            'sensor' => (function () use ($request, $device, $metrics, $sel, $range, $step) {
                $key = (string) $request->query('key');
                abort_unless($device->sensors()->where('key', $key)->exists(), 404);
                $k = addcslashes($key, '"\\');

                return $metrics->range(['value' => "avg_over_time(antareja_sensor{{$sel},key=\"{$k}\"}[{$step}s])"], $range);
            })(),
            default => $metrics->range([
                'rtt' => "avg_over_time(antareja_ping_rtt_ms{{$sel}}[{$step}s])",
                'min' => "min_over_time(antareja_ping_rtt_min_ms{{$sel}}[{$step}s])",
                'max' => "max_over_time(antareja_ping_rtt_max_ms{{$sel}}[{$step}s])",
                'loss' => "avg_over_time(antareja_ping_loss_pct{{$sel}}[{$step}s])",
            ], $range),
        };

        return response()->json($data);
    }

    /** Ambang khusus dari form: kosong = pakai bawaan. Pasangan [peringatan, kritis] harus berbeda. */
    public static function parseThresholds(Request $r): array
    {
        $out = [];
        foreach (Device::THRESHOLDS as $k => [$label, , , $type]) {
            if ($type === 'single') {
                $v = $r->input("thr.$k");
                if ($v !== null && $v !== '') {
                    abort_unless(is_numeric($v) && $v > 0, 422, "Ambang {$label} harus angka > 0.");
                    $out[$k] = (float) $v;
                }
                continue;
            }
            $w = $r->input("thr.$k.warn");
            $c = $r->input("thr.$k.crit");
            if (($w === null || $w === '') && ($c === null || $c === '')) {
                continue;
            }
            if (! is_numeric($w) || ! is_numeric($c) || (float) $w === (float) $c) {
                throw \Illuminate\Validation\ValidationException::withMessages(["thr.$k" => "Isi peringatan dan kritis {$label} (keduanya angka dan berbeda)."]);
            }
            $out[$k] = [(float) $w, (float) $c];
        }

        return $out;
    }

    /** Apakah menjadikan $parentId induk dari $deviceId membuat rantai melingkar? */
    public static function wouldLoop(int $deviceId, int $parentId): bool
    {
        $cur = $parentId;
        for ($i = 0; $i < 32 && $cur; $i++) {
            if ($cur === $deviceId) {
                return true;
            }
            $cur = (int) Device::whereKey($cur)->value('parent_id');
        }

        return false;
    }

    public function toggleMonitor(Device $device, int $ifIndex)
    {
        $port = $device->ports()->where('if_index', $ifIndex)->firstOrFail();
        $q = \Illuminate\Support\Facades\DB::table('monitored_ports')->where(['device_id' => $device->id, 'if_index' => $ifIndex]);
        if ($q->exists()) {
            $q->delete();
            $msg = "Port {$port->label()} tidak lagi dipantau.";
        } else {
            \Illuminate\Support\Facades\DB::table('monitored_ports')->insert(['device_id' => $device->id, 'if_index' => $ifIndex]);
            $msg = "Port {$port->label()} dipantau: problem bila putus atau penuh.";
        }
        Audit::log('updated', "{$device->name}: {$msg}", $device);

        return back()->with('ok', $msg);
    }

    private function fill(Device $device, Request $request): void
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'parent_id' => ['nullable', 'integer', 'exists:devices,id', Rule::notIn(array_filter([$device->id]))],
            'collector_id' => ['nullable', 'integer', 'exists:site_collectors,id'],
            'template_id' => ['nullable', 'integer', 'exists:device_templates,id'],
            'macros' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_keys(Device::CATEGORIES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['boolean'],
            'ping_enabled' => ['boolean'],
            'snmp_enabled' => ['boolean'],
            'snmp_version' => ['required', Rule::in(['1', '2c', '3'])],
            'snmp_port' => ['required', 'integer', 'between:1,65535'],
            'snmp_community' => ['nullable', 'string', 'max:128'],
            'snmp_v3_user' => ['nullable', 'required_if:snmp_version,3', 'string', 'max:64'],
            'snmp_v3_level' => ['nullable', Rule::in(['noAuthNoPriv', 'authNoPriv', 'authPriv'])],
            'snmp_v3_auth_proto' => ['nullable', Rule::in(['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'])],
            'snmp_v3_auth_pass' => ['nullable', 'string', 'min:8', 'max:128'],
            'snmp_v3_priv_proto' => ['nullable', Rule::in(['DES', 'AES', 'AES192', 'AES256'])],
            'snmp_v3_priv_pass' => ['nullable', 'string', 'min:8', 'max:128'],
            'cfg_type' => ['nullable', Rule::in(array_keys(Device::CFG_TYPES))],
            'ssh_port' => ['nullable', 'integer', 'between:1,65535'],
            'ssh_user' => ['nullable', 'required_with:cfg_type', 'string', 'max:64'],
            'ssh_password' => ['nullable', 'string', 'max:128'],
        ], [
            'ssh_user.required_with' => 'User SSH wajib diisi untuk backup konfigurasi.',
            'host.regex' => 'Isi alamat IP atau nama DNS saja (tanpa http:// atau spasi).',
            'snmp_v3_user.required_if' => 'User SNMPv3 wajib diisi.',
            'snmp_v3_auth_pass.min' => 'Password SNMPv3 minimal 8 karakter (aturan SNMPv3).',
            'snmp_v3_priv_pass.min' => 'Password SNMPv3 minimal 8 karakter (aturan SNMPv3).',
        ]);
        if (! empty($d['parent_id']) && $device->exists && self::wouldLoop($device->id, (int) $d['parent_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['parent_id' => 'Perangkat induk tidak boleh berada di bawah perangkat ini (rantai melingkar).']);
        }
        $device->thresholds = self::parseThresholds($request);
        [$macros, $badMacro] = \App\Services\TemplateSync::parseMacros($d['macros'] ?? '');
        if ($badMacro) {
            throw \Illuminate\Validation\ValidationException::withMessages(['macros' => 'Baris makro tidak sah: ' . implode('; ', array_slice($badMacro, 0, 3)) . '. Format: NAMA=nilai.']);
        }
        $d['macros'] = $macros;
        foreach (['enabled', 'ping_enabled', 'snmp_enabled'] as $b) {
            $d[$b] = $request->boolean($b);
        }
        if (! $d['ping_enabled'] && ! $d['snmp_enabled']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ping_enabled' => 'Aktifkan ping, SNMP, atau keduanya, perangkat harus bisa dipantau.']);
        }
        $d['ssh_port'] = (int) ($d['ssh_port'] ?? 22) ?: 22;
        $device->fill(collect($d)->except(['snmp_community', 'snmp_v3_auth_pass', 'snmp_v3_priv_pass', 'ssh_password'])->all());
        $device->setSecret('ssh_password_enc', $d['ssh_password'] ?? null);
        if (! empty($d['cfg_type']) && ! $device->hasSecret('ssh_password_enc')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ssh_password' => 'Password SSH wajib diisi untuk backup konfigurasi.']);
        }
        if ($device->isDirty('host')) {
            $device->ssh_hostkey = null; // host berubah: host key lama tidak berlaku
        }
        $device->setSecret('snmp_community_enc', $d['snmp_community'] ?? null);
        $device->setSecret('snmp_v3_auth_enc', $d['snmp_v3_auth_pass'] ?? null);
        $device->setSecret('snmp_v3_priv_enc', $d['snmp_v3_priv_pass'] ?? null);

        if ($d['snmp_enabled']) {
            $missing = $d['snmp_version'] === '3'
                ? (($d['snmp_v3_level'] ?? 'authPriv') !== 'noAuthNoPriv' && ! $device->hasSecret('snmp_v3_auth_enc'))
                    || (($d['snmp_v3_level'] ?? 'authPriv') === 'authPriv' && ! $device->hasSecret('snmp_v3_priv_enc'))
                : ! $device->hasSecret('snmp_community_enc');
            if ($missing) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $d['snmp_version'] === '3' ? 'snmp_v3_auth_pass' : 'snmp_community' => 'Kredensial SNMP wajib diisi untuk perangkat ini.',
                ]);
            }
        }
        if ($device->isDirty('host') || $device->isDirty('snmp_enabled')) {
            // Host/pengaturan berubah: status lama tidak lagi berlaku
            $device->status = 'unknown';
            $device->fail_streak = 0;
        }
    }
}
