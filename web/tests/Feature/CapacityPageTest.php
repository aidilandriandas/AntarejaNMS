<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CapacityPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_halaman_kapasitas_dan_batas(): void
    {
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/kapasitas')->assertOk()->assertSee('Belum ada port yang dipantau');

        $dev = DB::table('devices')->insertGetId(['name' => 'Core Uji', 'host' => '10.6.0.1', 'category' => 'core']);
        DB::table('interfaces')->insert(['device_id' => $dev, 'if_index' => 3, 'name' => 'sfp1', 'speed_bps' => 1_000_000_000]);
        DB::table('monitored_ports')->insert(['device_id' => $dev, 'if_index' => 3]);
        $t = now()->startOfDay()->getTimestamp();
        $vals = fn ($base) => array_map(fn ($i) => [$t - (19 - $i) * 86400, (string) (($base + $i * 10) * 1e6)], range(0, 19));
        Http::fake(['*query_range*' => fn ($req) => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['device_id' => (string) $dev, 'if_index' => '3'], 'values' => $vals(str_contains($req['query'], '_in_') ? 400 : 100)],
        ]]])]);

        $this->actingAs($v)->get('/kapasitas?hari=30')->assertOk()->assertSee('sfp1')->assertSee('(21 hari)')->assertDontSee('Batas kapasitas');
        $csv = $this->actingAs($v)->get('/kapasitas.csv?hari=30')->streamedContent();
        $this->assertStringContainsString('"Core Uji";sfp1', $csv);
        $this->assertStringContainsString(';300;', $csv); // tren +10 Mbps/hari = 300 Mbps per 30 hari
        $this->actingAs($v)->put('/kapasitas/batas', ['threshold' => 70])->assertForbidden();

        $this->flushSession();
        $a = $this->user('admin');
        $this->actingAs($a)->put('/kapasitas/batas', ['threshold' => 70])->assertRedirect();
        $this->assertEquals(70, \App\Models\Setting::get('capacity.threshold_pct'));
    }
}
