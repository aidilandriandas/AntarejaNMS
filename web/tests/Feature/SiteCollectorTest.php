<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SiteCollector;
use App\Services\Collector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteCollectorTest extends TestCase
{
    use DatabaseTransactions;

    private function create(): array
    {
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-S', 'name' => 'Pit Site']);
        $admin = $this->user('admin');
        $res = $this->actingAs($admin)->post('/collector-site', ['name' => 'collector-pit-s', 'site_id' => $site]);
        $c = SiteCollector::firstWhere('name', 'collector-pit-s');
        $res->assertRedirect(route('collectors.show', $c));
        $token = session('site_token');
        $this->assertStringStartsWith('acs_', $token);
        $this->assertSame(hash('sha256', $token), DB::table('site_collectors')->where('id', $c->id)->value('token_hash'));

        return [$c, $token, $site, $admin];
    }

    public function test_token_hanya_tampil_sekali_dan_halaman_hanya_admin(): void
    {
        [$c, $token, , $admin] = $this->create();
        $this->actingAs($admin)->get(route('collectors.show', $c))->assertOk()->assertSee($token);
        $this->actingAs($admin)->get(route('collectors.show', $c))->assertOk()->assertDontSee($token);
        $this->flushSession();
        $this->actingAs($this->user('engineer'))->get('/collector-site')->assertForbidden();
    }

    public function test_konfigurasi_hanya_perangkat_milik_collector(): void
    {
        [$c, $token, $site] = $this->create();
        $mine = DB::table('devices')->insertGetId(['name' => 'sw-site', 'host' => '10.60.0.1', 'site_id' => $site, 'collector_id' => $c->id,
            'snmp_enabled' => true, 'snmp_version' => '2c', 'snmp_community_enc' => Crypt::encryptString('RahasiaSite1')]);
        DB::table('devices')->insertGetId(['name' => 'sw-pusat', 'host' => '10.60.0.2', 'snmp_enabled' => true, 'snmp_version' => '2c',
            'snmp_community_enc' => Crypt::encryptString('RahasiaPusat1')]);
        $this->getJson('/api/site/v1/config', ['Authorization' => 'Bearer acs_' . str_repeat('x', 48)])->assertStatus(401);
        $r = $this->getJson('/api/site/v1/config', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertSame([$mine], array_column($r->json('devices'), 'id'));
        $this->assertSame('RahasiaSite1', $r->json('devices.0.snmp.community'));
        $this->assertStringNotContainsString('RahasiaPusat1', $r->getContent());
        $this->assertNotNull(DB::table('site_collectors')->where('id', $c->id)->value('last_seen_at'));
        // collector dinonaktifkan: ditolak
        DB::table('site_collectors')->where('id', $c->id)->update(['enabled' => false]);
        $this->getJson('/api/site/v1/config', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_hasil_diteruskan_ke_collector_pusat(): void
    {
        [$c, $token] = $this->create();
        $fake = new class extends Collector {
            public array $got = [];

            public function siteIngest(int $collectorId, string $body, array $headers): array
            {
                $this->got = [$collectorId, $body, $headers];

                return [200, ['pings' => 3, 'snmp' => 0, 'skipped' => 0]];
            }
        };
        $this->app->instance(Collector::class, $fake);
        $body = gzencode('{"pings":[]}');
        $this->call('POST', '/api/site/v1/results', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_X_ANTAREJA_QUEUED' => '7', 'HTTP_ACCEPT' => 'application/json'], $body)->assertOk()->assertJson(['pings' => 3]);
        $this->assertSame($c->id, $fake->got[0]);
        $this->assertSame($body, $fake->got[1]);
        $this->assertSame('gzip', $fake->got[2]['Content-Encoding']);
        $this->assertSame('7', $fake->got[2]['X-Antareja-Queued']);
        // API site tidak menerima metode/alamat lain
        $this->getJson('/api/site/v1/devices', ['Authorization' => "Bearer {$token}"])->assertNotFound();
    }

    public function test_pindah_perangkat_dan_hapus_collector_mengembalikan_ke_pusat(): void
    {
        [$c, , $site, $admin] = $this->create();
        $ids = [DB::table('devices')->insertGetId(['name' => 'a', 'host' => '10.61.0.1', 'site_id' => $site]),
            DB::table('devices')->insertGetId(['name' => 'b', 'host' => '10.61.0.2', 'site_id' => $site])];
        $this->actingAs($admin)->post(route('collectors.assign', $c))->assertSessionHas('ok');
        $this->assertSame(2, Device::whereIn('id', $ids)->where('collector_id', $c->id)->count());
        $this->actingAs($admin)->post('/perangkat/massal', ['ids' => [$ids[0]], 'action' => 'collector', 'collector_id' => ''])->assertSessionHas('ok');
        $this->assertNull(Device::find($ids[0])->collector_id);
        $this->actingAs($admin)->delete(route('collectors.destroy', $c))->assertRedirect(route('collectors.index'));
        $this->assertNull(Device::find($ids[1])->collector_id);
    }
}
