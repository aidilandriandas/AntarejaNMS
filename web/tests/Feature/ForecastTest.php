<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForecastTest extends TestCase
{
    use DatabaseTransactions;

    public function test_halaman_prediksi(): void
    {
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/prediksi')->assertOk()->assertSee('Belum ada prediksi');
        $srv = DB::table('devices')->insertGetId(['name' => 'srv-fms-x', 'host' => '10.6.0.5']);
        $gen = DB::table('devices')->insertGetId(['name' => 'genset-x', 'host' => '10.6.0.6']);
        DB::table('sensor_forecasts')->insert([
            ['device_id' => $srv, 'key' => 'px:disk:root', 'class' => 'disk', 'label' => 'Disk /', 'current' => 92, 'limit_value' => 100, 'per_day' => 2, 'r2' => 0.9, 'eta' => now()->addDays(4), 'points' => 72],
            ['device_id' => $gen, 'key' => 'mb:3', 'class' => 'fuel', 'label' => 'Solar', 'current' => 14, 'limit_value' => 0, 'per_day' => -48, 'r2' => 0.95, 'eta' => now()->addHours(7)->addMinutes(5), 'points' => 40],
        ]);
        $r = $this->actingAs($v)->get('/prediksi')->assertOk();
        $r->assertSeeInOrder(['genset-x', 'habis dalam 7 jam', 'srv-fms-x', 'penuh dalam'])->assertSee('2 per jam');
    }
}
