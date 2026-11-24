<?php

namespace Tests\Feature;

use App\Http\Controllers\VersionController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VersionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_versi_per_model_dan_jam_meleset(): void
    {
        $v = $this->user('viewer');
        $mk = fn ($n, $ver, $model = 'RB4011iGS+') => DB::table('devices')->insertGetId(['name' => $n, 'host' => '10.8.0.' . random_int(2, 250),
            'category' => 'distribution', 'os_version' => $ver, 'hw_model' => $model, 'serial_no' => 'SN-' . $n, 'snmp_ok' => true, 'snmp_enabled' => true]);
        $a = $mk('rt-pit-a', '7.15.3');
        $mk('rt-pit-b', '7.15.3');
        $c = $mk('rt-pit-c', '6.49.10');
        $mk('sw-kantor', '15.2(4)E7', 'WS-C2960X-24TS-L');
        DB::table('device_sensors')->insert([
            ['device_id' => $a, 'key' => 'clock', 'class' => 'clock_skew', 'grp' => 'sistem', 'label' => 'Selisih jam', 'unit' => 'detik', 'value' => -400, 'status' => 'crit', 'updated_at' => now()],
            ['device_id' => $c, 'key' => 'clock', 'class' => 'clock_skew', 'grp' => 'sistem', 'label' => 'Selisih jam', 'unit' => 'detik', 'value' => 3, 'status' => 'ok', 'updated_at' => now()],
        ]);
        $r = $this->actingAs($v)->get('/versi')->assertOk();
        $r->assertSee('RB4011iGS+')->assertSee('6.49.10')->assertSee('WS-C2960X-24TS-L')->assertSee('seragam')->assertSee('lambat 6 menit 40 detik');
        $this->actingAs($v)->get("/perangkat/{$a}")->assertOk()->assertSee('7.15.3')->assertSee('SN SN-rt-pit-a');
        $this->assertSame('cepat 1 jam 2 menit', VersionController::skew(3725));
        $this->assertSame('lambat 59 detik', VersionController::skew(-59));
    }
}
