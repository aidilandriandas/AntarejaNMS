<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Services\ServiceSla;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sla_layanan_dengan_cadangan_dan_maintenance(): void
    {
        $start = Carbon::parse('2026-01-01 00:00:00', 'UTC');
        $end = $start->copy()->addDays(10);
        $dev = fn ($n) => DB::table('devices')->insertGetId(['name' => $n, 'host' => '10.8.0.' . crc32($n) % 250, 'category' => 'router', 'created_at' => $start->copy()->subMonth()]);
        [$core, $isp1, $isp2] = [$dev('Core'), $dev('ISP 1'), $dev('ISP 2')];
        $down = fn ($d, $from, $hours) => DB::table('problems')->insert(['device_id' => $d, 'kind' => 'down', 'severity' => 'disaster', 'title' => 'down',
            'started_at' => $start->copy()->addHours($from), 'resolved_at' => $start->copy()->addHours($from + $hours)]);
        $down($core, 10, 2);  // core down 2 jam → layanan down
        $down($isp1, 30, 5);  // ISP 1 down 5 jam, ISP 2 tetap hidup → hanya "tanpa cadangan"
        $down($isp2, 33, 4);  // tumpang tindih 2 jam (33–35) → layanan down 2 jam
        $down($core, 100, 3); // 3 jam, 1 jam di antaranya maintenance → 2 jam
        $mid = DB::table('maintenances')->insertGetId(['title' => 'PM', 'scope' => 'devices', 'starts_at' => $start->copy()->addHours(100), 'ends_at' => $start->copy()->addHours(101)]);
        DB::table('maintenance_devices')->insert(['maintenance_id' => $mid, 'device_id' => $core]);

        $s = Service::create(['name' => 'Internet kantor']);
        $s->components()->create(['name' => 'Core', 'min_up' => 1])->devices()->sync([$core]);
        $s->components()->create(['name' => 'Uplink', 'min_up' => 1, 'position' => 1])->devices()->sync([$isp1, $isp2]);
        $s->load('components.devices');

        $r = (new ServiceSla)->compute(collect([$s]), $start, $end)[$s->id];
        $this->assertEqualsWithDelta(6 * 3600, $r['down'], 1);
        $this->assertSame(3, $r['count']);
        $this->assertEqualsWithDelta((5 + 4 - 2) * 3600 - 2 * 3600, $r['degraded'], 1); // ISP 30–37 minus 33–35
        $this->assertEqualsWithDelta(round((1 - 6 / 240) * 100, 3), $r['availability'], 0.001);
        $this->assertSame(['Uplink'], $r['outages'][1]['components']);

        // sebelum perangkat ditambahkan: tidak dihitung
        $r = (new ServiceSla)->compute(collect([$s]), $start->copy()->subYears(2), $start->copy()->subYear())[$s->id];
        $this->assertSame(0, (int) $r['monitored']);
        $this->assertNull($r['availability']);
    }

    public function test_kelola_layanan_dan_hak_akses(): void
    {
        $d1 = DB::table('devices')->insertGetId(['name' => 'NVR Pit A', 'host' => '10.7.0.1', 'category' => 'nvr']);
        $d2 = DB::table('devices')->insertGetId(['name' => 'Radio Pit A', 'host' => '10.7.0.2', 'category' => 'ptp', 'status' => 'down']);
        $eng = $this->user('engineer');
        $this->actingAs($eng)->post('/layanan', ['name' => 'CCTV Pit A', 'target_pct' => '99.9'])->assertRedirect();
        $s = Service::where('name', 'CCTV Pit A')->firstOrFail();
        $this->actingAs($eng)->post("/layanan/{$s->id}/komponen", ['name' => 'Jalur', 'devices' => [$d1, $d2], 'min_up' => 3])->assertSessionHasErrors('min_up');
        $this->actingAs($eng)->post("/layanan/{$s->id}/komponen", ['name' => 'Jalur', 'devices' => [$d1, $d2], 'min_up' => 2])->assertSessionHasNoErrors();
        $this->actingAs($eng)->get("/layanan/{$s->id}")->assertOk()->assertSee('semua 2 wajib hidup')->assertSee('Terganggu');
        $this->actingAs($eng)->get('/layanan')->assertOk()->assertSee('CCTV Pit A');
        $this->assertStringContainsString('CCTV Pit A', $this->actingAs($eng)->get('/layanan.csv')->streamedContent());

        $this->flushSession();
        $v = $this->user('viewer');
        $this->actingAs($v)->get("/layanan/{$s->id}")->assertOk()->assertDontSee('Tambah komponen');
        $this->actingAs($v)->post('/layanan', ['name' => 'X'])->assertForbidden();
        $this->actingAs($v)->delete("/layanan/{$s->id}")->assertForbidden();
        $c = $s->components()->first();
        $this->actingAs($v)->put("/layanan/{$s->id}/komponen/{$c->id}", ['name' => 'Y', 'devices' => [$d1], 'min_up' => 1])->assertForbidden();
    }
}
