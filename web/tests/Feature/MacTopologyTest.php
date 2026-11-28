<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Topologi dari tabel MAC switch: berlaku untuk semua merek, termasuk perangkat yang hanya di-ping. */
class MacTopologyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_perangkat_tersambung_ke_port_switch_dari_tabel_mac(): void
    {
        $sw = DB::table('devices')->insertGetId(['name' => 'SW MESS UJI', 'host' => '10.17.70.1', 'category' => 'access', 'snmp_enabled' => true, 'status' => 'up']);
        $rt = DB::table('devices')->insertGetId(['name' => 'RT MESS UJI', 'host' => '10.17.70.254', 'category' => 'core', 'snmp_enabled' => true, 'status' => 'up']);
        $cam = DB::table('devices')->insertGetId(['name' => 'CCTV MESS UJI', 'host' => '10.17.70.21', 'category' => 'cctv', 'status' => 'up']); // ping saja
        $ap = DB::table('devices')->insertGetId(['name' => 'AP MERK LAIN', 'host' => '10.17.70.30', 'category' => 'wireless', 'snmp_enabled' => true, 'status' => 'up']);
        $old = DB::table('devices')->insertGetId(['name' => 'PC LAMA', 'host' => '10.17.70.40', 'category' => 'other', 'status' => 'up']);
        DB::table('interfaces')->insert([['device_id' => $sw, 'if_index' => 7, 'name' => 'ge-0/0/7', 'mac' => null], ['device_id' => $sw, 'if_index' => 9, 'name' => 'ge-0/0/9', 'mac' => null],
            ['device_id' => $ap, 'if_index' => 1, 'name' => 'eth0', 'mac' => 'aa:bb:cc:00:00:30']]);
        // kamera: MAC dari ARP router untuk IP-nya; AP: MAC dari interface-nya sendiri
        DB::table('arp_entries')->insert(['reporter_id' => $rt, 'if_index' => 1, 'ip' => '10.17.70.21', 'mac' => 'aa:bb:cc:00:00:21']);
        DB::table('arp_entries')->insert(['reporter_id' => $rt, 'if_index' => 1, 'ip' => '10.17.70.40', 'mac' => 'aa:bb:cc:00:00:40']);
        DB::table('mac_locations')->insert([
            ['mac' => 'aa:bb:cc:00:00:21', 'device_id' => $sw, 'if_index' => 7, 'last_seen' => now()],
            ['mac' => 'aa:bb:cc:00:00:30', 'device_id' => $sw, 'if_index' => 9, 'last_seen' => now()],
            ['mac' => 'aa:bb:cc:00:00:40', 'device_id' => $sw, 'if_index' => 9, 'last_seen' => now()->subDays(3)], // sudah lama tidak terlihat
        ]);

        $topo = $this->actingAs($this->user('viewer'))->getJson(route('topology.data'))->assertOk()->json();
        $mac = collect($topo['edges'])->filter(fn ($e) => in_array('mac', $e['sources'], true));
        $find = fn ($dev) => $mac->first(fn ($e) => in_array($dev, [$e['from'], $e['to']], true) && in_array($sw, [$e['from'], $e['to']], true));
        $this->assertNotNull($find($cam), 'kamera ping saja tersambung lewat ARP + tabel MAC');
        $this->assertNotNull($find($ap), 'AP merek apa pun tersambung lewat MAC interface-nya');
        $this->assertNull($find($old), 'lokasi lebih dari 24 jam diabaikan');
        $e = $find($cam);
        $this->assertSame('ge-0/0/7', $e['from'] === $sw ? $e['label_a'] : $e['label_b']);
    }
}
