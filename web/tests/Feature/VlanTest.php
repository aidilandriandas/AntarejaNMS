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

    public function test_vlan_dari_interface_router(): void
    {
        $rt = DB::table('devices')->insertGetId(['name' => 'rt-mikrotik-v', 'host' => '10.0.5.1', 'category' => 'core']);
        DB::table('interfaces')->insert([
            ['device_id' => $rt, 'if_index' => 10, 'name' => 'vlan20-cctv', 'if_type' => 135, 'oper_up' => true, 'alias' => null],
            ['device_id' => $rt, 'if_index' => 11, 'name' => 'ether2.30', 'if_type' => 6, 'oper_up' => false, 'alias' => null],
            ['device_id' => $rt, 'if_index' => 12, 'name' => 'vl-kantor', 'if_type' => 135, 'oper_up' => true, 'alias' => 'jaringan kantor'],
            ['device_id' => $rt, 'if_index' => 13, 'name' => 'ether1', 'if_type' => 6, 'oper_up' => true, 'alias' => null],
        ]);
        $sw = DB::table('devices')->insertGetId(['name' => 'sw-tanpa-vlan', 'host' => '10.0.5.2', 'category' => 'access', 'snmp_enabled' => true, 'snmp_ok' => true]);
        $r = $this->actingAs($this->user('viewer'))->get('/vlan')->assertOk();
        $r->assertSeeInOrder(['20', 'rt-mikrotik-v vlan20-cctv', '30', 'rt-mikrotik-v ether2.30'])
            ->assertSee('Interface VLAN tanpa nomor di nama')->assertSee('vl-kantor')->assertDontSee('ether1<')
            ->assertSee('Belum ada VLAN terbaca')->assertSee('sw-tanpa-vlan');
        $this->assertSame(20, \App\Http\Controllers\VlanController::idFromName('Vlan20'));
        $this->assertSame(110, \App\Http\Controllers\VlanController::idFromName('GigabitEthernet0/0.110'));
        $this->assertNull(\App\Http\Controllers\VlanController::idFromName('vlan5000'));
        $this->assertNull(\App\Http\Controllers\VlanController::idFromName('bridge1'));
    }
}
