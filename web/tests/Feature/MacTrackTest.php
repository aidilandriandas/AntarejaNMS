<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MacTrackTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cari_mac_port_dan_perangkat(): void
    {
        $sw = DB::table('devices')->insertGetId(['name' => 'sw-pit-a', 'host' => '10.95.0.1', 'category' => 'access']);
        $sw2 = DB::table('devices')->insertGetId(['name' => 'sw-pit-b', 'host' => '10.95.0.2', 'category' => 'access']);
        $router = DB::table('devices')->insertGetId(['name' => 'core', 'host' => '10.95.0.254', 'category' => 'core']);
        $cam = DB::table('devices')->insertGetId(['name' => 'kamera-haul', 'host' => '10.95.1.10', 'category' => 'cctv']);
        DB::table('interfaces')->insert([
            ['device_id' => $sw, 'if_index' => 7, 'name' => 'ether7', 'mac' => null],
            ['device_id' => $cam, 'if_index' => 1, 'name' => 'eth0', 'mac' => 'aa:bb:cc:00:00:01'],
        ]);
        DB::table('arp_entries')->insert(['reporter_id' => $router, 'if_index' => 1, 'ip' => '10.95.1.10', 'mac' => 'aa:bb:cc:00:00:01']);
        DB::table('mac_locations')->insert([
            ['mac' => 'aa:bb:cc:00:00:01', 'device_id' => $sw, 'if_index' => 7, 'vlan' => 30],
            ['mac' => 'aa:bb:cc:00:00:02', 'device_id' => $sw, 'if_index' => 7, 'vlan' => 30],
        ]);
        DB::table('mac_moves')->insert(['mac' => 'aa:bb:cc:00:00:01', 'from_device_id' => $sw2, 'from_if_index' => 3, 'to_device_id' => $sw, 'to_if_index' => 7]);

        $v = $this->user('viewer');
        $this->actingAs($v)->get('/ipam/cari?q=10.95.1.10')->assertOk()->assertSee('sw-pit-a, ether7')->assertSee('VLAN 30');
        $this->actingAs($v)->get('/ipam/cari?q=AA-BB-CC-00-00-01')->assertOk()->assertSee('Riwayat pindah port')->assertSee('sw-pit-b');
        $this->actingAs($v)->get('/ipam/cari?q=aabbcc000002')->assertOk()->assertSee('Hanya terlihat di switch');
        $this->actingAs($v)->get("/perangkat/{$sw}/port/7")->assertOk()->assertSee('kamera-haul')->assertSee('aa:bb:cc:00:00:02');
        $this->actingAs($v)->get("/perangkat/{$cam}")->assertOk()->assertSee('Terpasang di')->assertSee('sw-pit-a, ether7');
        $this->actingAs($v)->get('/ipam')->assertOk()->assertSee('MAC pindah port');
    }
}
