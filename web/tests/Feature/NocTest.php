<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NocTest extends TestCase
{
    use DatabaseTransactions;

    public function test_hitungan_lengkap_problem_baru_di_atas_dan_sidik_berubah(): void
    {
        DB::table('problems')->delete();
        $site = DB::table('sites')->insertGetId(['code' => 'NOC' . random_int(100, 999), 'name' => 'Uji NOC']);
        $dev = fn (string $n, string $st = 'down') => DB::table('devices')->insertGetId(['name' => $n, 'host' => '10.88.' . random_int(0, 255) . '.' . random_int(1, 254),
            'category' => 'cctv', 'site_id' => $site, 'status' => $st]);
        // 45 problem High lama: lebih banyak dari daftar yang tampil
        for ($i = 0; $i < 45; $i++) {
            $d = $dev("CAM lama {$i}");
            DB::table('problems')->insert(['device_id' => $d, 'kind' => 'down', 'severity' => 'high', 'title' => "CAM lama {$i} down",
                'started_at' => now()->subHours(3), 'sensor_key' => '']);
        }
        DB::table('system_status')->updateOrInsert(['key' => 'collector'], ['value' => '{}', 'updated_at' => now()]);
        $viewer = $this->user('viewer');
        $j = $this->actingAs($viewer)->getJson("/noc/data?site={$site}")->assertOk()->json();
        $this->assertSame('45 problem High/Disaster belum di-ACK', $j['alarm']['text'], json_encode($j['alarm']));
        $this->assertSame(45, $j['kpi']['problems']);
        $this->assertSame(20, $j['kpi']['shown']);
        $this->assertSame(45, collect($j['severity'])->firstWhere('key', 'high')['n']);
        $this->assertSame([], $j['fresh']);
        $sig = $this->actingAs($viewer)->getJson("/noc/state?site={$site}")->assertOk()->json('sig');
        $this->assertSame($j['sig'], $sig);

        // Alert baru severity rendah tetap muncul paling atas; sidik berubah sehingga layar langsung memuat ulang
        $w = $dev('Switch mess', 'up');
        $pid = DB::table('problems')->insertGetId(['device_id' => $w, 'kind' => 'sensor', 'severity' => 'warning', 'title' => 'Suhu switch mess tinggi',
            'started_at' => now(), 'sensor_key' => 'temp:1']);
        $this->assertNotSame($sig, $this->actingAs($viewer)->getJson("/noc/state?site={$site}")->json('sig'));
        $j = $this->actingAs($viewer)->getJson("/noc/data?site={$site}")->json();
        $this->assertSame($pid, $j['problems'][0]['id']);
        $this->assertSame(46, $j['kpi']['problems']);

        // High baru masuk daftar bunyi; ACK mengubah sidik
        $h = DB::table('problems')->insertGetId(['device_id' => $dev('Radio PTP'), 'kind' => 'down', 'severity' => 'high', 'title' => 'Radio PTP down',
            'started_at' => now(), 'sensor_key' => '']);
        $j = $this->actingAs($viewer)->getJson("/noc/data?site={$site}")->json();
        $this->assertSame([$h], $j['fresh']);
        DB::table('problems')->where('id', $h)->update(['acked_at' => now()]);
        $this->assertNotSame($j['sig'], $this->actingAs($viewer)->getJson("/noc/state?site={$site}")->json('sig'));
        $this->assertSame('45 problem High/Disaster belum di-ACK', $this->actingAs($viewer)->getJson("/noc/data?site={$site}")->json('alarm.text'));
    }
}
