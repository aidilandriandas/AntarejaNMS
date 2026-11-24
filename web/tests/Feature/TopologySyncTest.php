<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TopologySyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sinkron_lldp_sekarang(): void
    {
        config(['antareja.collector_url' => 'http://collector.test:8081']);
        $eng = $this->user('engineer');
        $this->flushSession();

        Http::fake(['collector.test:8081/v1/topology/sync' => Http::sequence()
            ->push(['status' => 'dijadwalkan'], 202)
            ->push(['status' => 'sedang berjalan'], 409)
            ->push('', 500)]);
        $this->actingAs($eng)->from('/topologi')->post('/topologi/sinkron')->assertRedirect('/topologi')->assertSessionHas('ok', fn ($m) => str_starts_with($m, 'Sinkron dimulai'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/topology/sync') && $r->hasHeader('Authorization'));
        $this->actingAs($eng)->from('/topologi')->post('/topologi/sinkron')->assertSessionHas('ok', fn ($m) => str_starts_with($m, 'Putaran SNMP sedang berjalan'));
        $this->actingAs($eng)->from('/topologi')->post('/topologi/sinkron')->assertSessionHas('error');

        $this->actingAs($eng)->get('/topologi')->assertSee('Sinkron LLDP sekarang');
        $viewer = $this->user('viewer');
        $this->flushSession();
        $this->actingAs($viewer)->post('/topologi/sinkron')->assertForbidden();
        $this->actingAs($viewer)->get('/topologi')->assertDontSee('Sinkron LLDP sekarang')->assertSee('Peta beban')->assertSee('70 sampai 90%');
    }
}
