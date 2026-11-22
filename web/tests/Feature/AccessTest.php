<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Hak akses per peran: dicek di rute, bukan hanya disembunyikan di menu. */
class AccessTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_ONLY = ['/pengguna', '/audit', '/notifikasi', '/backup', '/laporan/terjadwal', '/tautan-status', '/ekspor-impor'];

    private const ENGINEER = ['/perangkat/tambah', '/perangkat/impor', '/discovery', '/cctv/kelola'];

    private const ANY = ['/', '/perangkat', '/problem', '/kejadian', '/log', '/peta', '/topologi', '/noc', '/kualitas', '/sensor', '/ipam', '/laporan/sla', '/site', '/maintenance', '/profil'];

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->user('admin'); // setup sudah dilakukan
        foreach (['/', '/perangkat', '/pengguna', '/ekspor-impor'] as $u) {
            $this->get($u)->assertRedirect(route('login'));
        }
    }

    public function test_viewer_hanya_melihat(): void
    {
        $v = $this->user('viewer');
        foreach (self::ANY as $u) {
            $this->actingAs($v)->get($u)->assertOk();
        }
        foreach (array_merge(self::ADMIN_ONLY, self::ENGINEER) as $u) {
            $this->actingAs($v)->get($u)->assertForbidden();
        }
        // aksi tulis juga ditolak walau form tidak ditampilkan
        $this->actingAs($v)->post('/site', ['code' => 'X1', 'name' => 'X'])->assertForbidden();
        $this->actingAs($v)->post('/discovery', ['cidr' => '10.0.0.0/24', 'snmp_port' => 161])->assertForbidden();
        $this->actingAs($v)->post('/peta/posisi', ['type' => 'site', 'id' => 1, 'lat' => 0, 'lng' => 0])->assertForbidden();
    }

    public function test_engineer_kelola_perangkat_tapi_bukan_admin(): void
    {
        $e = $this->user('engineer');
        foreach (self::ENGINEER as $u) {
            $this->actingAs($e)->get($u)->assertOk();
        }
        foreach (self::ADMIN_ONLY as $u) {
            $this->actingAs($e)->get($u)->assertForbidden();
        }
    }

    public function test_admin_membuka_semua(): void
    {
        $a = $this->user('admin');
        foreach (array_merge(self::ANY, self::ENGINEER, self::ADMIN_ONLY) as $u) {
            $this->actingAs($a)->get($u)->assertOk();
        }
    }
}
