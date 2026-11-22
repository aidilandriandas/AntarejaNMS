<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_langkah_awal_terisi_otomatis_dan_bisa_disembunyikan(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Langkah awal · 0/9 selesai');
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-A', 'name' => 'Pit A', 'lat' => -1, 'lng' => 116]);
        DB::table('devices')->insert(['name' => 'Core', 'host' => '10.0.0.1', 'site_id' => $site, 'category' => 'core', 'snmp_enabled' => true, 'snmp_ok' => true]);
        $this->actingAs($admin)->get('/')->assertSee('Langkah awal · 4/9 selesai');
        $this->actingAs($admin)->post('/langkah-awal/sembunyikan')->assertRedirect();
        $this->actingAs($admin)->get('/')->assertDontSee('Langkah awal');
        $this->actingAs($admin)->post('/langkah-awal/tampilkan')->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->get('/')->assertSee('Langkah awal');
    }

    public function test_bukan_admin_tidak_melihat_dan_tidak_bisa_mengubah(): void
    {
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/')->assertOk()->assertDontSee('Langkah awal');
        $this->actingAs($v)->post('/langkah-awal/sembunyikan')->assertForbidden();
    }
}
