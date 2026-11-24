<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Runbook;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RunbookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_paling_spesifik_dipakai(): void
    {
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-RB', 'name' => 'Pit RB']);
        $d = Device::find(DB::table('devices')->insertGetId(['name' => 'ptp-rb', 'host' => '10.90.0.1', 'site_id' => $site, 'category' => 'ptp']));
        $all = collect([
            Runbook::create(['title' => 'Umum', 'body' => 'x', 'enabled' => true]),
            Runbook::create(['title' => 'Down', 'body' => 'x', 'problem_kind' => 'down', 'enabled' => true]),
            Runbook::create(['title' => 'PTP down', 'body' => 'x', 'problem_kind' => 'down', 'category' => 'ptp', 'enabled' => true]),
            Runbook::create(['title' => 'Pit RB', 'body' => 'x', 'site_id' => $site, 'enabled' => true]),
            Runbook::create(['title' => 'Mati', 'body' => 'x', 'device_id' => $d->id, 'enabled' => false]),
        ]);
        $this->assertSame('Pit RB', Runbook::best($all, $d, 'down')->title, 'site (4) lebih spesifik dari kategori+jenis (3)');
        $this->assertSame('Pit RB', Runbook::best($all, $d, 'latency')->title);
        $other = Device::find(DB::table('devices')->insertGetId(['name' => 'sw', 'host' => '10.90.0.2', 'category' => 'access']));
        $this->assertSame('Down', Runbook::best($all, $other, 'down')->title);
        $this->assertSame('Umum', Runbook::best($all, $other, 'snmp')->title);
    }

    public function test_buat_tampil_di_problem_dan_hak_akses(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'ptp-x', 'host' => '10.90.1.1', 'category' => 'ptp']);
        DB::table('problems')->insert(['device_id' => $dev, 'kind' => 'down', 'severity' => 'high', 'title' => 'ptp-x down', 'started_at' => now()]);
        $eng = $this->user('engineer');
        $this->actingAs($eng)->post('/runbook', ['title' => 'Radio PTP down', 'body' => "1. Cek PoE\r\n2. Hubungi vendor", 'problem_kind' => 'down', 'category' => 'ptp', 'enabled' => '1'])->assertRedirect();
        $rb = Runbook::firstWhere('title', 'Radio PTP down');
        $this->assertSame("1. Cek PoE\n2. Hubungi vendor", $rb->body);
        $this->assertSame($eng->id, $rb->updated_by);
        $this->actingAs($eng)->post('/runbook', ['title' => 'x', 'body' => 'y', 'problem_kind' => 'bukan'])->assertSessionHasErrors('problem_kind');
        $this->flushSession();
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get('/problem')->assertOk()->assertSee('Runbook: Radio PTP down');
        $this->actingAs($viewer)->get('/runbook/' . $rb->id)->assertOk()->assertSee('Hubungi vendor');
        $this->actingAs($viewer)->get('/perangkat/' . $dev)->assertOk()->assertSee('Radio PTP down');
        $this->actingAs($viewer)->get('/runbook/tambah')->assertForbidden();
        $this->actingAs($viewer)->put('/runbook/' . $rb->id, ['title' => 'z', 'body' => 'z'])->assertForbidden();
    }
}
