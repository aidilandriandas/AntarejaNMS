<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteAccessTest extends TestCase
{
    use DatabaseTransactions;

    private function data(): array
    {
        $a = DB::table('sites')->insertGetId(['code' => 'SA-' . random_int(100, 999), 'name' => 'Pit Utara']);
        $b = DB::table('sites')->insertGetId(['code' => 'SB-' . random_int(100, 999), 'name' => 'Pit Selatan']);
        $da = DB::table('devices')->insertGetId(['name' => 'radio-utara-x', 'host' => '10.71.0.1', 'site_id' => $a, 'status' => 'down']);
        $db = DB::table('devices')->insertGetId(['name' => 'radio-selatan-x', 'host' => '10.72.0.1', 'site_id' => $b, 'status' => 'down']);
        $pa = DB::table('problems')->insertGetId(['device_id' => $da, 'kind' => 'down', 'severity' => 'high', 'title' => 'radio-utara-x down']);
        $pb = DB::table('problems')->insertGetId(['device_id' => $db, 'kind' => 'down', 'severity' => 'high', 'title' => 'radio-selatan-x down']);
        foreach ([$da, $db] as $d) {
            DB::table('device_sensors')->insert(['device_id' => $d, 'key' => 'cpu', 'class' => 'cpu', 'grp' => 'sistem', 'label' => 'CPU ' . $d, 'value' => 99, 'status' => 'crit']);
            DB::table('events')->insert(['device_id' => $d, 'kind' => 'down', 'message' => 'Down ' . $d, 'at' => now()]);
        }

        return [$a, $b, $da, $db, $pa, $pb];
    }

    public function test_viewer_terbatas_hanya_melihat_site_nya(): void
    {
        [$a, , $da, $db] = $this->data();
        $v = $this->user('viewer');
        DB::table('user_sites')->insert(['user_id' => $v->id, 'site_id' => $a]);

        $this->actingAs($v)->get('/')->assertOk()->assertDontSee('radio-selatan-x')->assertSee('Akses terbatas');
        $this->actingAs($v)->get('/perangkat')->assertOk()->assertSee('radio-utara-x')->assertDontSee('radio-selatan-x');
        $this->actingAs($v)->get("/perangkat/{$da}")->assertOk();
        $this->actingAs($v)->get("/perangkat/{$db}")->assertNotFound();
        $this->actingAs($v)->get('/problem')->assertOk()->assertSee('radio-utara-x down')->assertDontSee('radio-selatan-x down');
        $this->actingAs($v)->get('/sensor?tab=sistem')->assertOk()->assertSee('radio-utara-x')->assertDontSee('radio-selatan-x');
        $this->actingAs($v)->get('/kejadian')->assertOk()->assertSee('radio-utara-x')->assertDontSee('radio-selatan-x');
        foreach (['/ipam', '/topologi', '/noc', '/log', '/peta', '/flow', '/versi', '/laporan/sla', '/dampak', '/insiden'] as $url) {
            $this->actingAs($v)->get($url)->assertForbidden();
        }
        $this->actingAs($v)->getJson('/noc/data')->assertForbidden();
    }

    public function test_engineer_terbatas_hanya_ack(): void
    {
        [$a, , $da, , $pa, $pb] = $this->data();
        $e = $this->user('engineer');
        DB::table('user_sites')->insert(['user_id' => $e->id, 'site_id' => $a]);

        $this->actingAs($e)->post("/problem/{$pa}/ack", ['note' => 'tim jalan'])->assertRedirect();
        $this->assertNotNull(DB::table('problems')->where('id', $pa)->value('acked_at'));
        $this->actingAs($e)->post("/problem/{$pb}/ack")->assertNotFound();
        $this->assertNull(DB::table('problems')->where('id', $pb)->value('acked_at'));
        $this->actingAs($e)->get('/perangkat/tambah')->assertForbidden();
        $this->actingAs($e)->put("/perangkat/{$da}", ['name' => 'ganti'])->assertForbidden();
        $this->actingAs($e)->get("/perangkat/{$da}")->assertOk()->assertDontSee(route('devices.edit', $da));
    }

    public function test_admin_mengatur_batas_site(): void
    {
        [$a, $b] = $this->data();
        $admin = $this->user('admin');
        $base = ['name' => 'Kontraktor', 'username' => 'kontraktor' . random_int(10, 99), 'role' => 'viewer', 'password' => 'Kontraktor123', 'password_confirmation' => 'Kontraktor123', 'active' => 1];
        $this->actingAs($admin)->post('/pengguna', $base + ['sites' => [$a, $b]])->assertRedirect();
        $id = DB::table('users')->where('username', $base['username'])->value('id');
        $this->assertSame([$a, $b], DB::table('user_sites')->where('user_id', $id)->orderBy('site_id')->pluck('site_id')->map(fn ($v) => (int) $v)->all());
        $this->actingAs($admin)->get('/pengguna')->assertOk()->assertSee('SA-', false);
        $this->actingAs($admin)->get('/pengguna/tambah')->assertOk()->assertSee('Site yang boleh dilihat');
        $this->actingAs($admin)->post('/pengguna', array_merge($base, ['username' => 'admin2x' . random_int(10, 99), 'role' => 'admin', 'sites' => [$a]]))->assertSessionHasErrors('sites');
        // admin tetap melihat semua
        $this->actingAs($admin)->get('/perangkat')->assertSee('radio-utara-x')->assertSee('radio-selatan-x');
    }
}
