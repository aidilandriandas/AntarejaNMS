<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ekspor/impor konfigurasi ANTAREJA dalam satu berkas JSON (pindah VM, VM uji, cadangan yang bisa dibaca).
 *
 * - Data operasional (status, riwayat, metrik, log) & akun pengguna TIDAK ikut.
 * - Rahasia (community/USM SNMP, password SSH & kamera, token/URL/password kanal) hanya ikut bila diminta, dan
 *   dienkripsi dengan frasa sandi ekspor (AES-256-GCM, kunci PBKDF2-SHA256) — bukan ANTAREJA_APP_KEY,
 *   karena VM tujuan bisa memakai kunci lain.
 * - Impor hanya menambah & memperbarui (kunci: kode site, nama+host perangkat, dst.), tidak pernah menghapus.
 */
class ConfigTransfer
{
    public const FORMAT = 'antareja-config';

    public const VERSION = 1;

    private const KDF_ITER = 200000;

    /** Pengaturan yang ikut (awalan kunci). */
    private const SETTING_PREFIX = ['threshold.', 'sla.', 'syslog.', 'backup.', 'map.', 'ipam.', 'anomaly.'];

    private const TRIGGER_OK = ['severity' => ['info', 'warning', 'average', 'high', 'disaster'], 'metric' => ['latency', 'loss', 'sensor', 'port_in', 'port_out', 'port_util', 'port_errors', 'port_discards'],
        'op' => ['>', '>=', '<', '<='], 'scope' => ['all', 'site', 'category', 'devices']];

    private const CHANNEL_SECRET = ['telegram' => ['bot_token'], 'email' => ['password'], 'webhook' => ['url', 'secret']];

    private const CATEGORIES_OK = ['snmp_version' => ['1', '2c', '3'], 'cfg_type' => [null, 'mikrotik', 'cisco'], 'frequency' => ['daily', 'weekly', 'monthly'],
        'snmp_v3_level' => ['noAuthNoPriv', 'authNoPriv', 'authPriv'], 'snmp_v3_auth_proto' => ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'],
        'snmp_v3_priv_proto' => ['DES', 'AES', 'AES192', 'AES256']];

    private static function pick(array $x, string $k): ?string
    {
        return in_array($x[$k] ?? null, self::CATEGORIES_OK[$k], true) ? $x[$k] : null;
    }

    // ---------------------------------------------------------------- ekspor

    public function export(bool $withSecrets, ?string $pass): array
    {
        $sites = DB::table('sites')->orderBy('code')->get();
        $siteCode = $sites->pluck('code', 'id');
        $devices = DB::table('devices')->orderBy('name')->get();
        $collectorName = DB::table('site_collectors')->pluck('name', 'id');
        $points = DB::table('modbus_points')->orderBy('position')->orderBy('id')->get()->groupBy('device_id');
        $devKey = $devices->mapWithKeys(fn ($d) => [$d->id => ['name' => $d->name, 'host' => $d->host]]);
        $secrets = ['devices' => [], 'cameras' => [], 'channels' => []];
        $dec = fn (?string $v) => $v ? Crypt::decryptString($v) : null;

        $out = [
            'format' => self::FORMAT, 'version' => self::VERSION, 'exported_at' => now()->toIso8601String(),
            'app_version' => config('antareja.version'),
            'sites' => $sites->map(fn ($s) => ['code' => $s->code, 'name' => $s->name, 'description' => $s->description ?? null,
                'lat' => $s->lat, 'lng' => $s->lng])->values(),
            'site_collectors' => DB::table('site_collectors')->orderBy('name')->get()->map(fn ($c) => ['name' => $c->name,
                'site' => $siteCode[$c->site_id] ?? null, 'notes' => $c->notes, 'enabled' => (bool) $c->enabled])->values(),
            'devices' => $devices->map(function ($d) use ($siteCode, $devKey, $withSecrets, &$secrets, $dec, $collectorName, $points) {
                if ($withSecrets) {
                    $sec = array_filter(['snmp_community' => $dec($d->snmp_community_enc), 'snmp_v3_auth' => $dec($d->snmp_v3_auth_enc),
                        'snmp_v3_priv' => $dec($d->snmp_v3_priv_enc), 'ssh_password' => $dec($d->ssh_password_enc)], fn ($v) => $v !== null);
                    if ($sec) {
                        $secrets['devices'][$d->name . '|' . $d->host] = $sec;
                    }
                }

                return ['name' => $d->name, 'host' => $d->host, 'site' => $siteCode[$d->site_id] ?? null, 'category' => $d->category,
                    'enabled' => (bool) $d->enabled, 'ping_enabled' => (bool) $d->ping_enabled, 'snmp_enabled' => (bool) $d->snmp_enabled,
                    'snmp_version' => $d->snmp_version, 'snmp_port' => $d->snmp_port, 'snmp_v3_user' => $d->snmp_v3_user,
                    'snmp_v3_level' => $d->snmp_v3_level, 'snmp_v3_auth_proto' => $d->snmp_v3_auth_proto, 'snmp_v3_priv_proto' => $d->snmp_v3_priv_proto,
                    'parent' => $d->parent_id ? ($devKey[$d->parent_id] ?? null) : null, 'thresholds' => json_decode($d->thresholds ?: '{}', true) ?: (object) [],
                    'cfg_type' => $d->cfg_type, 'ssh_port' => $d->ssh_port, 'ssh_user' => $d->ssh_user, 'lat' => $d->lat, 'lng' => $d->lng, 'notes' => $d->notes,
                    'collector' => $d->collector_id ? ($collectorName[$d->collector_id] ?? null) : null,
                    'exporter' => $d->exporter_url ? ['url' => $d->exporter_url, 'services' => $d->exporter_services] : null,
                    'modbus' => ($d->modbus_port || isset($points[$d->id])) ? ['port' => $d->modbus_port, 'unit' => $d->modbus_unit,
                        'points' => ($points[$d->id] ?? collect())->map(fn ($p) => ['label' => $p->label, 'class' => $p->class, 'unit' => $p->unit, 'fn' => $p->fn,
                            'address' => $p->address, 'dtype' => $p->dtype, 'word_swap' => (bool) $p->word_swap, 'scale' => (float) $p->scale,
                            'offset' => (float) $p->offset_value, 'warn_low' => $p->warn_low, 'warn_high' => $p->warn_high, 'crit_low' => $p->crit_low,
                            'crit_high' => $p->crit_high, 'enabled' => (bool) $p->enabled])->values()] : null];
            })->values(),
            'links' => DB::table('device_links')->orderBy('id')->get()->map(fn ($l) => ['a' => $devKey[$l->a_device_id] ?? null, 'a_if' => $l->a_if_index,
                'b' => $devKey[$l->b_device_id] ?? null, 'b_if' => $l->b_if_index, 'note' => $l->note])->filter(fn ($l) => $l['a'] && $l['b'])->values(),
            'subnets' => DB::table('subnets')->where('source', 'manual')->orderBy('cidr')->get()->map(fn ($s) => ['cidr' => $s->cidr, 'name' => $s->name,
                'site' => $siteCode[$s->site_id] ?? null, 'vlan' => $s->vlan, 'note' => $s->note])->values(),
            'cameras' => DB::table('cameras')->orderBy('sort')->orderBy('name')->get()->map(function ($c) use ($siteCode, $devKey, $withSecrets, &$secrets, $dec) {
                if ($withSecrets && $c->password_enc) {
                    $secrets['cameras'][$c->name . '|' . $c->host . '|' . $c->channel] = ['password' => $dec($c->password_enc)];
                }

                return ['name' => $c->name, 'host' => $c->host, 'channel' => $c->channel, 'rtsp_port' => $c->rtsp_port, 'path_main' => $c->path_main,
                    'path_sub' => $c->path_sub, 'username' => $c->username, 'enabled' => (bool) $c->enabled, 'sort' => $c->sort,
                    'site' => $siteCode[$c->site_id] ?? null, 'device' => $c->device_id ? ($devKey[$c->device_id] ?? null) : null];
            })->values(),
            'channels' => DB::table('notify_channels')->orderBy('name')->get()->map(function ($c) use ($siteCode, $withSecrets, &$secrets) {
                $cfg = json_decode(Crypt::decryptString($c->config_enc), true) ?: [];
                $secretKeys = self::CHANNEL_SECRET[$c->type] ?? [];
                $sec = array_intersect_key($cfg, array_flip($secretKeys));
                if ($withSecrets && $sec) {
                    $secrets['channels'][$c->name . '|' . $c->type] = $sec;
                }

                return ['name' => $c->name, 'type' => $c->type, 'config' => array_diff_key($cfg, array_flip($secretKeys)),
                    'min_severity' => $c->min_severity, 'delay_minutes' => $c->delay_minutes, 'notify_resolve' => (bool) $c->notify_resolve,
                    'to_oncall' => (bool) ($c->to_oncall ?? false), 'site' => $siteCode[$c->site_id] ?? null, 'enabled' => (bool) $c->enabled,
                    'template_open' => $c->template_open ?? null, 'template_resolve' => $c->template_resolve ?? null];
            })->values(),
            'report_schedules' => DB::table('report_schedules as r')->join('notify_channels as c', 'c.id', '=', 'r.channel_id')->orderBy('r.name')
                ->get(['r.*', 'c.name as ch_name', 'c.type as ch_type'])->map(fn ($r) => ['name' => $r->name, 'frequency' => $r->frequency,
                    'send_hour' => $r->send_hour, 'channel' => ['name' => $r->ch_name, 'type' => $r->ch_type], 'site' => $siteCode[$r->site_id] ?? null,
                    'enabled' => (bool) $r->enabled])->values(),
            'services' => DB::table('services')->orderBy('name')->get()->map(fn ($sv) => ['name' => $sv->name, 'description' => $sv->description,
                'site' => $siteCode[$sv->site_id] ?? null, 'target_pct' => $sv->target_pct !== null ? (float) $sv->target_pct : null,
                'components' => DB::table('service_components')->where('service_id', $sv->id)->orderBy('position')->get()->map(fn ($c) => [
                    'name' => $c->name, 'min_up' => $c->min_up,
                    'devices' => DB::table('service_component_devices')->where('component_id', $c->id)->pluck('device_id')->map(fn ($id) => $devKey[$id] ?? null)->filter()->values(),
                ])->values()])->values(),
            'triggers' => DB::table('triggers')->orderBy('id')->get()->map(fn ($t) => ['name' => $t->name, 'enabled' => (bool) $t->enabled, 'severity' => $t->severity,
                'metric' => $t->metric, 'sensor_class' => $t->sensor_class, 'match_text' => $t->match_text, 'op' => $t->op, 'threshold' => (float) $t->threshold,
                'duration_min' => $t->duration_min, 'scope' => $t->scope, 'site' => $siteCode[$t->site_id] ?? null, 'category' => $t->category,
                'days' => $t->days, 'start_min' => $t->start_min, 'end_min' => $t->end_min, 'note' => $t->note,
                'devices' => DB::table('trigger_devices')->where('trigger_id', $t->id)->pluck('device_id')->map(fn ($id) => $devKey[$id] ?? null)->filter()->values()])->values(),
            'checks' => DB::table('service_checks')->orderBy('name')->get()->map(fn ($c) => ['name' => $c->name, 'device' => $devKey[$c->device_id] ?? null,
                'type' => $c->type, 'url' => $c->url, 'port' => $c->port, 'query' => $c->query, 'expect_status' => $c->expect_status, 'expect_text' => $c->expect_text,
                'expect_answer' => $c->expect_answer, 'verify_tls' => (bool) $c->verify_tls, 'interval_s' => $c->interval_s, 'timeout_s' => $c->timeout_s,
                'slow_ms' => $c->slow_ms, 'cert_warn_days' => $c->cert_warn_days, 'severity' => $c->severity, 'enabled' => (bool) $c->enabled])
                ->filter(fn ($c) => $c['device'])->values(),
            'runbooks' => DB::table('runbooks')->orderBy('title')->get()->map(fn ($r) => ['title' => $r->title, 'body' => $r->body,
                'problem_kind' => $r->problem_kind, 'category' => $r->category, 'site' => $siteCode[$r->site_id] ?? null,
                'device' => $r->device_id ? ($devKey[$r->device_id] ?? null) : null, 'enabled' => (bool) $r->enabled])->values(),
            'settings' => DB::table('settings')->orderBy('key')->get()->filter(fn ($s) => collect(self::SETTING_PREFIX)->contains(fn ($p) => str_starts_with($s->key, $p)))
                ->mapWithKeys(fn ($s) => [$s->key => json_decode($s->value, true)]),
        ];
        if ($withSecrets) {
            if (strlen((string) $pass) < 12) {
                throw new RuntimeException('Frasa sandi minimal 12 karakter.');
            }
            $out['secrets'] = self::seal(json_encode($secrets), $pass);
        }

        return $out;
    }

    public static function seal(string $plain, string $pass): array
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $pass, $salt, self::KDF_ITER, 32, true);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return ['alg' => 'AES-256-GCM', 'kdf' => 'PBKDF2-SHA256', 'iter' => self::KDF_ITER, 'salt' => base64_encode($salt),
            'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($ct)];
    }

    public static function open(array $box, string $pass): array
    {
        $iter = (int) ($box['iter'] ?? 0);
        if (($box['alg'] ?? '') !== 'AES-256-GCM' || $iter < 100000 || $iter > 2000000) {
            throw new RuntimeException('Format bagian rahasia tidak dikenal.');
        }
        $key = hash_pbkdf2('sha256', $pass, base64_decode($box['salt'] ?? ''), $iter, 32, true);
        $plain = openssl_decrypt(base64_decode($box['data'] ?? ''), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, base64_decode($box['iv'] ?? ''), base64_decode($box['tag'] ?? ''));
        if ($plain === false) {
            throw new RuntimeException('Frasa sandi salah atau berkas rusak.');
        }

        return json_decode($plain, true) ?: [];
    }

    // ---------------------------------------------------------------- impor

    /** Baca & periksa berkas; kembalikan data atau lempar galat yang bisa ditampilkan. */
    public function parse(string $json): array
    {
        $d = json_decode($json, true);
        if (! is_array($d) || ($d['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Bukan berkas ekspor ANTAREJA.');
        }
        if ((int) ($d['version'] ?? 0) > self::VERSION) {
            throw new RuntimeException('Berkas dari versi ANTAREJA yang lebih baru, perbarui aplikasi dulu.');
        }
        foreach (['sites', 'site_collectors', 'devices', 'links', 'subnets', 'cameras', 'channels', 'report_schedules', 'services', 'triggers', 'checks', 'runbooks'] as $k) {
            $d[$k] = array_values(array_filter((array) ($d[$k] ?? []), 'is_array'));
        }
        $d['settings'] = is_array($d['settings'] ?? null) ? $d['settings'] : [];

        return $d;
    }

    /** Rencana impor (tanpa menulis): jumlah baru / diperbarui per bagian + peringatan. */
    public function plan(array $d): array
    {
        $p = ['warnings' => []];
        $sites = DB::table('sites')->pluck('id', 'code');
        $p['sites'] = $this->count($d['sites'], fn ($s) => isset($sites[strtoupper((string) ($s['code'] ?? ''))]));
        $devs = DB::table('devices')->get(['name', 'host'])->map(fn ($x) => $x->name . '|' . $x->host)->flip();
        $p['devices'] = $this->count($d['devices'], fn ($x) => isset($devs[($x['name'] ?? '') . '|' . ($x['host'] ?? '')]));
        $p['links'] = ['total' => count($d['links'])];
        $cidrs = DB::table('subnets')->pluck('id', 'cidr')->mapWithKeys(fn ($v, $k) => [(string) $k => $v]);
        $p['subnets'] = $this->count($d['subnets'], fn ($s) => isset($cidrs[(string) ($s['cidr'] ?? '')]));
        $cams = DB::table('cameras')->get(['name', 'host', 'channel'])->map(fn ($c) => $c->name . '|' . $c->host . '|' . $c->channel)->flip();
        $p['cameras'] = $this->count($d['cameras'], fn ($c) => isset($cams[($c['name'] ?? '') . '|' . ($c['host'] ?? '') . '|' . ($c['channel'] ?? '')]));
        $chs = DB::table('notify_channels')->get(['name', 'type'])->map(fn ($c) => $c->name . '|' . $c->type)->flip();
        $p['channels'] = $this->count($d['channels'], fn ($c) => isset($chs[($c['name'] ?? '') . '|' . ($c['type'] ?? '')]));
        $rs = DB::table('report_schedules')->pluck('id', 'name');
        $p['report_schedules'] = $this->count($d['report_schedules'], fn ($r) => isset($rs[$r['name'] ?? '']));
        $sv = DB::table('services')->pluck('id', 'name');
        $p['services'] = $this->count($d['services'], fn ($x) => isset($sv[$x['name'] ?? '']));
        $tr = DB::table('triggers')->pluck('id', 'name');
        $p['triggers'] = $this->count($d['triggers'], fn ($x) => isset($tr[$x['name'] ?? '']));
        $sc = DB::table('site_collectors')->pluck('id', 'name');
        $p['site_collectors'] = $this->count($d['site_collectors'], fn ($x) => isset($sc[$x['name'] ?? '']));
        $ck = DB::table('service_checks as c')->join('devices as d', 'd.id', '=', 'c.device_id')->get(['c.name', 'd.name as dn', 'd.host'])
            ->map(fn ($x) => $x->name . '|' . $x->dn . '|' . $x->host)->flip();
        $p['checks'] = $this->count($d['checks'], fn ($x) => isset($ck[($x['name'] ?? '') . '|' . ($x['device']['name'] ?? '') . '|' . ($x['device']['host'] ?? '')]));
        $rb = DB::table('runbooks')->pluck('id', 'title');
        $p['runbooks'] = $this->count($d['runbooks'], fn ($x) => isset($rb[$x['title'] ?? '']));
        $p['modbus_points'] = ['total' => collect($d['devices'])->sum(fn ($x) => count((array) ($x['modbus']['points'] ?? [])))];
        if ($p['site_collectors']['new'] > 0) {
            $p['warnings'][] = 'Collector site baru dibuat dengan token acak yang tidak diketahui siapa pun: buka Collector site, klik Ganti token, lalu pasang token baru di site.';
        }
        $p['settings'] = ['total' => count($this->allowedSettings($d['settings']))];
        $p['has_secrets'] = isset($d['secrets']);
        $bad = collect($d['devices'])->filter(fn ($x) => ! $this->validDevice($x))->count();
        if ($bad) {
            $p['warnings'][] = "{$bad} perangkat tidak valid (nama/host/kategori) akan dilewati.";
        }
        $known = collect($d['sites'])->pluck('code')->map(fn ($c) => strtoupper((string) $c))->merge($sites->keys())->flip();
        $missing = collect($d['devices'])->pluck('site')->filter()->map(fn ($c) => strtoupper($c))->unique()->reject(fn ($c) => isset($known[$c]));
        if ($missing->isNotEmpty()) {
            $p['warnings'][] = 'Site ' . $missing->implode(', ') . ' belum ada dan akan dibuat otomatis.';
        }
        if (collect($d['channels'])->isNotEmpty() && ! $p['has_secrets']) {
            $p['warnings'][] = 'Berkas tanpa rahasia: kanal notifikasi baru dibuat nonaktif sampai token/password diisi.';
        }

        return $p;
    }

    private function count(array $rows, callable $exists): array
    {
        $new = 0;
        $upd = 0;
        foreach ($rows as $r) {
            $exists($r) ? $upd++ : $new++;
        }

        return ['total' => count($rows), 'new' => $new, 'update' => $upd];
    }

    /** Baris service_checks dari berkas, atau null bila tidak valid. */
    private function checkRow(array $c): ?array
    {
        $name = self::str($c['name'] ?? null, 128);
        $type = $c['type'] ?? '';
        if (! $name || ! in_array($type, ['http', 'tcp', 'dns'], true)) {
            return null;
        }
        $url = self::str($c['url'] ?? null, 500);
        $port = is_numeric($c['port'] ?? null) ? self::int($c['port'], 1, 65535, 0) : null;
        $query = self::str($c['query'] ?? null, 255);
        if (($type === 'http' && ! preg_match('#^https?://\S+$#i', (string) $url)) || ($type === 'tcp' && ! $port)
            || ($type === 'dns' && ! preg_match('/^[A-Za-z0-9._-]+$/', (string) $query))) {
            return null;
        }
        $status = self::str($c['expect_status'] ?? null, 32);
        if ($status !== null && ! preg_match('/^\s*\d{3}(\s*-\s*\d{3})?(\s*,\s*\d{3}(\s*-\s*\d{3})?)*\s*$/', $status)) {
            $status = null;
        }
        $answer = filter_var($c['expect_answer'] ?? null, FILTER_VALIDATE_IP) ?: null;
        $interval = (int) ($c['interval_s'] ?? 60);

        return ['name' => $name, 'type' => $type, 'url' => $type === 'http' ? $url : null, 'port' => $type === 'http' ? null : $port,
            'query' => $type === 'dns' ? $query : null, 'expect_status' => $type === 'http' ? $status : null,
            'expect_text' => $type === 'http' ? self::str($c['expect_text'] ?? null, 200) : null, 'expect_answer' => $type === 'dns' ? $answer : null,
            'verify_tls' => (bool) ($c['verify_tls'] ?? true), 'interval_s' => in_array($interval, [30, 60, 120, 300, 900, 3600], true) ? $interval : 60,
            'timeout_s' => self::int($c['timeout_s'] ?? 10, 1, 60, 10), 'slow_ms' => is_numeric($c['slow_ms'] ?? null) ? self::int($c['slow_ms'], 1, 600000, 1000) : null,
            'cert_warn_days' => $type === 'http' && is_numeric($c['cert_warn_days'] ?? null) ? self::int($c['cert_warn_days'], 1, 365, 14) : null,
            'severity' => in_array($c['severity'] ?? '', ['info', 'warning', 'average', 'high', 'disaster'], true) ? $c['severity'] : 'high',
            'enabled' => (bool) ($c['enabled'] ?? true)];
    }

    /** Baris modbus_points dari berkas, atau null bila tidak valid. */
    private function modbusPoint(array $p): ?array
    {
        $label = self::str($p['label'] ?? null, 80);
        $num = fn ($k) => is_numeric($p[$k] ?? null) && abs((float) $p[$k]) <= 1e9 ? (float) $p[$k] : null;
        $scale = $num('scale');
        if (! $label || ! isset(\App\Models\ModbusPoint::CLASSES[$p['class'] ?? '']) || ! in_array((int) ($p['fn'] ?? 0), [3, 4], true)
            || ! isset(\App\Models\ModbusPoint::DTYPES[$p['dtype'] ?? '']) || ! is_numeric($p['address'] ?? null) || ! $scale) {
            return null;
        }

        return ['label' => $label, 'class' => $p['class'], 'unit' => self::str($p['unit'] ?? null, 12) ?? '', 'fn' => (int) $p['fn'],
            'address' => self::int($p['address'], 0, 65535, 0), 'dtype' => $p['dtype'], 'word_swap' => (bool) ($p['word_swap'] ?? false),
            'scale' => $scale, 'offset_value' => $num('offset') ?? 0.0, 'warn_low' => $num('warn_low'), 'warn_high' => $num('warn_high'),
            'crit_low' => $num('crit_low'), 'crit_high' => $num('crit_high'), 'enabled' => (bool) ($p['enabled'] ?? true)];
    }

    private function validDevice(array $x): bool
    {
        return is_string($x['name'] ?? null) && $x['name'] !== '' && mb_strlen($x['name']) <= 128
            && is_string($x['host'] ?? null) && $x['host'] !== '' && strlen($x['host']) <= 255 && ! preg_match('/\s/', $x['host'])
            && isset(Device::CATEGORIES[$x['category'] ?? '']);
    }

    private function allowedSettings(array $s): array
    {
        return array_filter($s, fn ($k) => is_string($k) && collect(self::SETTING_PREFIX)->contains(fn ($p) => str_starts_with($k, $p)), ARRAY_FILTER_USE_KEY);
    }

    private static function str($v, int $max): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? mb_substr((string) $v, 0, $max) : null;
    }

    private static function int($v, int $min, int $max, int $def): int
    {
        return is_numeric($v) ? max($min, min($max, (int) $v)) : $def;
    }

    private static function coord($v, float $lim): ?float
    {
        return is_numeric($v) && abs((float) $v) <= $lim ? (float) $v : null;
    }

    /** Terapkan impor dalam satu transaksi. */
    public function apply(array $d, ?string $pass): array
    {
        $sec = ['devices' => [], 'cameras' => [], 'channels' => []];
        if (isset($d['secrets']) && $pass !== null && $pass !== '') {
            $sec = self::open($d['secrets'], $pass) + $sec;
        }
        $enc = fn (?string $v) => $v !== null && $v !== '' ? Crypt::encryptString($v) : null;
        $res = ['site_collectors' => 0, 'checks' => 0, 'runbooks' => 0, 'modbus_points' => 0, 'sites' => 0, 'devices' => 0, 'links' => 0, 'subnets' => 0, 'cameras' => 0, 'channels' => 0, 'report_schedules' => 0, 'services' => 0, 'triggers' => 0, 'settings' => 0, 'skipped' => 0];

        DB::transaction(function () use ($d, $sec, $enc, &$res) {
            $now = now();
            // Site
            foreach ($d['sites'] as $s) {
                $code = strtoupper((string) self::str($s['code'] ?? null, 32));
                if (! preg_match('/^[A-Z0-9._-]+$/', $code)) {
                    $res['skipped']++;
                    continue;
                }
                DB::table('sites')->updateOrInsert(['code' => $code], ['name' => self::str($s['name'] ?? null, 128) ?? $code,
                    'description' => self::str($s['description'] ?? null, 1000),
                    'lat' => self::coord($s['lat'] ?? null, 90), 'lng' => self::coord($s['lng'] ?? null, 180), 'updated_at' => $now]);
                $res['sites']++;
            }
            $siteId = function (?string $code) use ($now) {
                $code = strtoupper((string) $code);
                if ($code === '' || ! preg_match('/^[A-Z0-9._-]{1,32}$/', $code)) {
                    return null;
                }
                $id = DB::table('sites')->where('code', $code)->value('id');

                return $id ?: DB::table('sites')->insertGetId(['code' => $code, 'name' => $code, 'created_at' => $now, 'updated_at' => $now]);
            };

            // Collector site (token baru acak; admin mengganti token untuk melihatnya)
            foreach ($d['site_collectors'] as $c) {
                $name = self::str($c['name'] ?? null, 64);
                if (! $name) {
                    $res['skipped']++;
                    continue;
                }
                $row = ['site_id' => $siteId($c['site'] ?? null), 'notes' => self::str($c['notes'] ?? null, 1000), 'enabled' => (bool) ($c['enabled'] ?? true), 'updated_at' => $now];
                if (DB::table('site_collectors')->where('name', $name)->exists()) {
                    DB::table('site_collectors')->where('name', $name)->update($row);
                } else {
                    DB::table('site_collectors')->insert($row + ['name' => $name, 'token_hash' => hash('sha256', 'acs_' . \Illuminate\Support\Str::random(48)),
                        'token_hint' => 'acs_impor', 'created_at' => $now]);
                }
                $res['site_collectors']++;
            }
            $collectorId = fn ($n) => is_string($n) && $n !== '' ? DB::table('site_collectors')->where('name', $n)->value('id') : null;

            // Perangkat (induk diset setelah semua ada)
            $devId = fn (?array $k) => $k ? DB::table('devices')->where('name', $k['name'] ?? '')->where('host', $k['host'] ?? '')->value('id') : null;
            foreach ($d['devices'] as $x) {
                if (! $this->validDevice($x)) {
                    $res['skipped']++;
                    continue;
                }
                $s = $sec['devices'][$x['name'] . '|' . $x['host']] ?? [];
                $cfg = in_array($x['cfg_type'] ?? null, self::CATEGORIES_OK['cfg_type'], true) ? ($x['cfg_type'] ?? null) : null;
                $row = ['site_id' => $siteId($x['site'] ?? null), 'category' => $x['category'], 'enabled' => (bool) ($x['enabled'] ?? true),
                    'ping_enabled' => (bool) ($x['ping_enabled'] ?? true), 'snmp_enabled' => (bool) ($x['snmp_enabled'] ?? false),
                    'snmp_version' => in_array($x['snmp_version'] ?? '2c', self::CATEGORIES_OK['snmp_version'], true) ? $x['snmp_version'] : '2c',
                    'snmp_port' => self::int($x['snmp_port'] ?? 161, 1, 65535, 161), 'snmp_v3_user' => self::str($x['snmp_v3_user'] ?? null, 64),
                    'snmp_v3_level' => self::pick($x, 'snmp_v3_level'), 'snmp_v3_auth_proto' => self::pick($x, 'snmp_v3_auth_proto'),
                    'snmp_v3_priv_proto' => self::pick($x, 'snmp_v3_priv_proto'),
                    'thresholds' => json_encode(is_array($x['thresholds'] ?? null) ? $x['thresholds'] : (object) []),
                    'cfg_type' => $cfg, 'ssh_port' => self::int($x['ssh_port'] ?? 22, 1, 65535, 22), 'ssh_user' => self::str($x['ssh_user'] ?? null, 64),
                    'lat' => self::coord($x['lat'] ?? null, 90), 'lng' => self::coord($x['lng'] ?? null, 180), 'notes' => self::str($x['notes'] ?? null, 5000),
                    'collector_id' => $collectorId($x['collector'] ?? null),
                    'updated_at' => $now];
                if (is_array($x['exporter'] ?? null)) {
                    $eu = self::str($x['exporter']['url'] ?? null, 255);
                    $pu = $eu ? parse_url($eu) : false;
                    $row['exporter_url'] = ($pu && in_array($pu['scheme'] ?? '', ['http', 'https'], true) && ! empty($pu['host']) && ! isset($pu['user'])) ? $eu : null;
                    $row['exporter_services'] = self::str($x['exporter']['services'] ?? null, 500);
                }
                if (is_array($x['modbus'] ?? null)) {
                    $row['modbus_port'] = is_numeric($x['modbus']['port'] ?? null) ? self::int($x['modbus']['port'], 1, 65535, 502) : null;
                    $row['modbus_unit'] = self::int($x['modbus']['unit'] ?? 1, 0, 255, 1);
                }
                foreach (['snmp_community' => 'snmp_community_enc', 'snmp_v3_auth' => 'snmp_v3_auth_enc', 'snmp_v3_priv' => 'snmp_v3_priv_enc', 'ssh_password' => 'ssh_password_enc'] as $k => $col) {
                    if (isset($s[$k]) && is_string($s[$k]) && $s[$k] !== '') {
                        $row[$col] = $enc($s[$k]);
                    }
                }
                $id = $devId($x);
                $id ? DB::table('devices')->where('id', $id)->update($row)
                    : DB::table('devices')->insert($row + ['name' => mb_substr($x['name'], 0, 128), 'host' => $x['host'], 'created_at' => $now]);
                $res['devices']++;
            }
            foreach ($d['devices'] as $x) {
                if ($this->validDevice($x) && ($id = $devId($x))) {
                    $pid = is_array($x['parent'] ?? null) ? $devId($x['parent']) : null;
                    DB::table('devices')->where('id', $id)->update(['parent_id' => $pid !== $id ? $pid : null]);
                    // Titik Modbus: diganti seluruhnya bila berkas memuat bagian modbus untuk perangkat ini
                    if (is_array($x['modbus'] ?? null)) {
                        DB::table('modbus_points')->where('device_id', $id)->delete();
                        DB::table('device_sensors')->where('device_id', $id)->where('key', 'like', 'mb:%')->delete();
                        foreach (array_values(array_filter((array) ($x['modbus']['points'] ?? []), 'is_array')) as $i => $pt) {
                            if (! ($row = $this->modbusPoint($pt))) {
                                $res['skipped']++;
                                continue;
                            }
                            DB::table('modbus_points')->insert($row + ['device_id' => $id, 'position' => $i]);
                            $res['modbus_points']++;
                        }
                    }
                }
            }

            // Sambungan manual
            foreach ($d['links'] as $l) {
                $a = $devId($l['a'] ?? null);
                $b = $devId($l['b'] ?? null);
                if (! $a || ! $b || $a === $b) {
                    $res['skipped']++;
                    continue;
                }
                $k = ['a_device_id' => $a, 'a_if_index' => is_numeric($l['a_if'] ?? null) ? (int) $l['a_if'] : null,
                    'b_device_id' => $b, 'b_if_index' => is_numeric($l['b_if'] ?? null) ? (int) $l['b_if'] : null];
                if (! DB::table('device_links')->where($k)->exists()) {
                    DB::table('device_links')->insert($k + ['note' => self::str($l['note'] ?? null, 255), 'created_at' => $now]);
                    $res['links']++;
                }
            }

            // Subnet manual
            foreach ($d['subnets'] as $s) {
                $cidr = (string) ($s['cidr'] ?? '');
                if (! preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $cidr)) {
                    $res['skipped']++;
                    continue;
                }
                DB::table('subnets')->updateOrInsert(['cidr' => $cidr], ['name' => self::str($s['name'] ?? null, 128), 'site_id' => $siteId($s['site'] ?? null),
                    'vlan' => is_numeric($s['vlan'] ?? null) ? self::int($s['vlan'], 1, 4094, 1) : null, 'note' => self::str($s['note'] ?? null, 2000),
                    'source' => 'manual', 'updated_at' => $now]);
                $res['subnets']++;
            }

            // Kamera
            foreach ($d['cameras'] as $c) {
                $name = self::str($c['name'] ?? null, 128);
                $host = self::str($c['host'] ?? null, 255);
                if (! $name || ! $host || preg_match('/\s/', $host)) {
                    $res['skipped']++;
                    continue;
                }
                $ch = self::int($c['channel'] ?? 1, 1, 256, 1);
                $row = ['rtsp_port' => self::int($c['rtsp_port'] ?? 554, 1, 65535, 554), 'path_main' => self::str($c['path_main'] ?? null, 255) ?? '/Streaming/Channels/{ch}01',
                    'path_sub' => self::str($c['path_sub'] ?? null, 255) ?? '/Streaming/Channels/{ch}02', 'username' => self::str($c['username'] ?? null, 64),
                    'enabled' => (bool) ($c['enabled'] ?? true), 'sort' => self::int($c['sort'] ?? 0, 0, 100000, 0),
                    'site_id' => $siteId($c['site'] ?? null), 'device_id' => is_array($c['device'] ?? null) ? $devId($c['device']) : null, 'updated_at' => $now];
                $pw = $sec['cameras'][$name . '|' . $host . '|' . $ch]['password'] ?? null;
                if (is_string($pw) && $pw !== '') {
                    $row['password_enc'] = $enc($pw);
                }
                DB::table('cameras')->updateOrInsert(['name' => $name, 'host' => $host, 'channel' => $ch], $row);
                $res['cameras']++;
            }

            // Kanal notifikasi
            foreach ($d['channels'] as $c) {
                $type = $c['type'] ?? '';
                $name = self::str($c['name'] ?? null, 128);
                if (! $name || ! isset(self::CHANNEL_SECRET[$type])) {
                    $res['skipped']++;
                    continue;
                }
                $cfg = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 1000) : '', array_diff_key((array) ($c['config'] ?? []), array_flip(self::CHANNEL_SECRET[$type])));
                $s = array_map('strval', array_filter((array) ($sec['channels'][$name . '|' . $type] ?? []), 'is_scalar'));
                $existing = DB::table('notify_channels')->where('name', $name)->where('type', $type)->first();
                $old = $existing ? (json_decode(Crypt::decryptString($existing->config_enc), true) ?: []) : [];
                $full = $cfg + $s + array_intersect_key($old, array_flip(self::CHANNEL_SECRET[$type]));
                $complete = match ($type) { 'telegram' => ! empty($full['bot_token']), 'webhook' => ! empty($full['url']), default => true };
                $row = ['config_enc' => Crypt::encryptString(json_encode($full)),
                    'min_severity' => in_array($c['min_severity'] ?? '', ['info', 'warning', 'average', 'high', 'disaster'], true) ? $c['min_severity'] : 'average',
                    'delay_minutes' => self::int($c['delay_minutes'] ?? 0, 0, 1440, 0), 'notify_resolve' => (bool) ($c['notify_resolve'] ?? true),
                    'to_oncall' => in_array($type, ['email', 'telegram'], true) && (bool) ($c['to_oncall'] ?? false),
                    'site_id' => $siteId($c['site'] ?? null), 'enabled' => (bool) ($c['enabled'] ?? true) && $complete, 'updated_at' => $now,
                    'template_open' => self::str($c['template_open'] ?? null, 2000), 'template_resolve' => self::str($c['template_resolve'] ?? null, 2000)];
                $existing ? DB::table('notify_channels')->where('id', $existing->id)->update($row)
                    : DB::table('notify_channels')->insert($row + ['name' => $name, 'type' => $type, 'created_at' => $now]);
                $res['channels']++;
            }

            // Jadwal laporan
            foreach ($d['report_schedules'] as $r) {
                $name = self::str($r['name'] ?? null, 128);
                $ch = is_array($r['channel'] ?? null) ? DB::table('notify_channels')->where('name', $r['channel']['name'] ?? '')->where('type', $r['channel']['type'] ?? '')->value('id') : null;
                if (! $name || ! $ch || ! in_array($r['frequency'] ?? '', self::CATEGORIES_OK['frequency'], true)) {
                    $res['skipped']++;
                    continue;
                }
                DB::table('report_schedules')->updateOrInsert(['name' => $name], ['frequency' => $r['frequency'], 'send_hour' => self::int($r['send_hour'] ?? 7, 0, 23, 7),
                    'channel_id' => $ch, 'site_id' => $siteId($r['site'] ?? null), 'enabled' => (bool) ($r['enabled'] ?? true), 'updated_at' => $now]);
                $res['report_schedules']++;
            }

            // Layanan: komponen diganti seluruhnya dengan isi berkas; perangkat yang tidak ada di sini dilewati
            foreach ($d['services'] as $x) {
                $name = self::str($x['name'] ?? null, 120);
                $comps = array_values(array_filter((array) ($x['components'] ?? []), 'is_array'));
                if (! $name || ! $comps) {
                    $res['skipped']++;
                    continue;
                }
                $tp = is_numeric($x['target_pct'] ?? null) ? max(50, min(100, (float) $x['target_pct'])) : null;
                DB::table('services')->updateOrInsert(['name' => $name], ['description' => self::str($x['description'] ?? null, 500),
                    'site_id' => $siteId($x['site'] ?? null), 'target_pct' => $tp, 'updated_at' => $now]);
                $sid = DB::table('services')->where('name', $name)->value('id');
                DB::table('service_components')->where('service_id', $sid)->delete();
                foreach ($comps as $i => $c) {
                    $cid = DB::table('service_components')->insertGetId(['service_id' => $sid, 'name' => self::str($c['name'] ?? null, 120) ?? 'Komponen ' . ($i + 1),
                        'min_up' => self::int($c['min_up'] ?? 1, 1, 1000, 1), 'position' => $i]);
                    $ids = collect((array) ($c['devices'] ?? []))->filter(fn ($v) => is_array($v))->map(fn ($v) => $devId($v))->filter()->unique();
                    DB::table('service_component_devices')->insert($ids->map(fn ($id) => ['component_id' => $cid, 'device_id' => $id])->values()->all());
                }
                $res['services']++;
            }

            // Trigger custom (dicocokkan lewat nama)
            foreach ($d['triggers'] as $t) {
                $name = self::str($t['name'] ?? null, 120);
                $ok = $name && is_numeric($t['threshold'] ?? null);
                foreach (self::TRIGGER_OK as $k => $allowed) {
                    $ok = $ok && in_array($t[$k] ?? null, $allowed, true);
                }
                if ($ok && $t['metric'] === 'sensor' && ! self::str($t['sensor_class'] ?? null, 24)) {
                    $ok = false;
                }
                $sm = is_numeric($t['start_min'] ?? null) ? self::int($t['start_min'], 0, 1439, 0) : null;
                $em = is_numeric($t['end_min'] ?? null) ? self::int($t['end_min'], 1, 1440, 1440) : null;
                if (! $ok) {
                    $res['skipped']++;
                    continue;
                }
                $row = ['enabled' => (bool) ($t['enabled'] ?? true), 'severity' => $t['severity'], 'metric' => $t['metric'],
                    'sensor_class' => $t['metric'] === 'sensor' ? self::str($t['sensor_class'], 24) : null, 'match_text' => self::str($t['match_text'] ?? null, 120),
                    'op' => $t['op'], 'threshold' => (float) $t['threshold'], 'duration_min' => self::int($t['duration_min'] ?? 5, 0, 1440, 5),
                    'scope' => $t['scope'], 'site_id' => $t['scope'] === 'site' ? $siteId($t['site'] ?? null) : null,
                    'category' => $t['scope'] === 'category' ? self::str($t['category'] ?? null, 24) : null,
                    'days' => self::int($t['days'] ?? 127, 1, 127, 127), 'start_min' => $sm !== null && $em !== null ? $sm : null, 'end_min' => $sm !== null && $em !== null ? $em : null,
                    'note' => self::str($t['note'] ?? null, 500), 'updated_at' => $now];
                $tid = DB::table('triggers')->where('name', $name)->value('id');
                if ($tid) {
                    DB::table('triggers')->where('id', $tid)->update($row);
                } else {
                    $tid = DB::table('triggers')->insertGetId($row + ['name' => $name, 'created_at' => $now]);
                }
                DB::table('trigger_devices')->where('trigger_id', $tid)->delete();
                if ($t['scope'] === 'devices') {
                    $ids = collect((array) ($t['devices'] ?? []))->filter(fn ($v) => is_array($v))->map(fn ($v) => $devId($v))->filter()->unique();
                    DB::table('trigger_devices')->insert($ids->map(fn ($id) => ['trigger_id' => $tid, 'device_id' => $id])->values()->all());
                }
                $res['triggers']++;
            }

            // Cek layanan (dicocokkan lewat nama + perangkat)
            foreach ($d['checks'] as $c) {
                $dev = is_array($c['device'] ?? null) ? $devId($c['device']) : null;
                $row = $dev ? $this->checkRow($c) : null;
                if (! $row) {
                    $res['skipped']++;
                    continue;
                }
                $ex = DB::table('service_checks')->where('device_id', $dev)->where('name', $row['name'])->value('id');
                $ex ? DB::table('service_checks')->where('id', $ex)->update($row + ['updated_at' => $now])
                    : DB::table('service_checks')->insert($row + ['device_id' => $dev, 'created_at' => $now, 'updated_at' => $now]);
                $res['checks']++;
            }

            // Runbook (dicocokkan lewat judul)
            foreach ($d['runbooks'] as $r) {
                $title = self::str($r['title'] ?? null, 160);
                $body = is_string($r['body'] ?? null) ? mb_substr(trim($r['body']), 0, 6000) : '';
                $kind = $r['problem_kind'] ?? null;
                $cat = $r['category'] ?? null;
                if (! $title || $body === '' || ($kind !== null && ! isset(\App\Models\Problem::KINDS[$kind])) || ($cat !== null && ! isset(Device::CATEGORIES[$cat]))) {
                    $res['skipped']++;
                    continue;
                }
                $devRef = is_array($r['device'] ?? null) ? $devId($r['device']) : null;
                if (is_array($r['device'] ?? null) && ! $devRef) {
                    $res['skipped']++; // perangkatnya tidak ada: jangan jadikan runbook umum
                    continue;
                }
                DB::table('runbooks')->updateOrInsert(['title' => $title], ['body' => $body, 'problem_kind' => $kind, 'category' => $cat,
                    'site_id' => $siteId($r['site'] ?? null), 'device_id' => $devRef, 'enabled' => (bool) ($r['enabled'] ?? true), 'updated_at' => $now]);
                $res['runbooks']++;
            }

            // Pengaturan (hanya kunci yang diizinkan)
            foreach ($this->allowedSettings($d['settings']) as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    continue; // nilai bersarang tidak dipakai pengaturan yang diizinkan
                }
                DB::table('settings')->updateOrInsert(['key' => mb_substr($k, 0, 64)], ['value' => json_encode($v), 'updated_at' => $now]);
                $res['settings']++;
            }
        });

        return $res;
    }
}
