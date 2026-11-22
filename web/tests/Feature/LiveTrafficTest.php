<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveTrafficTest extends TestCase
{
    use DatabaseTransactions;

    public function test_trafik_live_port(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'Core Live', 'host' => '10.4.0.1', 'category' => 'core', 'snmp_enabled' => true]);
        DB::table('interfaces')->insert(['device_id' => $dev, 'if_index' => 5, 'name' => 'sfp1', 'speed_bps' => 1e9, 'oper_up' => true]);
        Http::fake(['*/v1/live' => function ($req) use ($dev) {
            $this->assertSame([['device_id' => $dev, 'if_index' => 5]], $req['ports']);

            return Http::response(['samples' => [['device_id' => $dev, 'if_index' => 5, 'in' => 123456789012, 'out' => 42, 'at' => 1780000000.5]]]);
        }]);
        $v = $this->user('viewer');
        $this->actingAs($v)->get("/perangkat/{$dev}")->assertOk()->assertSee('data-live-port="5"', false)->assertSee('Live semua port');
        $this->actingAs($v)->postJson("/perangkat/{$dev}/live", ['ports' => [5, 5]])->assertOk()
            ->assertJson(['samples' => [['if' => 5, 'in' => 123456789012, 'out' => 42, 'at' => 1780000000.5]], 'error' => null]);
        $this->actingAs($v)->postJson("/perangkat/{$dev}/live", ['ports' => range(1, 61)])->assertStatus(422);
        $this->actingAs($v)->postJson("/perangkat/{$dev}/live", ['ports' => ['x']])->assertStatus(422);

        DB::table('devices')->where('id', $dev)->update(['snmp_enabled' => false]);
        $this->actingAs($v)->postJson("/perangkat/{$dev}/live", ['ports' => [5]])->assertOk()->assertJson(['samples' => [], 'error' => 'SNMP perangkat ini dimatikan.']);
    }
}
