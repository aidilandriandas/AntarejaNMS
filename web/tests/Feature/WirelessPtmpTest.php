<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PTMP: satu AP dengan banyak station; station juga melaporkan AP-nya. Satu garis per station, tidak dobel. */
class WirelessPtmpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_satu_ap_tiga_station_jadi_tiga_garis(): void
    {
        $ap = DB::table('devices')->insertGetId(['name' => 'AP PTMP UJI', 'host' => '10.17.50.1', 'category' => 'wireless', 'status' => 'up']);
        $sta = [];
        foreach ([1, 2, 3] as $i) {
            $sta[$i] = DB::table('devices')->insertGetId(['name' => "STATION UJI {$i}", 'host' => "10.17.50.1{$i}", 'category' => 'ptp', 'status' => 'up']);
            // AP melihat station
            DB::table('wireless_links')->insert(['device_id' => $ap, 'mac' => "18:5a:d6:00:50:1{$i}", 'kind' => 'airmax', 'ip' => "10.17.50.1{$i}",
                'remote_device_id' => $sta[$i], 'signal_dbm' => -60 - $i * 5, 'connected' => true]);
            DB::table('neighbors')->insert(['device_id' => $ap, 'protocol' => 'wifi', 'remote_ip' => "10.17.50.1{$i}", 'remote_device_id' => $sta[$i]]);
            // station melihat AP (airOS mode station juga punya tabel station)
            DB::table('wireless_links')->insert(['device_id' => $sta[$i], 'mac' => '18:5a:d6:00:50:01', 'kind' => 'airmax', 'ip' => '10.17.50.1',
                'remote_device_id' => $ap, 'signal_dbm' => -61 - $i * 5, 'connected' => true]);
            DB::table('neighbors')->insert(['device_id' => $sta[$i], 'protocol' => 'wifi', 'remote_ip' => '10.17.50.1', 'remote_device_id' => $ap]);
        }
        $viewer = $this->user('viewer');
        $topo = $this->actingAs($viewer)->getJson(route('topology.data'))->assertOk()->json();
        $radio = collect($topo['edges'])->filter(fn ($e) => in_array('wifi', $e['sources'], true) && in_array($ap, [$e['from'], $e['to']], true));
        $this->assertCount(3, $radio, 'satu garis per station, dua laporan arah digabung');
        // garis memakai sinyal terlemah dari kedua arah
        $e3 = $radio->first(fn ($e) => in_array($sta[3], [$e['from'], $e['to']], true));
        $this->assertSame(-76.0, (float) $e3['radio']['signal']);

        $page = $this->actingAs($viewer)->get('/link-radio?q=UJI')->assertOk();
        $page->assertSee('STATION UJI 1')->assertSee('STATION UJI 3');
        $this->actingAs($viewer)->get("/perangkat/{$ap}")->assertOk()->assertSee('3 tersambung');
        $this->actingAs($viewer)->get("/perangkat/{$sta[2]}")->assertOk()->assertSee('Tersambung lewat radio ke')->assertSee('AP PTMP UJI');
    }
}
