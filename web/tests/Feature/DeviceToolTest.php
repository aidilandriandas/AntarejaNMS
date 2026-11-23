<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceToolTest extends TestCase
{
    use DatabaseTransactions;

    public function test_alat_diagnosa(): void
    {
        config(['antareja.collector_url' => 'http://collector.test:8081']);
        $cam = DB::table('devices')->insertGetId(['name' => 'Kamera Pit A', 'host' => '10.7.0.5', 'category' => 'camera']);
        Http::fake(["collector.test:8081/v1/devices/{$cam}/tools" => Http::response(['target' => '10.7.0.5', 'port' => 554, 'state' => 'open', 'ms' => 3.2])]);
        $eng = $this->user('engineer');
        $this->flushSession();
        $this->actingAs($eng)->get("/perangkat/{$cam}")->assertOk()->assertSee('Alat diagnosa')->assertSee('value="554"', false);
        $this->actingAs($eng)->postJson("/perangkat/{$cam}/alat", ['tool' => 'port', 'port' => 554])->assertOk()
            ->assertJsonPath('tool', 'port')->assertJsonPath('result.state', 'open');
        Http::assertSent(fn ($r) => $r['tool'] === 'port' && $r['port'] === 554 && str_ends_with($r->url(), "/v1/devices/{$cam}/tools"));
        $this->assertSame(1, DB::table('audit_logs')->where('description', 'like', 'Cek port TCP 554 ke Kamera Pit A%')->count());

        $this->actingAs($eng)->postJson("/perangkat/{$cam}/alat", ['tool' => 'nmap'])->assertStatus(422);
        $this->actingAs($eng)->postJson("/perangkat/{$cam}/alat", ['tool' => 'port'])->assertStatus(422);
        $this->actingAs($eng)->postJson("/perangkat/{$cam}/alat", ['tool' => 'port', 'port' => 70000])->assertStatus(422);

        $v = $this->user('viewer');
        $this->flushSession();
        $this->actingAs($v)->get("/perangkat/{$cam}")->assertDontSee('Alat diagnosa');
        $this->actingAs($v)->postJson("/perangkat/{$cam}/alat", ['tool' => 'ping'])->assertForbidden();
    }
}
