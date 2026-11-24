<?php

namespace Tests\Feature;

use App\Models\ModbusPoint;
use App\Services\Collector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModbusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_atur_titik_salin_dan_matikan(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'menara-a', 'host' => '10.97.0.10']);
        $dev2 = DB::table('devices')->insertGetId(['name' => 'menara-b', 'host' => '10.97.0.11']);
        $eng = $this->user('engineer');
        $this->actingAs($eng)->put("/perangkat/{$dev}/modbus", ['enabled' => '1', 'modbus_port' => 502, 'modbus_unit' => 1])->assertSessionHas('ok');
        $this->assertSame(502, DB::table('devices')->where('id', $dev)->value('modbus_port'));
        $pt = ['label' => 'Baterai', 'class' => 'voltage', 'unit' => 'V', 'fn' => 4, 'address' => 12548, 'dtype' => 'u16', 'scale' => '0.01', 'enabled' => '1'];
        $this->actingAs($eng)->post("/perangkat/{$dev}/modbus/titik", $pt + ['warn_low' => '11', 'crit_low' => '12'])->assertSessionHasErrors('crit_low');
        $this->actingAs($eng)->post("/perangkat/{$dev}/modbus/titik", array_merge($pt, ['scale' => '0']))->assertSessionHasErrors('scale');
        $this->actingAs($eng)->post("/perangkat/{$dev}/modbus/titik", array_merge($pt, ['dtype' => 'f32', 'address' => 65535]))->assertSessionHasErrors('address');
        $this->actingAs($eng)->post("/perangkat/{$dev}/modbus/titik", $pt + ['warn_low' => '12.2', 'crit_low' => '11.8'])->assertRedirect();
        $p = ModbusPoint::where('device_id', $dev)->firstOrFail();
        $this->assertSame('warning di bawah 12,2, kritis di bawah 11,8', $p->thresholdLabel());
        DB::table('device_sensors')->insert(['device_id' => $dev, 'key' => 'mb:' . $p->id, 'class' => 'voltage', 'grp' => 'daya', 'label' => 'Baterai', 'unit' => 'V', 'value' => 12.9, 'status' => 'ok']);
        $this->actingAs($eng)->get("/perangkat/{$dev}/modbus")->assertOk()->assertSee('12,9 V')->assertSee('input 12548');
        // salin ke menara lain
        $this->actingAs($eng)->post("/perangkat/{$dev2}/modbus/salin", ['source' => $dev])->assertSessionHas('ok');
        $this->assertSame(1, ModbusPoint::where('device_id', $dev2)->count());
        // titik milik perangkat lain tidak bisa diubah lewat perangkat ini
        $this->actingAs($eng)->delete("/perangkat/{$dev2}/modbus/titik/{$p->id}")->assertNotFound();
        // matikan: sensor Modbus dihapus
        $this->actingAs($eng)->put("/perangkat/{$dev}/modbus", ['modbus_unit' => 1])->assertSessionHas('ok');
        $this->assertNull(DB::table('devices')->where('id', $dev)->value('modbus_port'));
        $this->assertSame(0, DB::table('device_sensors')->where('device_id', $dev)->count());
        $this->flushSession();
        $this->actingAs($this->user('viewer'))->get("/perangkat/{$dev}/modbus")->assertForbidden();
    }

    public function test_uji_baca(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'menara-c', 'host' => '10.97.0.12', 'modbus_port' => 502]);
        $this->app->instance(Collector::class, new class extends Collector {
            public function modbusTest(int $deviceId): array
            {
                return ['ok' => true, 'readings' => [['point_id' => 1, 'label' => 'Baterai', 'unit' => 'V', 'value' => 11.5, 'raw' => [1150], 'status' => 'crit']]];
            }
        });
        $eng = $this->user('engineer');
        $this->actingAs($eng)->post("/perangkat/{$dev}/modbus/uji")->assertSessionHas('modbus_test');
        $this->actingAs($eng)->get("/perangkat/{$dev}/modbus")->assertOk()->assertSee('Hasil uji baca')->assertSee('1150 (0x47E)');
    }
}
