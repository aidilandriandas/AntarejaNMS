<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemplateOidTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sensor_oid_khusus_di_templat(): void
    {
        $eng = $this->user('engineer');
        $tpl = DB::table('device_templates')->insertGetId(['name' => 'Radio merek lain']);
        $dev = DB::table('devices')->insertGetId(['name' => 'PTP MEREK LAIN', 'host' => '10.17.80.1', 'template_id' => $tpl, 'snmp_enabled' => true]);
        $this->actingAs($eng)->get("/templat/{$tpl}")->assertOk()->assertSee('Sensor OID khusus');
        $this->actingAs($eng)->post("/templat/{$tpl}/oid", ['label' => 'Salah', 'oid' => 'ifInOctets', 'mode' => 'get', 'class' => 'signal', 'scale' => 1, 'grp' => 'radio'])
            ->assertSessionHasErrors('oid');
        $this->actingAs($eng)->post("/templat/{$tpl}/oid", ['label' => 'Sinyal radio', 'oid' => '1.3.6.1.4.1.99999.1.2.0', 'mode' => 'get', 'class' => 'signal', 'scale' => 0.1, 'grp' => 'radio'])
            ->assertSessionHasNoErrors();
        $o = DB::table('template_oids')->where('template_id', $tpl)->first();
        $this->assertSame(['.1.3.6.1.4.1.99999.1.2.0', 'dBm'], [$o->oid, $o->unit]);
        $this->actingAs($eng)->get("/templat/{$tpl}")->assertSee('.1.3.6.1.4.1.99999.1.2.0')->assertSee('dikali 0,1');

        // ekspor/impor membawa OID templat
        $t = new \App\Services\ConfigTransfer;
        $file = $t->parse(json_encode($t->export(false, null)));
        DB::table('template_oids')->delete();
        $t->apply($file, null);
        $this->assertSame(1, DB::table('template_oids')->where('template_id', $tpl)->where('label', 'Sinyal radio')->count());

        // hapus: sensor yang sudah tersimpan di perangkat ikut hilang
        $oid = DB::table('template_oids')->where('template_id', $tpl)->value('id');
        DB::table('device_sensors')->insert(['device_id' => $dev, 'key' => "ox:{$oid}", 'class' => 'signal', 'grp' => 'radio', 'label' => 'Sinyal radio', 'value' => -65, 'status' => 'ok']);
        $this->actingAs($eng)->delete("/templat/{$tpl}/oid/{$oid}")->assertSessionHas('ok');
        $this->assertSame(0, DB::table('device_sensors')->where('device_id', $dev)->where('key', "ox:{$oid}")->count());
        $this->flushSession();
        $this->actingAs($this->user('viewer'))->post("/templat/{$tpl}/oid", ['label' => 'x'])->assertForbidden();
    }
}
