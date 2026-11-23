<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiagnoseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unduh_diagnosa_snmp(): void
    {
        config(['antareja.collector_url' => 'http://collector.test:8081']);
        $dev = DB::table('devices')->insertGetId(['name' => 'NVR Pit A', 'host' => '10.1.1.9', 'snmp_enabled' => true]);
        $off = DB::table('devices')->insertGetId(['name' => 'Kamera', 'host' => '10.1.1.10', 'snmp_enabled' => false]);
        Http::fake(["collector.test:8081/v1/devices/{$dev}/diagnose" => Http::response(['device' => 'NVR Pit A', 'sys_object_id' => '.1.3.6.1.4.1.39165.1.1',
            'enterprise_walk' => [['.1.3.6.1.4.1.39165.1.1.0', 'DS-7608NI']]])]);
        $eng = $this->user('engineer');
        $this->flushSession();
        $this->actingAs($eng)->get("/perangkat/{$dev}")->assertSee('Diagnosa SNMP');
        $r = $this->actingAs($eng)->get("/perangkat/{$dev}/diagnosa")->assertOk();
        $this->assertStringContainsString('attachment; filename=diagnosa-snmp-nvr-pit-a-', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('"sys_object_id": ".1.3.6.1.4.1.39165.1.1"', $r->streamedContent());
        $this->actingAs($eng)->get("/perangkat/{$off}")->assertDontSee('Diagnosa SNMP');
        $this->actingAs($eng)->get("/perangkat/{$off}/diagnosa")->assertStatus(422);

        $v = $this->user('viewer');
        $this->flushSession();
        $this->actingAs($v)->get("/perangkat/{$dev}/diagnosa")->assertForbidden();
    }
}
