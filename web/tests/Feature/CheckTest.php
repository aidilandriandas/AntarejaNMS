<?php

namespace Tests\Feature;

use App\Models\ServiceCheck;
use App\Services\Collector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckTest extends TestCase
{
    use DatabaseTransactions;

    private function dev(): int
    {
        return DB::table('devices')->insertGetId(['name' => 'srv-fms', 'host' => '10.80.0.5']);
    }

    public function test_tambah_cek_http_dan_validasi(): void
    {
        $dev = $this->dev();
        $eng = $this->user('engineer');
        $base = ['name' => 'Login FMS', 'device_id' => $dev, 'type' => 'http', 'interval_s' => 60, 'timeout_s' => 10, 'severity' => 'high', 'enabled' => '1', 'verify_tls' => '1'];
        $this->actingAs($eng)->post('/cek-layanan', $base + ['url' => 'file:///etc/passwd'])->assertSessionHasErrors('url');
        $this->actingAs($eng)->post('/cek-layanan', $base + ['url' => 'https://fms.local/login', 'expect_status' => '2xx'])->assertSessionHasErrors('expect_status');
        $this->actingAs($eng)->post('/cek-layanan', $base + ['url' => 'https://fms.local/login', 'slow_ms' => 20000])->assertSessionHasErrors('slow_ms');
        $this->actingAs($eng)->post('/cek-layanan', $base + ['url' => 'https://fms.local/login', 'expect_status' => '200,302', 'port' => 22, 'query' => 'x.local'])
            ->assertRedirect(route('checks.index'));
        $c = ServiceCheck::firstWhere('name', 'Login FMS');
        $this->assertSame(['https://fms.local/login', null, null, 'unknown'], [$c->url, $c->port, $c->query, $c->status], 'kolom jenis lain dikosongkan');
        $this->assertTrue(DB::table('audit_logs')->where('subject_id', $c->id)->where('description', 'like', 'Cek layanan Login FMS%')->exists());
    }

    public function test_tcp_dns_dan_hak_akses(): void
    {
        $dev = $this->dev();
        $eng = $this->user('engineer');
        $base = ['device_id' => $dev, 'interval_s' => 60, 'timeout_s' => 5, 'severity' => 'average', 'enabled' => '1'];
        $this->actingAs($eng)->post('/cek-layanan', $base + ['name' => 'DB', 'type' => 'tcp'])->assertSessionHasErrors('port');
        $this->actingAs($eng)->post('/cek-layanan', $base + ['name' => 'DB', 'type' => 'tcp', 'port' => 3306])->assertRedirect();
        $this->actingAs($eng)->post('/cek-layanan', $base + ['name' => 'DNS', 'type' => 'dns', 'query' => 'fms tambang'])->assertSessionHasErrors('query');
        $this->actingAs($eng)->post('/cek-layanan', $base + ['name' => 'DNS', 'type' => 'dns', 'query' => 'fms.tambang.local', 'expect_answer' => '10.80.0.5'])->assertRedirect();
        $this->assertSame('10.80.0.5 port 3306', ServiceCheck::firstWhere('name', 'DB')->target());
        $this->flushSession();
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get('/cek-layanan')->assertOk()->assertSee('fms.tambang.local')->assertDontSee('Tambah cek');
        $this->actingAs($viewer)->get('/cek-layanan/tambah')->assertForbidden();
        $this->actingAs($viewer)->get('/perangkat/' . $dev)->assertOk()->assertSee('Cek layanan');
    }

    public function test_uji_sekarang_lewat_collector(): void
    {
        $dev = $this->dev();
        $id = DB::table('service_checks')->insertGetId(['name' => 'Web', 'device_id' => $dev, 'type' => 'http', 'url' => 'http://10.80.0.5/']);
        $this->app->instance(Collector::class, new class extends Collector {
            public function runCheck(int $checkId): array
            {
                return ['ok' => false, 'ms' => 12.5, 'error' => 'kode HTTP 503 (diharapkan 200-399)', 'status' => 503];
            }
        });
        $this->actingAs($this->user('engineer'))->post("/cek-layanan/{$id}/uji")->assertSessionHas('error', 'Cek Web gagal: kode HTTP 503 (diharapkan 200-399)');
    }
}
