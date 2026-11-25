<?php

namespace Tests\Feature;

use App\Models\Camera;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CameraProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profil_login_dan_tambah_massal(): void
    {
        $eng = $this->user('engineer');
        $site = DB::table('sites')->insertGetId(['code' => 'MESS' . random_int(10, 99), 'name' => 'Mess']);
        $a = DB::table('devices')->insertGetId(['name' => 'CCTV - MESS ANGGREK 04', 'host' => '10.17.34.135', 'category' => 'cctv', 'site_id' => $site]);
        $b = DB::table('devices')->insertGetId(['name' => 'CCTV - MESS ANGGREK 05', 'host' => '10.17.34.136', 'category' => 'cctv']);
        DB::table('devices')->insert(['name' => 'Switch mess', 'host' => '10.17.34.1', 'category' => 'access']);

        $this->actingAs($eng)->get('/cctv/kelola')->assertOk()->assertSee('Buat profil login dulu');
        $this->actingAs($eng)->post('/cctv/profil', ['name' => 'Hikvision Mess', 'username' => 'viewer', 'password' => 'Rahasia#1', 'rtsp_port' => 554,
            'path_main' => '/Streaming/Channels/{ch}01', 'path_sub' => '/Streaming/Channels/{ch}02'])->assertSessionHasNoErrors();
        $pid = DB::table('camera_profiles')->where('name', 'Hikvision Mess')->value('id');
        $page = $this->actingAs($eng)->get('/cctv/kelola')->assertOk();
        $page->assertSee('CCTV - MESS ANGGREK 04')->assertSee('2 kamera IP belum ditambahkan')->assertDontSee('data-text="switch mess', false)->assertDontSee('Rahasia#1');

        $this->actingAs($eng)->post('/cctv/kamera-massal', ['devices' => [$a, $b], 'profile_id' => $pid, 'channel' => 1])->assertSessionHasNoErrors();
        $cams = Camera::whereIn('device_id', [$a, $b])->orderBy('name')->get();
        $this->assertCount(2, $cams);
        $this->assertSame(['10.17.34.135', (int) $site, (int) $pid, null], [$cams[0]->host, (int) $cams[0]->site_id, (int) $cams[0]->profile_id, $cams[0]->password_enc]);
        $this->assertSame('rtsp://viewer:Rahasia%231@10.17.34.135:554/Streaming/Channels/102', $cams[0]->rtspUrl('sub'));
        // sudah ada: dilewati; daftar kandidat kosong
        $this->actingAs($eng)->post('/cctv/kamera-massal', ['devices' => [$a], 'profile_id' => $pid, 'channel' => 1])->assertSessionHas('ok', fn ($m) => str_contains($m, '1 dilewati'));
        $this->actingAs($eng)->get('/cctv/kelola')->assertSee('Semua perangkat berkategori Kamera CCTV sudah ada');

        // ganti password profil: semua kameranya ikut; kosong = tetap
        $this->actingAs($eng)->put("/cctv/profil/{$pid}", ['name' => 'Hikvision Mess', 'username' => 'viewer', 'password' => 'Baru!2', 'rtsp_port' => 554,
            'path_main' => '/Streaming/Channels/{ch}01', 'path_sub' => '/Streaming/Channels/{ch}02'])->assertSessionHasNoErrors();
        $this->actingAs($eng)->put("/cctv/profil/{$pid}", ['name' => 'Hikvision Mess', 'username' => 'viewer', 'password' => '', 'rtsp_port' => 554,
            'path_main' => '/Streaming/Channels/{ch}01', 'path_sub' => '/Streaming/Channels/{ch}02'])->assertSessionHasNoErrors();
        $this->assertSame('rtsp://viewer:Baru%212@10.17.34.136:554/Streaming/Channels/101', Camera::where('device_id', $b)->first()->rtspUrl('main'));

        // profil masih dipakai: tidak bisa dihapus
        $this->actingAs($eng)->delete("/cctv/profil/{$pid}")->assertSessionHas('error');
        $this->assertNotNull(DB::table('camera_profiles')->find($pid));

        // tambah satu kamera manual dengan profil: user/password sendiri diabaikan
        $this->actingAs($eng)->post('/cctv/kamera', ['name' => 'CAM gerbang', 'host' => '10.17.34.200', 'rtsp_port' => 554, 'channel_from' => 1, 'channel_to' => 1,
            'path_main' => '/Streaming/Channels/{ch}01', 'path_sub' => '/Streaming/Channels/{ch}02', 'profile_id' => $pid, 'username' => 'abaikan', 'password' => 'abaikan'])->assertSessionHasNoErrors();
        $g = Camera::where('name', 'CAM gerbang')->first();
        $this->assertSame([null, null], [$g->username, $g->password_enc]);
        $this->assertStringStartsWith('rtsp://viewer:Baru%212@', $g->rtspUrl('main'));
        // ekspor/impor dengan rahasia: profil & password ikut, kamera tetap tertaut profil
        $t = new \App\Services\ConfigTransfer;
        $file = $t->parse(json_encode($t->export(true, 'FrasaRahasia123')));
        DB::table('cameras')->update(['profile_id' => null]);
        DB::table('camera_profiles')->delete();
        $res = $t->apply($file, 'FrasaRahasia123');
        $this->assertSame(1, $res['camera_profiles']);
        $this->assertStringStartsWith('rtsp://viewer:Baru%212@', Camera::where('name', 'CAM gerbang')->first()->rtspUrl('main'));
    }
}
