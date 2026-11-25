<?php

namespace Tests\Feature;

use App\Models\IspLink;
use App\Services\IspSla;
use App\Services\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IspSlaTest extends TestCase
{
    use DatabaseTransactions;

    /** Seri 60 detik selama 2 hari: down 14 menit, loss 30% 6 menit, maintenance 30 menit berisi down, 10 menit tanpa data. */
    private function series(int $s): array
    {
        $out = ['up' => [], 'loss' => [], 'rtt' => [], 'jitter' => [], 'count' => []];
        for ($i = 1; $i <= 2880; $i++) {
            $t = $s + $i * 60;
            if ($i > 2000 && $i <= 2010) {
                continue; // tanpa data
            }
            $down = $i > 100 && $i <= 114 || $i > 1500 && $i <= 1530;
            $out['up'][$t] = $down ? 0.0 : 1.0;
            $out['loss'][$t] = $down ? 100.0 : ($i > 700 && $i <= 706 ? 30.0 : 0.5);
            $out['rtt'][$t] = 40.0 + ($i % 2) * 20;
            $out['jitter'][$t] = 2.0;
            $out['count'][$t] = 3.0;
        }

        return $out;
    }

    public function test_hitung_sla_insiden_dan_maintenance(): void
    {
        $l = new IspLink(['name' => 'Uji', 'target_pct' => 99, 'warn_pct' => 98, 'loss_down_pct' => 10, 'latency_target_ms' => 100, 'jitter_target_ms' => 5, 'loss_target_pct' => 1]);
        $s = CarbonImmutable::parse('2026-11-16 00:00', config('app.timezone'))->getTimestamp();
        $maint = [[$s + 1500 * 60, $s + 1530 * 60]];
        $r = IspSla::compute($l, $this->series($s), $s, $s + 2880 * 60, 60, $maint);
        // gangguan 14 + 6 menit; maintenance 30 menit dan tanpa data 10 menit tidak dihitung
        $this->assertSame(20 * 60, $r['downtime_s']);
        $this->assertSame(30 * 60, $r['maint_s']);
        $this->assertSame(10 * 60, $r['nodata_s']);
        $this->assertEqualsWithDelta(100 * (1 - 20 / (2880 - 40)), $r['availability'], 0.001);
        $this->assertSame('compliant', $r['verdict']);
        $this->assertCount(2, $r['incidents']);
        $this->assertSame([14 * 60, 'high'], [$r['incidents'][0]['duration_s'], $r['incidents'][0]['severity']]);
        $this->assertStringContainsString('Packet loss tinggi (puncak 30%', $r['incidents'][1]['cause']);
        $this->assertCount(2, $r['days']);
        $this->assertEqualsWithDelta(50, $r['latency'], 0.01);
        $this->assertSame(3 * (2880 - 10), $r['samples']);
        $this->assertLessThanOrEqual(IspSla::MAX_DETAIL_POINTS, count($r['detail']['t']));
        $this->assertSame('warning', $l->verdict(98.5));
        $this->assertSame('breach', $l->verdict(97.9));
    }

    public function test_halaman_dan_atur_link(): void
    {
        $eng = $this->user('engineer');
        $dev = DB::table('devices')->insertGetId(['name' => 'gw-metro-e', 'host' => '10.250.0.1', 'ping_enabled' => true]);
        $this->actingAs($eng)->get('/laporan/sla-isp')->assertOk()->assertSee('Belum ada link ISP');
        $this->actingAs($eng)->post('/laporan/sla-isp/atur', ['name' => 'Metro E PLJ', 'provider' => 'PLJ', 'device_id' => $dev, 'target_pct' => 98, 'warn_pct' => 99,
            'loss_down_pct' => 10, 'latency_target_ms' => 100, 'jitter_target_ms' => 5, 'loss_target_pct' => 1])->assertSessionHasErrors('warn_pct');
        $this->actingAs($eng)->post('/laporan/sla-isp/atur', ['name' => 'Metro E PLJ', 'provider' => 'PLJ', 'device_id' => $dev, 'target_pct' => 98, 'warn_pct' => 97,
            'loss_down_pct' => 10, 'latency_target_ms' => 100, 'jitter_target_ms' => 5, 'loss_target_pct' => 1, 'exclude_maintenance' => 1])->assertSessionHasNoErrors();
        $link = IspLink::where('name', 'Metro E PLJ')->firstOrFail();

        // VictoriaMetrics tiruan: up selalu 1, loss 0, rtt 50, jitter 3
        $this->app->instance(Metrics::class, new class extends Metrics {
            public function matrix(string $query, int $start, int $end, int $step): array
            {
                $v = match (true) {
                    str_contains($query, 'ping_up') && str_starts_with($query, 'count') => 3.0,
                    str_contains($query, 'ping_up') => 1.0,
                    str_contains($query, 'loss') => 0.0,
                    str_contains($query, 'jitter') => 3.0,
                    default => 50.0,
                };
                $vals = [];
                for ($t = $start; $t <= $end; $t += $step) {
                    $vals[$t] = $v;
                }

                return [[[], $vals]];
            }
        });
        $this->flushSession();
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get('/laporan/sla-isp?periode=minggu-lalu')->assertOk()->assertSee('Metro E PLJ')->assertSee('100,00%')->assertSee('Memenuhi SLA');
        $this->actingAs($viewer)->get("/laporan/sla-isp/{$link->id}?periode=minggu-lalu")->assertOk()->assertSee('Tren harian')
            ->assertSee('data-isp-report', false)->assertSee('Tidak ada gangguan')->assertSee('50,00 ms');
        $this->actingAs($viewer)->get("/laporan/sla-isp/{$link->id}?periode=kustom&dari=2026-01-01&sampai=2026-06-01")->assertSessionHasErrors('sampai');
        $csv = $this->actingAs($viewer)->get("/laporan/sla-isp/{$link->id}.csv?periode=minggu-lalu")->assertOk()->streamedContent();
        $this->assertStringContainsString('"Ketersediaan (%)";100,000', $csv);
        $this->actingAs($viewer)->get('/laporan/sla-isp/atur')->assertForbidden();
    }
}
