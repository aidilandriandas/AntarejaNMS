<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CameraEditTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ubah_kamera_di_kartu_samping(): void
    {
        $eng = $this->user('engineer');
        $site = DB::table('sites')->insertGetId(['code' => 'CAM-S' . random_int(10, 99), 'name' => 'Pit Kamera']);
        $cam = DB::table('cameras')->insertGetId(['name' => 'CCTV uji', 'host' => '10.17.34.139', 'rtsp_port' => 554, 'channel' => 1,
            'path_main' => '/Streaming/Channels/{ch}01', 'path_sub' => '/Streaming/Channels/{ch}02', 'username' => 'admin']);
        $this->actingAs($eng)->get('/cctv/kelola')->assertOk()->assertSee('Tambah kamera')->assertDontSee('Batal ubah');
        $this->actingAs($eng)->get("/cctv/kelola?ubah={$cam}")->assertOk()->assertSee('Ubah CCTV uji')->assertSee('Batal ubah')
            ->assertDontSee('Tambah kamera / kanal NVR')->assertSee('value="10.17.34.139"', false);
        $base = ['name' => 'CCTV gerbang', 'host' => '10.17.34.140', 'rtsp_port' => 8554, 'channel' => 3, 'sort' => 2, 'site_id' => $site,
            'path_main' => '/Streaming/Channels/{ch}01', 'path_sub' => '/Streaming/Channels/{ch}02', 'username' => 'viewer', 'enabled' => 1];
        $this->actingAs($eng)->from("/cctv/kelola?ubah={$cam}")->put("/cctv/kamera/{$cam}", array_merge($base, ['host' => 'bukan host!']))
            ->assertRedirect("/cctv/kelola?ubah={$cam}")->assertSessionHasErrors('host');
        $this->actingAs($eng)->put("/cctv/kamera/{$cam}", $base)->assertRedirect('/cctv/kelola');
        $c = DB::table('cameras')->find($cam);
        $this->assertSame(['CCTV gerbang', '10.17.34.140', 8554, 3, (int) $site, 'viewer'], [$c->name, $c->host, (int) $c->rtsp_port, (int) $c->channel, (int) $c->site_id, $c->username]);
    }
}
