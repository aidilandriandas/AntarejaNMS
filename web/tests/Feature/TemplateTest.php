<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_templat_makro_cek_exporter_dan_ambang(): void
    {
        $eng = $this->user('engineer');
        $a = DB::table('devices')->insertGetId(['name' => 'srv-fms-1', 'host' => '10.20.0.11', 'category' => 'server']);
        $b = DB::table('devices')->insertGetId(['name' => 'srv-fms-2', 'host' => '10.20.0.12', 'category' => 'server', 'exporter_url' => 'http://10.20.0.12:9200/metrics']);

        $this->actingAs($eng)->post('/templat', ['name' => 'Server FMS', 'macros' => "PORT_WEB=8080\nbad line"])->assertSessionHasErrors('macros');
        $this->actingAs($eng)->post('/templat', ['name' => 'Server FMS', 'macros' => "PORT_WEB=8080\n{\$PORT_DB}=5432", 'exporter_url' => 'http://{HOST}:9100/metrics',
            'exporter_services' => 'nginx', 'thr' => ['cpu' => ['warn' => 70, 'crit' => 90], 'latency_ms' => 50]])->assertSessionHasNoErrors();
        $t = DB::table('device_templates')->where('name', 'Server FMS')->first();
        $this->assertEqualsCanonicalizing(['{$PORT_WEB}' => '8080', '{$PORT_DB}' => '5432'], json_decode($t->macros, true));
        $this->actingAs($eng)->post("/templat/{$t->id}/cek", ['name' => 'Web {NAME}', 'type' => 'http', 'url' => 'http://{HOST}:{$PORT_WEB}/login', 'expect_status' => '200-399',
            'interval_s' => 60, 'timeout_s' => 10, 'severity' => 'high', 'enabled' => 1, 'verify_tls' => 1])->assertSessionHasNoErrors();
        $this->actingAs($eng)->post("/templat/{$t->id}/cek", ['name' => 'DB', 'type' => 'tcp', 'port' => '{$PORT_DB}', 'interval_s' => 60, 'timeout_s' => 10, 'severity' => 'average', 'enabled' => 1])->assertSessionHasNoErrors();
        $this->actingAs($eng)->post("/templat/{$t->id}/cek", ['name' => 'Aneh', 'type' => 'tcp', 'port' => '{$X}{$Y}', 'interval_s' => 60, 'timeout_s' => 10, 'severity' => 'average', 'enabled' => 1])->assertSessionHasErrors('port');

        // pasang lewat ubah massal; srv-fms-2 menimpa port web lewat makro perangkat
        DB::table('devices')->where('id', $b)->update(['macros' => json_encode(['{$PORT_WEB}' => '8443'])]);
        $this->actingAs($eng)->post('/perangkat/massal', ['ids' => [$a, $b], 'action' => 'template', 'template_id' => $t->id])->assertSessionHas('ok');
        $urls = DB::table('service_checks')->whereIn('device_id', [$a, $b])->whereNotNull('template_check_id')->orderBy('device_id')->orderBy('type')->get(['device_id', 'name', 'url', 'port']);
        $this->assertSame(4, $urls->count());
        $this->assertSame(['Web srv-fms-1', 'http://10.20.0.11:8080/login'], [$urls[0]->name, $urls[0]->url]);
        $this->assertSame(5432, (int) $urls[1]->port);
        $this->assertSame('http://10.20.0.12:8443/login', $urls[2]->url);
        // exporter: srv-fms-1 diisi dari templat, srv-fms-2 tetap URL sendiri
        $this->assertSame('http://10.20.0.11:9100/metrics', DB::table('devices')->where('id', $a)->value('exporter_url'));
        $this->assertSame('http://10.20.0.12:9200/metrics', DB::table('devices')->where('id', $b)->value('exporter_url'));

        // ubah cek templat: ikut berubah di perangkat; hapus: ikut hilang
        $web = DB::table('template_checks')->where('template_id', $t->id)->where('type', 'http')->value('id');
        $this->actingAs($eng)->put("/templat/{$t->id}/cek/{$web}", ['name' => 'Web {NAME}', 'type' => 'http', 'url' => 'http://{HOST}:{$PORT_WEB}/dispatch',
            'interval_s' => 300, 'timeout_s' => 10, 'severity' => 'high', 'enabled' => 1])->assertSessionHasNoErrors();
        $this->assertSame('http://10.20.0.11:8080/dispatch', DB::table('service_checks')->where('device_id', $a)->where('template_check_id', $web)->value('url'));
        $this->actingAs($eng)->delete("/templat/{$t->id}/cek/{$web}")->assertRedirect();
        $this->assertSame(2, DB::table('service_checks')->whereIn('device_id', [$a, $b])->count());

        // ambang efektif: templat lalu ditimpa perangkat
        DB::table('devices')->where('id', $a)->update(['thresholds' => json_encode(['cpu' => [60, 80]])]);
        $dev = \App\Models\Device::find($a);
        $this->assertEquals(['cpu' => [60, 80], 'latency_ms' => 50], $dev->effectiveThresholds());
        $this->actingAs($eng)->get("/perangkat/{$a}/ubah")->assertOk()->assertSee('Server FMS')->assertSee('templat 50');
        $this->actingAs($eng)->get("/templat/{$t->id}")->assertOk()->assertSee('srv-fms-2');

        // trigger cakupan templat
        $this->actingAs($eng)->post('/trigger', ['name' => 'CPU templat', 'severity' => 'average', 'metric' => 'latency', 'op' => '>', 'threshold' => 100, 'duration_min' => 5,
            'scope' => 'template', 'template_id' => $t->id, 'days' => [0, 1, 2, 3, 4, 5, 6], 'enabled' => 1])->assertSessionHasNoErrors();
        $this->assertSame((int) $t->id, (int) DB::table('triggers')->where('name', 'CPU templat')->value('template_id'));

        // ekspor lalu impor: templat, makro perangkat, dan aturan log ikut; cek dari templat tidak ganda
        DB::table('syslog_rules')->insert(['name' => 'QA login', 'pattern' => 'login failure', 'min_count' => 3]);
        $tr = new \App\Services\ConfigTransfer;
        $file = $tr->parse(json_encode($tr->export(false, null)));
        $this->assertSame(0, collect($file['checks'])->count(), 'cek dari templat tidak diekspor terpisah');
        DB::table('syslog_rules')->delete();
        DB::table('template_checks')->where('template_id', $t->id)->update(['name' => 'DB lama']);
        $res = $tr->apply($file, null);
        $this->assertSame([1, 1], [$res['templates'], $res['syslog_rules']]);
        $this->assertSame('DB', DB::table('template_checks')->where('template_id', $t->id)->value('name'));
        $this->assertSame(2, DB::table('service_checks')->whereIn('device_id', [$a, $b])->count());
        $this->assertSame(['{$PORT_WEB}' => '8443'], json_decode(DB::table('devices')->where('id', $b)->value('macros'), true));

        // hapus templat: cek & exporter dari templat dilepas
        $this->actingAs($eng)->delete("/templat/{$t->id}")->assertRedirect();
        $this->assertSame(0, DB::table('service_checks')->whereIn('device_id', [$a, $b])->count());
        $this->assertNull(DB::table('devices')->where('id', $a)->value('exporter_url'));
        $this->assertSame('http://10.20.0.12:9200/metrics', DB::table('devices')->where('id', $b)->value('exporter_url'));

        $this->flushSession();
        $this->actingAs($this->user('viewer'))->get('/templat')->assertForbidden();
    }
}
