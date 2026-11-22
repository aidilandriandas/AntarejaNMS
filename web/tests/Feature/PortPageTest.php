<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_halaman_dan_data_port(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'Core P', 'host' => '10.3.0.1', 'category' => 'core', 'snmp_enabled' => true]);
        $other = DB::table('devices')->insertGetId(['name' => 'Radio Hauling', 'host' => '10.3.0.2', 'category' => 'ptp']);
        DB::table('interfaces')->insert(['device_id' => $dev, 'if_index' => 7, 'name' => 'sfp-sfpplus1', 'alias' => 'ke Pit B', 'speed_bps' => 1e9, 'oper_up' => true, 'admin_up' => true]);
        DB::table('neighbors')->insert(['device_id' => $dev, 'local_if_index' => 7, 'protocol' => 'lldp', 'remote_name' => 'radio', 'remote_device_id' => $other, 'remote_port' => 'eth0']);
        DB::table('device_ips')->insert(['device_id' => $dev, 'ip' => '10.30.0.1', 'if_index' => 7, 'prefix' => 30]);
        $t = time() - time() % 60;
        Http::fake([
            '*query_range*' => Http::response(['status' => 'success', 'data' => ['result' => [['metric' => ['if_index' => '7'], 'values' => [[$t - 120, '1000'], [$t - 60, '3000']]]]]]),
            '*/api/v1/query?*' => Http::response(['status' => 'success', 'data' => ['result' => [['metric' => [], 'value' => [$t, '123456789']]]]]),
        ]);
        $v = $this->user('viewer');
        $this->actingAs($v)->get("/perangkat/{$dev}")->assertOk()->assertSee("/perangkat/{$dev}/port/7", false)->assertSee('class="pspark"', false);
        $this->actingAs($v)->get("/perangkat/{$dev}/port/7")->assertOk()->assertSee('sfp-sfpplus1')->assertSee('ke Pit B')
            ->assertSee('Radio Hauling')->assertSee('10.30.0.1/30')->assertSee('Persentil 95')->assertDontSee('Pantau port');
        $j = $this->actingAs($v)->getJson("/perangkat/{$dev}/port/7/data?range=7d")->assertOk()->json();
        $this->assertSame(1_000_000_000, $j['speed']);
        $this->assertArrayHasKey('in_ucast', $j['series']);
        $this->assertEquals(123456789, $j['stats']['out']['p95']);
        $this->actingAs($v)->get("/perangkat/{$dev}/port/99")->assertNotFound();
    }
}
