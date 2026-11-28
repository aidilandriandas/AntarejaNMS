<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WirelessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_link_radio_halaman_perangkat_dan_topologi(): void
    {
        $ap = DB::table('devices')->insertGetId(['name' => 'AP RADIO PIT-A', 'host' => '10.17.36.10', 'category' => 'wireless', 'status' => 'up']);
        $cpe = DB::table('devices')->insertGetId(['name' => 'CPE RADIO HAULING', 'host' => '10.17.36.120', 'category' => 'ptp', 'status' => 'up']);
        DB::table('wireless_links')->insert([
            ['device_id' => $ap, 'mac' => '18:5a:d6:00:00:01', 'kind' => 'airmax', 'name' => 'CPE HAULING', 'ip' => '10.17.36.120', 'remote_device_id' => $cpe,
                'signal_dbm' => -74, 'ccq_pct' => 91, 'capacity_pct' => 70, 'distance_m' => 2300, 'tx_mbps' => 130, 'rx_mbps' => 144, 'uptime_s' => 93600, 'connected' => true],
            ['device_id' => $ap, 'mac' => '18:5a:d6:00:00:09', 'kind' => 'airmax', 'name' => 'laptop tamu', 'ip' => null, 'remote_device_id' => null,
                'signal_dbm' => -60, 'ccq_pct' => null, 'capacity_pct' => null, 'distance_m' => null, 'tx_mbps' => null, 'rx_mbps' => null, 'uptime_s' => null, 'connected' => false],
        ]);
        DB::table('neighbors')->insert(['device_id' => $ap, 'local_port' => 'radio', 'protocol' => 'wifi', 'remote_name' => 'CPE HAULING', 'remote_chassis' => '18:5a:d6:00:00:01',
            'remote_ip' => '10.17.36.120', 'remote_device_id' => $cpe]);
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)->get('/link-radio')->assertOk()->assertSee('CPE RADIO HAULING')->assertSee('-74 dBm')->assertSee('terputus')->assertSee('2,3 km');
        $this->actingAs($viewer)->get('/link-radio?kondisi=lemah')->assertOk()->assertSee('CPE RADIO HAULING')->assertDontSee('laptop tamu');
        $this->actingAs($viewer)->get('/link-radio?kondisi=putus')->assertOk()->assertSee('laptop tamu')->assertDontSee('CPE RADIO HAULING</a>', false);
        $this->actingAs($viewer)->get("/perangkat/{$ap}")->assertOk()->assertSee('Link radio')->assertSee('1 tersambung, 1 terputus')->assertSee('1 hari 2 jam');
        $this->actingAs($viewer)->get("/perangkat/{$cpe}")->assertOk()->assertSee('Tersambung lewat radio ke')->assertSee('AP RADIO PIT-A');

        $topo = $this->actingAs($viewer)->getJson(route('topology.data'))->assertOk()->json();
        $edge = collect($topo['edges'])->first(fn ($e) => in_array('wifi', $e['sources'], true));
        $this->assertNotNull($edge, 'garis topologi dari link radio');
        $this->assertSame([-74.0, 'airMAX', 2.3], [(float) $edge['radio']['signal'], $edge['radio']['kind'], $edge['radio']['distance_km']]);
    }
}
