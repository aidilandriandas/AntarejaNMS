<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DowntimeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_matriks_down_bulanan_csv_dan_api(): void
    {
        DB::table('downtime_monthly')->delete();
        $a = DB::table('devices')->insertGetId(['name' => 'Radio Pit A', 'host' => '10.8.0.1', 'category' => 'ptp']);
        $b = DB::table('devices')->insertGetId(['name' => 'Switch Kantor', 'host' => '10.8.0.2', 'category' => 'access']);
        $m1 = now()->startOfMonth()->subMonth()->toDateString();
        $m0 = now()->startOfMonth()->toDateString();
        $month = 30 * 86400;
        DB::table('downtime_monthly')->insert([
            ['month' => $m1, 'device_id' => $a, 'device_name' => 'Radio Pit A', 'host' => '10.8.0.1', 'site' => 'PIT-A', 'monitored_s' => $month, 'down_s' => 0.025 * $month, 'outages' => 3, 'final' => true],
            ['month' => $m0, 'device_id' => $a, 'device_name' => 'Radio Pit A', 'host' => '10.8.0.1', 'site' => 'PIT-A', 'monitored_s' => $month, 'down_s' => 0.001 * $month, 'outages' => 1, 'final' => false],
            ['month' => $m1, 'device_id' => $b, 'device_name' => 'Switch Kantor', 'host' => '10.8.0.2', 'site' => 'KANTOR', 'monitored_s' => $month, 'down_s' => 0, 'outages' => 0, 'final' => true],
            ['month' => $m1, 'device_id' => null, 'device_name' => 'Kamera Lama', 'host' => '10.8.0.9', 'site' => 'PIT-A', 'monitored_s' => $month, 'down_s' => 0.5 * $month, 'outages' => 9, 'final' => true],
        ]);
        DB::table('settings')->updateOrInsert(['key' => 'sla.target_pct'], ['value' => '99.5']);
        $v = $this->user('viewer');
        $this->flushSession();
        $r = $this->actingAs($v)->get('/laporan/down-bulanan?bulan=3')->assertOk();
        $r->assertSee('Down bulanan')->assertSee('Radio Pit A')->assertSee('Kamera Lama')->assertSee('(dihapus)')
            ->assertSee('<td class="num dt-crit" title="down 15 hari, 9 gangguan">50</td>', false)   // 50% > batas 0,5%
            ->assertSee('<td class="num dt-crit" title="down 18 jam, 3 gangguan">2,5</td>', false)
            ->assertSee('<td class="num dt-warn" title="down 43 menit, 1 gangguan, sementara">0,1</td>', false); // di bawah batas
        $html = $r->getContent();
        $this->assertLessThan(strpos($html, 'Switch Kantor'), strpos($html, 'Radio Pit A'), 'urut dari persen down tertinggi');
        $this->actingAs($v)->get('/laporan/down-bulanan?bulan=3&pernah_down=1')->assertDontSee('Switch Kantor');
        $this->actingAs($v)->get('/laporan/down-bulanan?bulan=3&site=KANTOR')->assertSee('Switch Kantor')->assertDontSee('Radio Pit A');
        $this->actingAs($v)->get('/laporan/down-bulanan?bulan=5')->assertSessionHasErrors('bulan');

        $csv = $this->actingAs($v)->get('/laporan/down-bulanan.csv?bulan=3')->streamedContent();
        $this->assertStringContainsString(';"Radio Pit A";10.8.0.1;PIT-A;720,0;1080,0;2,500;97,500;3;final', $csv);

        $admin = $this->user('admin');
        $this->flushSession();
        $this->actingAs($admin)->post('/token-api', ['name' => 'SOC', 'days' => 90]);
        $t = session('api_token');
        auth()->logout();
        $this->flushSession();
        $j = $this->getJson('/api/v1/downtime?month=' . substr($m1, 0, 7), ['Authorization' => "Bearer {$t}"])->assertOk();
        $this->assertCount(3, $j->json('data'));
        $this->assertEquals(2.5, collect($j->json('data'))->firstWhere('device', 'Radio Pit A')['down_pct']);
        $this->getJson('/api/v1/downtime?month=2026-13', ['Authorization' => "Bearer {$t}"])->assertStatus(422);
    }
}
