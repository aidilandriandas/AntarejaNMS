<?php

namespace Tests\Feature;

use App\Models\Incident;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use DatabaseTransactions;

    /** @return int[] id problem */
    private function isiProblem(): array
    {
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-B', 'name' => 'Pit B']);
        $ids = [];
        foreach (['Radio 1', 'Radio 2'] as $n => $name) {
            $dev = DB::table('devices')->insertGetId(['name' => $name, 'host' => '10.9.0.' . ($n + 1), 'site_id' => $site, 'category' => 'ptp']);
            $ids[] = DB::table('problems')->insertGetId(['device_id' => $dev, 'kind' => 'down', 'severity' => 'high', 'title' => "$name down", 'started_at' => now()->subMinutes(20)]);
        }

        return $ids;
    }

    public function test_buat_insiden_manual_tangani_dan_tutup_wajib_rca(): void
    {
        $ids = $this->isiProblem();
        $eng = $this->user('engineer');
        $this->actingAs($eng)->get('/problem')->assertOk()->assertSee('Buat insiden')->assertSee('form="buat-insiden"', false);

        $this->actingAs($eng)->post('/insiden', ['problems' => $ids, 'title' => 'Radio Pit B mati'])->assertRedirect();
        $i = Incident::latest('id')->first();
        $this->assertSame('Radio Pit B mati', $i->title);
        $this->assertSame('high', $i->severity);
        $this->assertNotNull($i->site_id);
        $this->assertCount(2, $i->problems);

        // problem yang sudah masuk insiden tidak bisa dipakai lagi & tampil tautannya
        $this->actingAs($eng)->post('/insiden', ['problems' => $ids])->assertSessionHasErrors('problems');
        $this->actingAs($eng)->get('/problem')->assertSee('Insiden #' . $i->id);

        $this->actingAs($eng)->post("/insiden/{$i->id}/tangani")->assertRedirect();
        $i->refresh();
        $this->assertSame('handling', $i->status);
        $this->assertSame($eng->id, $i->owner_id);

        // tutup tanpa RCA ditolak
        $this->actingAs($eng)->post("/insiden/{$i->id}/tutup")->assertSessionHasErrors('cause');
        $this->actingAs($eng)->post("/insiden/{$i->id}/analisis", ['title' => $i->title, 'cause' => 'PLN padam', 'action_taken' => 'Genset dinyalakan'])->assertRedirect();
        // masih ada problem aktif → perlu force
        $this->actingAs($eng)->post("/insiden/{$i->id}/tutup")->assertSessionHasErrors('cause');
        DB::table('problems')->whereIn('id', $ids)->update(['resolved_at' => now()]);
        $this->actingAs($eng)->post("/insiden/{$i->id}/tutup")->assertSessionHasNoErrors();
        $i->refresh();
        $this->assertSame('closed', $i->status);
        $this->assertNotNull($i->recovered_at);

        $this->actingAs($eng)->post("/insiden/{$i->id}/catatan", ['body' => 'Cek baterai UPS minggu depan'])->assertRedirect();
        $this->actingAs($eng)->get("/insiden/{$i->id}")->assertOk()->assertSee('Cek baterai UPS minggu depan')->assertSee('PLN padam');
        $this->actingAs($eng)->get('/insiden?tab=ditutup')->assertOk()->assertSee('Radio Pit B mati');
        $csv = $this->actingAs($eng)->get('/insiden.csv')->assertOk();
        $this->assertStringContainsString('Genset dinyalakan', $csv->getContent());
    }

    public function test_viewer_hanya_lihat(): void
    {
        $ids = $this->isiProblem();
        $i = Incident::create(['title' => 'Uji', 'severity' => 'high', 'opened_at' => now()]);
        $i->problems()->attach($ids);
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/insiden')->assertOk()->assertSee('Uji');
        $this->actingAs($v)->get("/insiden/{$i->id}")->assertOk()->assertDontSee('Saya tangani');
        $this->actingAs($v)->get('/problem')->assertDontSee('Buat insiden');
        $this->actingAs($v)->post('/insiden', ['problems' => $ids])->assertForbidden();
        foreach (['tangani', 'catatan', 'analisis', 'tutup', 'buka'] as $a) {
            $this->actingAs($v)->post("/insiden/{$i->id}/$a")->assertForbidden();
        }
    }
}
