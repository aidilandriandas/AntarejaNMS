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
