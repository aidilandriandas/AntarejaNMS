<?php

namespace Tests\Feature;

use App\Services\Impact;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImpactTest extends TestCase
{
    use DatabaseTransactions;

    private function topo(): array
    {
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-I', 'name' => 'Pit I']);
        $core = DB::table('devices')->insertGetId(['name' => 'core-i', 'host' => '10.70.0.1']);
        $ptp = DB::table('devices')->insertGetId(['name' => 'ptp-i', 'host' => '10.70.0.2', 'site_id' => $site, 'parent_id' => $core, 'status' => 'down']);
        $sw = DB::table('devices')->insertGetId(['name' => 'sw-i', 'host' => '10.70.0.3', 'site_id' => $site, 'parent_id' => $ptp, 'status' => 'down']);
        $nvr = DB::table('devices')->insertGetId(['name' => 'nvr-i', 'host' => '10.70.0.4', 'site_id' => $site, 'parent_id' => $sw, 'category' => 'nvr', 'status' => 'down']);
        DB::table('cameras')->insert([['name' => 'Kamera haul road', 'device_id' => $nvr, 'site_id' => $site, 'host' => '10.70.1.1']]);
        $svc = DB::table('services')->insertGetId(['name' => 'CCTV Pit I']);
        $comp = DB::table('service_components')->insertGetId(['service_id' => $svc, 'name' => 'NVR', 'min_up' => 1]);
        DB::table('service_component_devices')->insert(['component_id' => $comp, 'device_id' => $nvr]);

        return [$core, $ptp, $sw, $nvr];
    }

    public function test_dampak_dihitung_dari_induk_kamera_layanan_dan_site(): void
    {
        [$core, $ptp] = $this->topo();
        $impact = new Impact;
        $this->assertContains($ptp, $impact->downRoots());
        $r = $impact->of([$ptp]);
        $this->assertSame(['nvr-i', 'sw-i'], array_column($r['devices'], 'name'));
        $this->assertSame('2 perangkat, 1 kamera, 1 site putus total', Impact::line($r), 'layanan sudah down sekarang, jadi tidak dihitung sebagai perubahan');
        // simulasi dari core yang masih hidup: layanan dihitung terhadap status sekarang
        DB::table('devices')->whereIn('name', ['ptp-i', 'sw-i', 'nvr-i'])->update(['status' => 'up']);
        $this->assertSame('3 perangkat, 1 kamera, 1 layanan terganggu, 1 site putus total', Impact::line((new Impact)->of([$core])));
    }

    public function test_halaman_dampak_dan_baris_di_problem(): void
    {
        [, $ptp] = $this->topo();
        DB::table('problems')->insert(['device_id' => $ptp, 'kind' => 'down', 'severity' => 'high', 'title' => 'ptp-i down', 'started_at' => now()]);
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get('/dampak')->assertOk()->assertSee('ptp-i')->assertSee('Kamera haul road')->assertSee('putus total');
        $this->actingAs($viewer)->get('/dampak?sim[]=' . $ptp)->assertOk()->assertSee('Hitung dampak');
        $this->actingAs($viewer)->get('/problem')->assertOk()->assertSee('Dampak: 2 perangkat, 1 kamera');
        $this->actingAs($viewer)->get('/perangkat/' . $ptp)->assertOk()->assertSee('Dampak sekarang');
    }
}
