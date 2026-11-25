<?php

namespace Tests\Feature;

use App\Services\Collector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlowChartTest extends TestCase
{
    use DatabaseTransactions;

    public function test_grafik_riwayat_dan_live(): void
    {
        $v = $this->user('viewer');
        $r = DB::table('devices')->insertGetId(['name' => 'router-grafik', 'host' => '10.3.1.1']);
        DB::table('devices')->insert(['name' => 'kamera-pit', 'host' => '10.3.5.21']);
        $b = now()->subMinutes(12)->startOfMinute();
        $b = $b->subMinutes($b->minute % 5);
        DB::table('flow_stats')->insert([
            ['bucket' => $b, 'device_id' => $r, 'if_index' => 4, 'dir' => 'o', 'src' => '10.3.5.21', 'dst' => '10.9.0.5', 'proto' => 6, 'port' => 554, 'bytes' => 37_500_000, 'packets' => 1],
            ['bucket' => $b, 'device_id' => $r, 'if_index' => 4, 'dir' => 'o', 'src' => '10.3.7.7', 'dst' => '1.1.1.1', 'proto' => 6, 'port' => 443, 'bytes' => 3_750_000, 'packets' => 1],
            ['bucket' => $b, 'device_id' => $r, 'if_index' => 4, 'dir' => 'o', 'src' => null, 'dst' => null, 'proto' => 0, 'port' => 0, 'bytes' => 375_000, 'packets' => 1],
        ]);
        $j = $this->actingAs($v)->getJson("/flow/data?perangkat={$r}&port=4&arah=o&per=app&rentang=1h")->assertOk()->json();
        $labels = array_column($j['series'], 'label');
        $this->assertSame(['RTSP (CCTV) (TCP 554)', 'HTTPS (TCP 443)', 'Lainnya'], $labels);
        $i = array_search($b->timestamp, $j['t'], true);
        $this->assertNotFalse($i);
        $this->assertEqualsWithDelta(1_000_000, $j['series'][0]['values'][$i], 1); // 37,5 MB dalam 5 menit = 1 Mbps
        $src = $this->actingAs($v)->getJson("/flow/data?perangkat={$r}&port=4&arah=o&per=src&rentang=1h")->assertOk()->json();
        $this->assertSame('kamera-pit (10.3.5.21)', $src['series'][0]['label']);
        $this->actingAs($v)->getJson("/flow/data?perangkat={$r}&port=4&arah=x&per=app")->assertStatus(422);

        $this->app->instance(Collector::class, new class extends Collector {
            public function flowLive(int $deviceId, int $ifIndex, string $dir, string $by): array
            {
                return ['slot_s' => 10, 'points' => [['t' => 1700000000, 'total' => 9000, 'by' => ['6/554' => 8000, 'lainnya' => 1000]],
                    ['t' => 1700000010, 'total' => 0, 'by' => []]]];
            }
        });
        $l = $this->actingAs($v)->getJson("/flow/live?perangkat={$r}&port=4&arah=o&per=app")->assertOk()->json();
        $this->assertSame(['RTSP (CCTV) (TCP 554)', 'Lainnya'], array_column($l['series'], 'label'));
        $this->assertEquals([8000.0, 0.0], $l['series'][0]['values']);
        $this->actingAs($v)->get("/flow?perangkat={$r}&port=4&arah=o")->assertOk()->assertSee('data-flow-chart', false)->assertSee('active timeout');
    }
}
