<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VlanTest extends TestCase
{
    use DatabaseTransactions;

    public function test_vlan_dan_stp(): void
    {
        $a = DB::table('devices')->insertGetId(['name' => 'sw-core-v', 'host' => '10.0.4.1', 'category' => 'core', 'stp_root' => '4096/00:11:22:33:44:55', 'stp_root_port' => 0, 'stp_top_changes' => 7, 'stp_last_change_at' => now()->subHour()]);
        $b = DB::table('devices')->insertGetId(['name' => 'sw-pit-v', 'host' => '10.0.4.2', 'category' => 'access', 'stp_root' => '4096/00:11:22:33:44:55', 'stp_root_port' => 3, 'stp_top_changes' => 12]);
        DB::table('interfaces')->insert(['device_id' => $b, 'if_index' => 3, 'name' => 'gi0/3', 'pvid' => 20, 'stp_state' => 5]);
        DB::table('device_vlans')->insert([
            ['device_id' => $a, 'vlan_id' => 20, 'name' => 'CCTV'], ['device_id' => $b, 'vlan_id' => 20, 'name' => 'KAMERA'],
            ['device_id' => $a, 'vlan_id' => 30, 'name' => 'FMS'],
        ]);
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/vlan')->assertOk()->assertSee('1 root bridge')->assertSee('switch ini root')->assertSee('gi0/3')
            ->assertSee('Nama beda antar switch')->assertSee('FMS');
        $this->actingAs($v)->get("/perangkat/{$b}/port/3")->assertOk()->assertSee('VLAN bawaan 20')->assertSee('STP forwarding, port ke root');
        $this->actingAs($v)->get("/perangkat/{$a}")->assertOk()->assertSee('2 VLAN')->assertSee('(switch ini)');
    }
}
