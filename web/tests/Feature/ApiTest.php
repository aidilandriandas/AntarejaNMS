<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use DatabaseTransactions;

    private function token(array $form = []): string
    {
        $admin = $this->user('admin');
        $this->flushSession();
        $this->actingAs($admin)->post('/token-api', $form + ['name' => 'SOC', 'days' => 90])->assertSessionHas('api_token');
        $t = session('api_token');
        auth()->logout();
        $this->flushSession();

        return $t;
    }

    public function test_token_dan_endpoint(): void
    {
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-A', 'name' => 'Pit A']);
        $dev = DB::table('devices')->insertGetId(['name' => 'Core Pit A', 'host' => '10.1.0.1', 'site_id' => $site, 'category' => 'core', 'status' => 'down',
            'snmp_enabled' => true, 'snmp_community_enc' => Crypt::encryptString('komunitas-rahasia'), 'ssh_password_enc' => Crypt::encryptString('ssh-rahasia')]);
        DB::table('interfaces')->insert(['device_id' => $dev, 'if_index' => 1, 'name' => 'ether1', 'oper_up' => true]);
        $p1 = DB::table('problems')->insertGetId(['device_id' => $dev, 'kind' => 'down', 'severity' => 'high', 'title' => 'Core Pit A down']);
        DB::table('problems')->insert(['device_id' => $dev, 'kind' => 'latency', 'severity' => 'warning', 'title' => 'Latensi', 'resolved_at' => now()]);

        $t = $this->token();
        $this->assertSame(48, strlen($t));
        $this->assertSame(0, DB::table('api_tokens')->where('token_hash', $t)->count(), 'token tidak boleh disimpan polos');
        $h = ['Authorization' => "Bearer {$t}"];

        $this->getJson('/api/v1/devices')->assertStatus(401);
        $this->getJson('/api/v1/devices', ['Authorization' => 'Bearer ant_salah'])->assertStatus(401);
        $r = $this->getJson('/api/v1/devices?site=pit-a', $h)->assertOk()->assertJsonPath('data.0.name', 'Core Pit A')->assertJsonPath('data.0.site', 'PIT-A')
            ->assertJsonPath('data.0.status', 'down')->assertJsonPath('next_after_id', null);
        $this->assertStringNotContainsString('rahasia', $r->getContent());
        $this->assertStringNotContainsString('_enc', $r->getContent());
        $this->getJson("/api/v1/devices/{$dev}", $h)->assertOk()->assertJsonPath('data.ports.0.name', 'ether1')->assertJsonPath('data.open_problems.0.id', $p1);
        $this->getJson('/api/v1/devices/999999', $h)->assertNotFound();

        $this->getJson('/api/v1/problems', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Core Pit A down');
        $this->getJson('/api/v1/problems?status=all', $h)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/problems?status=all&min_severity=high', $h)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/problems?status=semua', $h)->assertStatus(422);
        $this->getJson('/api/v1/problems?since=kemarin-sore', $h)->assertStatus(422);

        // Kursor halaman
        $page = $this->getJson('/api/v1/problems?status=all&limit=1', $h)->assertJsonCount(1, 'data');
        $next = $page->json('next_after_id');
        $this->assertNotNull($next);
        $this->getJson("/api/v1/problems?status=all&limit=1&after_id={$next}", $h)->assertJsonCount(1, 'data')->assertJsonPath('next_after_id', null);

        foreach (['/api/v1', '/api/v1/sites', '/api/v1/incidents', '/api/v1/events', '/api/v1/anomalies', '/api/v1/services'] as $u) {
            $this->getJson($u, $h)->assertOk();
        }
        // Hanya baca
        $this->postJson('/api/v1/problems', [], $h)->assertStatus(405);
        $this->deleteJson("/api/v1/devices/{$dev}", [], $h)->assertStatus(405);
        $this->assertSame(1, DB::table('devices')->where('id', $dev)->count());

        $row = DB::table('api_tokens')->first();
        $this->assertGreaterThan(5, $row->use_count);
        $this->assertNotNull($row->last_used_at);

        // Cabut
        $admin = $this->user('admin');
        $this->actingAs($admin)->get('/token-api')->assertOk()->assertSee('SOC')->assertSee($row->prefix)->assertSee('kali')->assertDontSee($t);
        $this->actingAs($admin)->post("/token-api/{$row->id}/cabut")->assertSessionHas('ok');
        auth()->logout();
        $this->flushSession();
        $this->getJson('/api/v1/devices', $h)->assertStatus(401);
    }

    public function test_cek_sensor_dan_mac(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'srv-fms', 'host' => '10.2.0.5']);
        $sw = DB::table('devices')->insertGetId(['name' => 'sw-a', 'host' => '10.2.0.1', 'category' => 'access']);
        DB::table('service_checks')->insert([
            ['name' => 'Login', 'device_id' => $dev, 'type' => 'http', 'url' => 'https://nms:SandiRahasia9@fms.local/login', 'port' => null, 'status' => 'down', 'last_error' => 'kode HTTP 503'],
            ['name' => 'DB', 'device_id' => $dev, 'type' => 'tcp', 'url' => null, 'port' => 3306, 'status' => 'up', 'last_error' => null],
        ]);
        DB::table('device_sensors')->insert([
            ['device_id' => $dev, 'key' => 'mb:5', 'class' => 'voltage', 'grp' => 'daya', 'label' => 'Baterai', 'unit' => 'V', 'value' => 11.9, 'status' => 'warn'],
            ['device_id' => $dev, 'key' => 'cpu', 'class' => 'cpu', 'grp' => 'sistem', 'label' => 'CPU', 'unit' => '%', 'value' => 12, 'status' => 'ok'],
        ]);
        DB::table('interfaces')->insert(['device_id' => $sw, 'if_index' => 4, 'name' => 'ether4']);
        DB::table('mac_locations')->insert(['mac' => '00:11:22:33:44:55', 'device_id' => $sw, 'if_index' => 4, 'vlan' => 20]);
        DB::table('arp_entries')->insert(['reporter_id' => $sw, 'if_index' => 1, 'ip' => '10.2.0.77', 'mac' => '00:11:22:33:44:55']);
        $h = ['Authorization' => 'Bearer ' . $this->token()];

        $r = $this->getJson('/api/v1/checks?status=down', $h)->assertOk();
        $this->assertSame(['Login'], array_column($r->json('data'), 'name'));
        $this->assertSame('https://fms.local/login', $r->json('data.0.target'));
        $this->assertStringNotContainsString('SandiRahasia9', $r->getContent());
        $this->assertSame('10.2.0.5:3306', $this->getJson('/api/v1/checks?status=up', $h)->json('data.0.target'));

        $r = $this->getJson('/api/v1/sensors?source=modbus', $h)->assertOk();
        $this->assertSame([['modbus', 'warn', 11.9]], array_map(fn ($x) => [$x['source'], $x['status'], $x['value']], $r->json('data')));

        $this->assertSame('ether4', $this->getJson('/api/v1/macs?q=10.2.0.77', $h)->json('data.0.port'));
        $this->assertSame('10.2.0.77', $this->getJson('/api/v1/macs?q=0011.2233', $h)->json('data.0.ip'));
        $this->assertSame([], $this->getJson('/api/v1/macs?q=aa:bb:cc', $h)->json('data'));
    }

    public function test_batas_ip_dan_hanya_admin(): void
    {
        $t = $this->token(['allowed_ips' => '10.99.0.0/16']);
        $this->getJson('/api/v1/sites', ['Authorization' => "Bearer {$t}"])->assertStatus(401); // tes dari 127.0.0.1
        $t2 = $this->token(['name' => 'Lokal', 'allowed_ips' => '127.0.0.1']);
        $this->getJson('/api/v1/sites', ['Authorization' => "Bearer {$t2}"])->assertOk();

        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/token-api', ['name' => 'X', 'days' => 90, 'allowed_ips' => '10.0.0.0/40'])->assertSessionHasErrors('allowed_ips');
        $this->actingAs($admin)->post('/token-api', ['name' => 'X', 'days' => 90, 'allowed_ips' => 'server-soc'])->assertSessionHasErrors('allowed_ips');
        $this->flushSession();
        $this->actingAs($this->user('engineer'))->get('/token-api')->assertForbidden();
    }
}
