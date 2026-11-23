<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusLinkTest extends TestCase
{
    use DatabaseTransactions;

    private function token(): string
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/tautan-status', ['name' => 'Direksi', 'days' => 30, 'show_devices' => 1])->assertSessionHas('status_url');
        $url = session('status_url');
        auth()->logout();

        return substr($url, strrpos($url, '/') + 1);
    }

    public function test_tautan_sah_tanpa_login_tanpa_ip(): void
    {
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-A', 'name' => 'Pit A']);
        $dev = DB::table('devices')->insertGetId(['name' => 'Router Pit A', 'host' => '10.20.30.40', 'site_id' => $site, 'category' => 'core', 'status' => 'down']);
        $sv = DB::table('services')->insertGetId(['name' => 'CCTV Pit A', 'site_id' => $site, 'target_pct' => 99]);
        $c = DB::table('service_components')->insertGetId(['service_id' => $sv, 'name' => 'Uplink', 'min_up' => 1]);
        DB::table('service_component_devices')->insert(['component_id' => $c, 'device_id' => $dev]);
        $t = $this->token();
        $this->assertSame(40, strlen($t));
        $this->assertSame(0, DB::table('status_links')->where('token_hash', $t)->count(), 'token tidak boleh disimpan polos');
        $r = $this->get('/status/' . $t)->assertOk()->assertSee('Router Pit A')->assertSee('PIT-A')->assertSee('CCTV Pit A')->assertSee('Terganggu');
        $r->assertDontSee('10.20.30.40')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(1, (int) DB::table('status_links')->value('use_count'));
    }

    public function test_tautan_salah_dicabut_kedaluwarsa_ditolak(): void
    {
        $t = $this->token();
        $this->get('/status/' . str_repeat('A', 40))->assertNotFound();
        $this->get('/status/pendek')->assertNotFound();
        DB::table('status_links')->update(['expires_at' => now()->subMinute()]);
        $this->get('/status/' . $t)->assertNotFound();
        DB::table('status_links')->update(['expires_at' => null, 'revoked_at' => now()]);
        $this->get('/status/' . $t)->assertNotFound();
    }
}
