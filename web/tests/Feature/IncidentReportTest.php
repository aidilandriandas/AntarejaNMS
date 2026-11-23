<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_laporan_insiden_bulanan(): void
    {
        $a = DB::table('sites')->insertGetId(['code' => 'PIT-X', 'name' => 'Pit X']);
        $b = DB::table('sites')->insertGetId(['code' => 'PIT-Y', 'name' => 'Pit Y']);
        $eng = $this->user('engineer', ['name' => 'Budi NOC']);
        $t = fn ($d, $h, $m = 0) => "2026-10-{$d} {$h}:" . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ':00+07';
        // dibuka 08:00, ditangani 08:10, pulih 09:00, penyebab lengkap
        DB::table('incidents')->insert(['title' => 'PLN padam Pit X', 'severity' => 'disaster', 'status' => 'closed', 'site_id' => $a, 'owner_id' => $eng->id, 'auto' => true,
            'cause' => 'PLN padam', 'action_taken' => 'Genset', 'opened_at' => $t(5, 8), 'handled_at' => $t(5, 8, 10), 'recovered_at' => $t(5, 9), 'closed_at' => $t(5, 10)]);
        // dibuka 10:00, ditangani 10:30, pulih 13:00, penyebab kosong
        DB::table('incidents')->insert(['title' => 'Radio Pit Y putus', 'severity' => 'high', 'status' => 'recovered', 'site_id' => $b, 'owner_id' => $eng->id,
            'opened_at' => $t(12, 10), 'handled_at' => $t(12, 10, 30), 'recovered_at' => $t(12, 13)]);
        // bulan lain: tidak ikut
        DB::table('incidents')->insert(['title' => 'Bulan lalu', 'severity' => 'high', 'status' => 'closed', 'site_id' => $a, 'opened_at' => '2026-09-20 08:00:00+07', 'recovered_at' => '2026-09-20 09:00:00+07']);

        $v = $this->user('viewer');
        $this->flushSession();
        $r = $this->actingAs($v)->get('/laporan/insiden?bulan=2026-10')->assertOk();
        $r->assertSee('Insiden bulanan, Oktober 2026')->assertSee('PLN padam Pit X')->assertSee('Radio Pit Y putus')->assertDontSee('Bulan lalu')
            ->assertSee('<div class="val">20 menit</div>', false) // MTTA (10 + 30) / 2
            ->assertSee('<div class="val">2 jam</div>', false)  // MTTR (60 + 180) / 2
            ->assertSee('<td class="num">3 jam</td>', false)   // MTTR site PIT-Y
            ->assertSee('50<small>%</small>', false) // 1 dari 2 penyebab tercatat
            ->assertSee('Budi NOC')->assertSee('belum diisi');
        $this->actingAs($v)->get("/laporan/insiden?bulan=2026-10&site={$b}")->assertOk()->assertSee('Radio Pit Y putus')->assertDontSee('PLN padam Pit X');
        $csv = $this->actingAs($v)->get('/laporan/insiden.csv?bulan=2026-10')->streamedContent();
        $this->assertStringContainsString('"PLN padam Pit X";Disaster;Ditutup;PIT-X;"Budi NOC";ya;0;"2026-10-05 08:00"', $csv);
        $this->actingAs($v)->get('/laporan/insiden?bulan=2026-08')->assertOk()->assertSee('Tidak ada insiden yang dibuka');
    }
}
