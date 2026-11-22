<?php

namespace Tests\Feature;

use App\Models\Trigger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TriggerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_buat_ubah_matikan_hapus_trigger(): void
    {
        $eng = $this->user('engineer');
        $this->actingAs($eng)->get('/trigger')->assertOk()->assertSee('Belum ada trigger');
        $this->actingAs($eng)->get('/trigger/tambah')->assertOk()->assertSee('data-show-if="metric=sensor"', false);

        $base = ['name' => 'Uplink mati jam kerja', 'severity' => 'high', 'metric' => 'port_in', 'match_text' => ' sfp ', 'op' => '<', 'threshold' => '0.01',
            'duration_min' => 10, 'scope' => 'all', 'days' => [0, 1, 2, 3, 4], 'start' => '07:00', 'end' => '18:00'];
        $this->actingAs($eng)->post('/trigger', ['metric' => 'sensor'] + $base)->assertSessionHasErrors('sensor_class');
        $this->actingAs($eng)->post('/trigger', array_merge($base, ['end' => null]))->assertSessionHasErrors('end');
        $this->actingAs($eng)->post('/trigger', $base)->assertRedirect('/trigger');

        $t = Trigger::firstOrFail();
        $this->assertSame(31, $t->days);
        $this->assertSame(420, $t->start_min);
        $this->assertSame('sfp', $t->match_text);
        $this->assertSame('Trafik masuk port berisi "sfp" kurang dari 0,01 Mbps selama 10 menit', $t->conditionText());
        $this->assertSame('Senin sampai Jumat, 07.00 sampai 18.00', $t->scheduleText());
        $this->actingAs($eng)->get('/trigger')->assertSee('Uplink mati jam kerja')->assertSee('Semua perangkat');

        // perangkat terpilih + sensor, jadwal melewati tengah malam
        $dev = DB::table('devices')->insertGetId(['name' => 'Panel Pit A', 'host' => '10.2.0.1', 'category' => 'ups']);
        $this->actingAs($eng)->put("/trigger/{$t->id}", ['metric' => 'sensor', 'sensor_class' => 'temperature', 'op' => '>=', 'threshold' => '60',
            'scope' => 'devices', 'devices' => [$dev], 'start' => '22:00', 'end' => '00:00', 'days' => [0, 1, 2, 3, 4, 5, 6]] + $base)->assertRedirect('/trigger');
        $t->refresh();
        $this->assertSame([$dev], $t->devices()->pluck('devices.id')->all());
        $this->assertSame(1440, $t->end_min);
        $this->assertSame('Sensor suhu berisi "sfp" paling sedikit 60 selama 10 menit', $t->conditionText());
        $this->assertSame('Setiap hari, 22.00 sampai 00.00', $t->scheduleText());

        DB::table('problems')->insert(['device_id' => $dev, 'kind' => 'custom', 'sensor_key' => "trg:{$t->id}:temp", 'severity' => 'high', 'title' => 'x']);
        $this->actingAs($eng)->get('/trigger')->assertSee('class="inc-tag">1<', false);

        $this->actingAs($eng)->post("/trigger/{$t->id}/aktif")->assertRedirect();
        $this->assertFalse($t->fresh()->enabled);
        $this->actingAs($eng)->delete("/trigger/{$t->id}")->assertRedirect('/trigger');
        $this->assertSame(0, Trigger::count());
    }

    public function test_viewer_hanya_melihat(): void
    {
        $t = Trigger::create(['name' => 'Latensi', 'severity' => 'warning', 'metric' => 'latency', 'op' => '>', 'threshold' => 80, 'duration_min' => 5, 'scope' => 'all', 'days' => 127]);
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/trigger')->assertOk()->assertSee('Latensi ping lebih dari 80 ms selama 5 menit')->assertDontSee('Tambah trigger');
        $this->actingAs($v)->get('/trigger/tambah')->assertForbidden();
        $this->actingAs($v)->post('/trigger', [])->assertForbidden();
        $this->actingAs($v)->post("/trigger/{$t->id}/aktif")->assertForbidden();
        $this->actingAs($v)->delete("/trigger/{$t->id}")->assertForbidden();
    }
}
