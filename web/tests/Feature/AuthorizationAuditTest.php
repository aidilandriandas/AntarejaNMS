<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Audit hak akses seluruh rute (bukan contoh satu-satu):
 * - setiap rute yang mengubah data wajib bergerbang manage-devices atau admin, kecuali daftar kecil yang memang untuk semua pengguna;
 * - setiap rute bergerbang benar-benar menolak viewer (403), dan rute admin menolak engineer (403), dengan data nyata
 *   supaya penolakan bukan karena 404 binding.
 */
class AuthorizationAuditTest extends TestCase
{
    use DatabaseTransactions;

    /** Rute pengubah yang sah untuk semua pengguna login (atau tamu): akun sendiri, tampilan sendiri, baca trafik live. */
    private const OPEN_WRITE = ['login', 'login/verifikasi', 'profil/2fa', 'profil/2fa/mulai', 'profil/2fa/aktifkan', 'profil/2fa/cadangan', 'logout', 'setup', 'profil/password', 'profil/kontak', 'ringkasan/susunan',
        'perangkat/{device}/live', 'topologi/live', 'cctv/{camera}/live'];

    private function gated(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            $mw = $r->gatherMiddleware();
            $gate = collect($mw)->first(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
            $out[] = [$r, $gate];
        }

        return $out;
    }

    public function test_semua_rute_pengubah_bergerbang(): void
    {
        $bad = [];
        foreach ($this->gated() as [$r, $gate]) {
            $methods = array_diff($r->methods(), ['GET', 'HEAD']);
            if (! $methods || str_starts_with($r->uri(), 'api/') || in_array($r->uri(), self::OPEN_WRITE, true)) {
                continue;
            }
            if (! in_array($gate, ['can:manage-devices', 'can:admin', 'can:ack-problems'], true)) {
                $bad[] = implode('|', $methods) . ' ' . $r->uri();
            }
        }
        $this->assertSame([], $bad, 'Rute pengubah tanpa gerbang peran');
    }

    public function test_gerbang_benar_benar_menolak(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $site = DB::table('sites')->insertGetId(['code' => 'QA-1', 'name' => 'QA']);
        $dev = DB::table('devices')->insertGetId(['name' => 'QA dev', 'host' => '10.99.0.1', 'site_id' => $site, 'snmp_enabled' => true]);
        DB::table('interfaces')->insert(['device_id' => $dev, 'if_index' => 1, 'name' => 'eth0', 'oper_up' => true]);
        $other = $this->user('engineer');
        $ch = DB::table('notify_channels')->insertGetId(['name' => 'QA', 'type' => 'webhook', 'config_enc' => Crypt::encryptString('{"url":"https://x"}')]);
        $svc = DB::table('services')->insertGetId(['name' => 'QA svc']);
        $ids = [
            'device' => $dev, 'ifIndex' => 1, 'site' => $site, 'user' => $other->id, 'channel' => $ch, 'service' => $svc,
            'component' => DB::table('service_components')->insertGetId(['service_id' => $svc, 'name' => 'K']),
            'trigger' => DB::table('triggers')->insertGetId(['name' => 'QA', 'severity' => 'warning', 'metric' => 'latency', 'op' => '>', 'threshold' => 1]),
            'maintenance' => DB::table('maintenances')->insertGetId(['title' => 'QA', 'scope' => 'all', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]),
            'incident' => DB::table('incidents')->insertGetId(['title' => 'QA', 'severity' => 'high']),
            'problem' => DB::table('problems')->insertGetId(['device_id' => $dev, 'kind' => 'down', 'severity' => 'high', 'title' => 'QA']),
            'anomaly' => DB::table('anomalies')->insertGetId(['device_id' => $dev, 'metric' => 'latency', 'direction' => 'up', 'value' => 9, 'baseline' => 1, 'spread' => 1, 'score' => 8]),
            'slot' => DB::table('oncall_slots')->insertGetId(['user_id' => $other->id, 'starts_at' => now(), 'ends_at' => now()->addHour()]),
            'token' => DB::table('api_tokens')->insertGetId(['name' => 'QA', 'token_hash' => str_repeat('a', 64), 'prefix' => 'ant_qa']),
            'collector' => DB::table('site_collectors')->insertGetId(['name' => 'QA collector', 'token_hash' => str_repeat('c', 64), 'token_hint' => 'acs_qa']),
            'check' => DB::table('service_checks')->insertGetId(['name' => 'QA cek', 'device_id' => $dev, 'type' => 'tcp', 'port' => 22]),
            'runbook' => DB::table('runbooks')->insertGetId(['title' => 'QA runbook', 'body' => 'langkah']),
            'point' => DB::table('modbus_points')->insertGetId(['device_id' => $dev, 'label' => 'QA', 'class' => 'other', 'address' => 1]),
            'link' => DB::table('device_links')->insertGetId(['a_device_id' => $dev, 'b_device_id' => DB::table('devices')->insertGetId(['name' => 'QA b', 'host' => '10.99.0.2'])]),
            'subnet' => DB::table('subnets')->insertGetId(['cidr' => '10.99.0.0/24']),
            'camera' => DB::table('cameras')->insertGetId(['name' => 'QA cam', 'host' => '10.99.0.3']),
            'schedule' => DB::table('report_schedules')->insertGetId(['name' => 'QA', 'frequency' => 'daily', 'channel_id' => $ch]),
            'scan' => DB::table('discovery_scans')->insertGetId(['cidr' => '10.99.1.0/24']),
            'plan' => DB::table('map_plans')->insertGetId(['name' => 'QA', 'mime' => 'image/png', 'width' => 1, 'height' => 1, 'sha256' => str_repeat('b', 64), 'data' => DB::raw("'\\x89504e47'::bytea")]),
            'backup' => DB::table('backups')->insertGetId(['kind' => 'manual']),
            'version' => DB::table('device_configs')->insertGetId(['device_id' => $dev, 'sha256' => str_repeat('c', 64), 'lines' => 1, 'size_bytes' => 1, 'content_enc' => Crypt::encryptString('x')]),
        ];
        $viewer = $this->user('viewer');
        $eng = $this->user('engineer');
        $fail = [];
        $checked = 0;
        foreach ($this->gated() as [$r, $gate]) {
            if (! in_array($gate, ['can:manage-devices', 'can:admin', 'can:ack-problems'], true)) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($ids, &$fail, $r) {
                if (! isset($ids[$m[1]])) {
                    $fail[] = "tanpa data contoh untuk {{$m[1]}}: " . $r->uri();
                }

                return $ids[$m[1]] ?? 1;
            }, $r->uri());
            $method = collect($r->methods())->first(fn ($m) => $m !== 'HEAD');
            $who = $gate === 'can:admin' ? [$viewer, $eng] : [$viewer];
            foreach ($who as $u) {
                $this->flushSession();
                $status = $this->actingAs($u)->call($method, '/' . ltrim($uri, '/'))->getStatusCode();
                $checked++;
                if ($status !== 403) {
                    $fail[] = "{$u->role} mendapat {$status} di {$method} /{$uri} ({$gate})";
                }
            }
        }
        $this->assertGreaterThan(80, $checked);
        $this->assertSame([], array_values(array_unique($fail)));
    }
}
