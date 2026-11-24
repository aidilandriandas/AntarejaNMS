<?php

namespace Tests\Feature;

use App\Services\Collector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExporterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_atur_exporter_dan_uji_scrape(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'srv-fms', 'host' => '10.4.0.10', 'category' => 'server']);
        $eng = $this->user('engineer');
        $this->actingAs($eng)->get("/perangkat/{$dev}/exporter")->assertOk()->assertSee('http://10.4.0.10:9100/metrics');
        $this->actingAs($eng)->put("/perangkat/{$dev}/exporter", ['enabled' => 1, 'exporter_url' => 'http://admin:x@10.4.0.10:9100/metrics'])
            ->assertSessionHasErrors('exporter_url');
        $this->actingAs($eng)->put("/perangkat/{$dev}/exporter", ['enabled' => 1, 'exporter_url' => 'ftp://10.4.0.10/metrics'])->assertSessionHasErrors('exporter_url');
        $this->actingAs($eng)->put("/perangkat/{$dev}/exporter", ['enabled' => 1, 'exporter_services' => 'nginx; rm -rf', 'exporter_url' => 'http://10.4.0.10:9100/metrics'])
            ->assertSessionHasErrors('exporter_services');
        $this->actingAs($eng)->put("/perangkat/{$dev}/exporter", ['enabled' => 1, 'exporter_url' => 'http://10.4.0.10:9100/metrics', 'exporter_services' => "nginx,\npostgresql nginx"])
            ->assertSessionHasNoErrors();
        $d = DB::table('devices')->find($dev);
        $this->assertSame(['http://10.4.0.10:9100/metrics', 'nginx, postgresql'], [$d->exporter_url, $d->exporter_services]);

        DB::table('device_sensors')->insert(['device_id' => $dev, 'key' => 'px:svc:nginx', 'class' => 'service', 'grp' => 'sistem', 'label' => 'Layanan nginx',
            'text' => 'failed', 'status' => 'crit', 'updated_at' => now()]);
        $this->actingAs($eng)->get("/perangkat/{$dev}/exporter")->assertOk()->assertSee('Layanan nginx')->assertSee('failed');

        $this->app->instance(Collector::class, new class extends Collector {
            public function exporterTest(int $deviceId): array
            {
                return ['ok' => true, 'kind' => 'node', 'readings' => [['key' => 'px:mem', 'label' => 'Memori', 'unit' => '%', 'value' => 63.5]]];
            }
        });
        $this->actingAs($eng)->post("/perangkat/{$dev}/exporter/uji")->assertSessionHas('exporter_test');
        $this->actingAs($eng)->get("/perangkat/{$dev}/exporter")->assertSee('Hasil uji scrape')->assertSee('63,5 %');

        $this->actingAs($eng)->put("/perangkat/{$dev}/exporter", ['exporter_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('devices')->where('id', $dev)->value('exporter_url'));
        $this->assertSame(0, DB::table('device_sensors')->where('device_id', $dev)->where('key', 'like', 'px:%')->count());

        $this->flushSession();
        $this->actingAs($this->user('viewer'))->get("/perangkat/{$dev}/exporter")->assertForbidden();
    }
}
