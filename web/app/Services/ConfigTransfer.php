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
    private const SETTING_PREFIX = ['threshold.', 'sla.', 'syslog.', 'backup.', 'map.', 'ipam.'];

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
        $devKey = $devices->mapWithKeys(fn ($d) => [$d->id => ['name' => $d->name, 'host' => $d->host]]);
        $secrets = ['devices' => [], 'cameras' => [], 'channels' => []];
        $dec = fn (?string $v) => $v ? Crypt::decryptString($v) : null;

        $out = [
            'format' => self::FORMAT, 'version' => self::VERSION, 'exported_at' => now()->toIso8601String(),
            'app_version' => config('antareja.version'),
            'sites' => $sites->map(fn ($s) => ['code' => $s->code, 'name' => $s->name, 'description' => $s->description ?? null,
                'lat' => $s->lat, 'lng' => $s->lng])->values(),
            'devices' => $devices->map(function ($d) use ($siteCode, $devKey, $withSecrets, &$secrets, $dec) {
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
                    'cfg_type' => $d->cfg_type, 'ssh_port' => $d->ssh_port, 'ssh_user' => $d->ssh_user, 'lat' => $d->lat, 'lng' => $d->lng, 'notes' => $d->notes];
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
                    'site' => $siteCode[$c->site_id] ?? null, 'enabled' => (bool) $c->enabled];
            })->values(),
            'report_schedules' => DB::table('report_schedules as r')->join('notify_channels as c', 'c.id', '=', 'r.channel_id')->orderBy('r.name')
                ->get(['r.*', 'c.name as ch_name', 'c.type as ch_type'])->map(fn ($r) => ['name' => $r->name, 'frequency' => $r->frequency,
                    'send_hour' => $r->send_hour, 'channel' => ['name' => $r->ch_name, 'type' => $r->ch_type], 'site' => $siteCode[$r->site_id] ?? null,
                    'enabled' => (bool) $r->enabled])->values(),
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
            throw new RuntimeException('Berkas dari versi ANTAREJA yang lebih baru — perbarui aplikasi dulu.');
        }
        foreach (['sites', 'devices', 'links', 'subnets', 'cameras', 'channels', 'report_schedules'] as $k) {
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
        $res = ['sites' => 0, 'devices' => 0, 'links' => 0, 'subnets' => 0, 'cameras' => 0, 'channels' => 0, 'report_schedules' => 0, 'settings' => 0, 'skipped' => 0];

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
                    'updated_at' => $now];
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
                    'site_id' => $siteId($c['site'] ?? null), 'enabled' => (bool) ($c['enabled'] ?? true) && $complete, 'updated_at' => $now];
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
