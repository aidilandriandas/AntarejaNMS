<?php

namespace Tests\Feature;

use App\Models\IspLink;
use App\Services\IspSla;
use App\Services\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Link ISP berbasis port router: status port, trafik vs bandwidth kontrak, dua port ISP di satu router. */
class IspPortTest extends TestCase
{
    use DatabaseTransactions;

    public function test_port_down_dan_trafik(): void
    {
        $l = new IspLink(['name' => 'ISP A', 'if_index' => 1, 'bandwidth_mbps' => 100, 'target_pct' => 99, 'warn_pct' => 98, 'loss_down_pct' => 10,
            'latency_target_ms' => 100, 'jitter_target_ms' => 5, 'loss_target_pct' => 1]);
        $s = CarbonImmutable::parse('2026-11-16 00:00', config('app.timezone'))->getTimestamp();
        $series = ['up' => [], 'loss' => [], 'rtt' => [], 'jitter' => [], 'count' => [], 'port' => [], 'in' => [], 'out' => [], 'speed_mbps' => 1000.0];
        for ($i = 1; $i <= 288; $i++) { // 1 hari per 5 menit
            $t = $s + $i * 300;
            $series['port'][$t] = $i > 100 && $i <= 103 ? 0.0 : 1.0; // port down 15 menit
            $series['in'][$t] = $i === 200 ? 95.0 : 40.0;
            $series['out'][$t] = 10.0;
        }
        $r = IspSla::compute($l, $series, $s, $s + 288 * 300, 300);
        $this->assertSame(15 * 60, $r['downtime_s']);
        $this->assertCount(1, $r['incidents']);
        $this->assertSame(['Port ke ISP down', 'high'], [$r['incidents'][0]['cause'], $r['incidents'][0]['severity']]);
        $this->assertEqualsWithDelta(100 * (285 / 288), $r['availability'], 0.001);
        // trafik: p95 masuk 40 (satu puncak 95 tidak masuk p95), pemakaian dari bandwidth kontrak 100 Mbps
        $this->assertSame(['avg' => round((287 * 40 + 95) / 288, 2), 'max' => 95.0, 'p95' => 40.0, 'util_p95' => 40.0], $r['traffic']['in']);
        $this->assertSame([100.0, 'kontrak'], [$r['traffic']['capacity_mbps'], $r['traffic']['capacity_from']]);
        $this->assertCount(288, $r['detail']['in']);

        // tanpa bandwidth kontrak: pakai kecepatan port
        $l->bandwidth_mbps = null;
        $this->assertSame([1000.0, 'port'], array_values(array_intersect_key(IspSla::traffic($series, $l), ['capacity_mbps' => 1, 'capacity_from' => 1])));
    }

    public function test_atur_dua_port_isp_di_satu_router_dan_laporan(): void
    {
        $eng = $this->user('engineer');
        $rt = DB::table('devices')->insertGetId(['name' => 'R.AMM-PIK UJI', 'host' => '10.251.0.1', 'snmp_enabled' => true]);
        DB::table('interfaces')->insert([['device_id' => $rt, 'if_index' => 1, 'name' => 'ether1', 'alias' => 'ISP Telkom', 'oper_up' => true],
            ['device_id' => $rt, 'if_index' => 2, 'name' => 'ether2', 'alias' => 'ISP Lintas', 'oper_up' => true]]);
        $gw = DB::table('devices')->insertGetId(['name' => 'gw-telkom', 'host' => '10.251.9.1', 'ping_enabled' => true]);
        $base = ['provider' => 'Telkom', 'device_id' => $rt, 'target_pct' => 99, 'warn_pct' => 98, 'loss_down_pct' => 10, 'latency_target_ms' => 100,
            'jitter_target_ms' => 5, 'loss_target_pct' => 1];
        $this->actingAs($eng)->post('/laporan/sla-isp/atur', $base + ['name' => 'Telkom', 'if_index' => 1, 'ping_device_id' => $gw, 'bandwidth_mbps' => 100])->assertSessionHasNoErrors();
        $this->actingAs($eng)->post('/laporan/sla-isp/atur', $base + ['name' => 'Lintas', 'if_index' => 2])->assertSessionHasNoErrors();
        $this->actingAs($eng)->post('/laporan/sla-isp/atur', $base + ['name' => 'Dobel', 'if_index' => 2])->assertSessionHasErrors('if_index');
        $this->actingAs($eng)->post('/laporan/sla-isp/atur', $base + ['name' => 'Salah', 'if_index' => 9])->assertSessionHasErrors('if_index');
        $this->assertSame(2, IspLink::where('device_id', $rt)->count());

        $this->app->instance(Metrics::class, new class extends Metrics {
            public function matrix(string $query, int $start, int $end, int $step): array
            {
                $v = match (true) {
                    str_contains($query, 'if_oper_up') => 1.0,
                    str_contains($query, 'in_octets') => 50e6 / 8,
                    str_contains($query, 'out_octets') => 5e6 / 8,
                    str_contains($query, 'if_speed') => 1e9,
                    str_contains($query, 'ping_up') && str_starts_with($query, 'count') => 3.0,
                    str_contains($query, 'ping_up') => 1.0,
                    str_contains($query, 'loss') => 0.0,
                    str_contains($query, 'jitter') => 2.0,
                    default => 30.0,
                };
                $vals = [];
                for ($t = $start; $t <= $end; $t += $step) {
                    $vals[$t] = $v;
                }

                return [[[], $vals]];
            }
        });
        $viewer = $this->user('viewer');
        $this->flushSession();
        $this->actingAs($viewer)->get('/laporan/sla-isp?periode=minggu-lalu')->assertOk()->assertSee('R.AMM-PIK UJI, ether1 (ISP Telkom)')
            ->assertSee('50,0 / 5,0 Mbps')->assertSee('50% dari 100 Mbps');
        $telkom = IspLink::where('name', 'Telkom')->first();
        $this->actingAs($viewer)->get("/laporan/sla-isp/{$telkom->id}?periode=minggu-lalu")->assertOk()->assertSee('Trafik masuk (unduh)')
            ->assertSee('data-isp-plot="traffic"', false)->assertSee('30,00 ms')->assertSee('ping ke gw-telkom gagal');
        $lintas = IspLink::where('name', 'Lintas')->first();
        $this->actingAs($viewer)->get("/laporan/sla-isp/{$lintas->id}?periode=minggu-lalu")->assertOk()->assertSee('belum ada ping ke ujung ISP');
    }
}
