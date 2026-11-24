<?php

namespace Tests\Feature;

use App\Http\Controllers\FlowController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_halaman_flow(): void
    {
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/flow')->assertOk()->assertSee('2055/UDP');
        $r = DB::table('devices')->insertGetId(['name' => 'router-pit', 'host' => '10.3.0.1', 'category' => 'core']);
        $cam = DB::table('devices')->insertGetId(['name' => 'kamera-gerbang', 'host' => '10.3.5.20', 'category' => 'cctv']);
        DB::table('interfaces')->insert(['device_id' => $r, 'if_index' => 4, 'name' => 'sfp1-vsat']);
        $b = now()->subMinutes(10)->startOfMinute();
        DB::table('flow_stats')->insert([
            ['bucket' => $b, 'device_id' => $r, 'if_index' => 4, 'dir' => 'o', 'src' => '10.3.5.20', 'dst' => '10.9.0.5', 'proto' => 6, 'port' => 554, 'bytes' => 900_000_000, 'packets' => 10],
            ['bucket' => $b, 'device_id' => $r, 'if_index' => 4, 'dir' => 'o', 'src' => '10.3.7.7', 'dst' => '142.250.4.1', 'proto' => 6, 'port' => 443, 'bytes' => 100_000_000, 'packets' => 10],
            ['bucket' => $b, 'device_id' => $r, 'if_index' => 4, 'dir' => 'o', 'src' => null, 'dst' => null, 'proto' => 0, 'port' => 0, 'bytes' => 50_000_000, 'packets' => 1],
            ['bucket' => now()->subDays(2), 'device_id' => $r, 'if_index' => 4, 'dir' => 'o', 'src' => '10.3.9.9', 'dst' => '1.1.1.1', 'proto' => 17, 'port' => 53, 'bytes' => 5, 'packets' => 1],
        ]);
        $res = $this->actingAs($v)->get("/flow?perangkat={$r}&port=4&arah=o&rentang=1h")->assertOk();
        $res->assertSee('kamera-gerbang')->assertSee('RTSP (CCTV) (TCP 554)')->assertSee('1,1 GB')->assertSee('86%')->assertDontSee('10.3.9.9');
        $this->actingAs($v)->get("/perangkat/{$r}/port/4")->assertOk()->assertSee('Pemakai terbesar')->assertSee('10.3.5.20');
        $this->assertSame('TCP port acak', FlowController::app(6, 0));
        $this->assertSame('UDP 40000', FlowController::app(17, 40000));
    }
}
