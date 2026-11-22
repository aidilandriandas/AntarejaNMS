<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_collector_diam_memunculkan_banner_untuk_semua_peran(): void
    {
        DB::table('system_status')->insert(['key' => 'collector', 'value' => '{"version":"x"}', 'updated_at' => now()->subMinutes(10)]);
        $this->actingAs($this->user('viewer'))->get('/perangkat')->assertOk()->assertSee('collector ANTAREJA tidak melapor');
        $this->assertSame('crit', $this->get('/noc/data')->json('alarm.level'));
        $this->assertStringContainsString('DATA USANG', $this->get('/noc/data')->json('alarm.text'));
    }

    public function test_collector_normal_tanpa_banner(): void
    {
        DB::table('system_status')->insert(['key' => 'collector', 'value' => json_encode(['version' => 'x', 'ping_at' => now()->toIso8601String()]), 'updated_at' => now()]);
        $this->actingAs($this->user('viewer'))->get('/perangkat')->assertOk()->assertDontSee('collector ANTAREJA tidak melapor');
    }

    public function test_halaman_kesehatan_sistem_admin(): void
    {
        DB::table('system_status')->insert([
            ['key' => 'collector', 'value' => json_encode(['version' => '0.2.0', 'ping_at' => now()->toIso8601String(), 'syslog' => ['received' => 10, 'stored' => 9, 'dropped' => 1], 'syslog_dropped_1h' => 1]), 'updated_at' => now()],
            ['key' => 'backup', 'value' => '{"disk_total_kb": 100000000, "disk_free_kb": 4000000}', 'updated_at' => now()],
        ]);
        DB::table('system_alerts')->insert(['key' => 'backup_disk', 'active' => true, 'severity' => 'high', 'message' => 'Ruang kosong tinggal 3.8 GB', 'since' => now()]);
        $admin = $this->user('admin');
        $this->actingAs($admin)->get('/sistem')->assertOk()
            ->assertSee('Disk backup hampir penuh')->assertSee('Ruang kosong tinggal 3.8 GB')->assertSee('Berjalan');
        $this->actingAs($admin)->get('/perangkat')->assertOk()->assertSee('masalah sistem ANTAREJA');
    }
}
