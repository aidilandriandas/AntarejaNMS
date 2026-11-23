<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnomalyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_halaman_tandai_wajar_dan_setelan(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'sw-pit-a', 'host' => '10.9.0.1']);
        $a = DB::table('anomalies')->insertGetId(['device_id' => $dev, 'metric' => 'port_in', 'if_index' => 3, 'if_name' => 'ether3', 'direction' => 'down',
            'value' => 300000, 'baseline' => 40000000, 'spread' => 6000000, 'score' => -6.6, 'started_at' => now()->subMinutes(20)]);
        DB::table('anomalies')->insert(['device_id' => $dev, 'metric' => 'latency', 'direction' => 'up', 'value' => 80, 'baseline' => 21, 'spread' => 2.1,
            'score' => 28, 'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)->addMinutes(35)]);

        $viewer = $this->user('viewer');
        $this->flushSession();
        $this->actingAs($viewer)->get('/anomali')->assertOk()
            ->assertSee('Trafik masuk ether3')->assertSee('300 Kbps')->assertSee('40 Mbps')->assertSee('1% dari biasanya')
            ->assertSee('kembali normal')->assertSee('80 ms')->assertDontSee('Abaikan selama');
        $this->actingAs($viewer)->post("/anomali/{$a}/wajar", ['hours' => 24])->assertForbidden();

        $eng = $this->user('engineer');
        $this->flushSession();
        $this->actingAs($eng)->post("/anomali/{$a}/wajar", ['hours' => 24, 'note' => 'kamera dimatikan untuk pindah tiang'])->assertSessionHas('ok');
        $row = DB::table('anomalies')->find($a);
        $this->assertNotNull($row->ended_at);
        $this->assertSame($eng->id, (int) $row->dismissed_by);
        $mute = DB::table('anomaly_mutes')->where(['device_id' => $dev, 'metric' => 'port_in', 'if_index' => 3])->first();
        $this->assertNotNull($mute);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, \Illuminate\Support\Carbon::parse($mute->until)->timestamp, 5);
        $this->actingAs($eng)->get('/anomali')->assertSee('ditandai wajar')->assertSee('kamera dimatikan untuk pindah tiang')->assertSee('Sedang diabaikan');
        $this->actingAs($eng)->post("/anomali/{$a}/wajar", ['hours' => 5])->assertSessionHasErrors('hours');

        $this->actingAs($eng)->delete('/anomali/abaikan', ['device_id' => $dev, 'metric' => 'port_in', 'if_index' => 3])->assertSessionHas('ok');
        $this->assertSame(0, DB::table('anomaly_mutes')->where('device_id', $dev)->count());

        // Setelan hanya admin
        $this->actingAs($eng)->put('/anomali/setelan', ['problem_severity' => 'off'])->assertForbidden();
        $admin = $this->user('admin');
        $this->flushSession();
        $this->actingAs($admin)->put('/anomali/setelan', ['problem_severity' => 'average', 'enabled' => 1])->assertSessionHas('ok');
        $this->assertSame('average', Setting::get('anomaly.problem_severity'));
        $this->assertTrue(Setting::get('anomaly.enabled'));
        $this->actingAs($admin)->put('/anomali/setelan', ['problem_severity' => 'disaster'])->assertSessionHasErrors('problem_severity');
        $this->actingAs($admin)->put('/anomali/setelan', ['problem_severity' => 'off'])->assertSessionHas('ok');
        $this->assertFalse(Setting::get('anomaly.enabled'));
        $this->actingAs($admin)->get('/anomali')->assertSee('mati');
    }
}
