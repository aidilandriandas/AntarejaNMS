<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\ConfigTransfer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ConfigTransferTest extends TestCase
{
    use DatabaseTransactions;

    private function isiData(): int
    {
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-A', 'name' => 'Pit A', 'lat' => -1.2, 'lng' => 116.8]);
        $core = DB::table('devices')->insertGetId(['name' => 'Core', 'host' => '10.0.0.1', 'site_id' => $site, 'category' => 'core',
            'snmp_enabled' => true, 'snmp_community_enc' => Crypt::encryptString('komunitas-rahasia'), 'cfg_type' => 'mikrotik',
            'ssh_user' => 'nms', 'ssh_password_enc' => Crypt::encryptString('sandi-ssh-rahasia'), 'thresholds' => '{"latency_ms": 300}']);
        DB::table('devices')->insert(['name' => 'Radio', 'host' => '10.0.0.2', 'site_id' => $site, 'category' => 'ptp', 'parent_id' => $core]);
        DB::table('notify_channels')->insert(['name' => 'Tele NOC', 'type' => 'telegram',
            'config_enc' => Crypt::encryptString(json_encode(['bot_token' => '123:token-rahasia', 'chat_id' => '-100123']))]);

        return $core;
    }

    public function test_ekspor_tanpa_rahasia_tidak_membocorkan(): void
    {
        $this->isiData();
        $json = json_encode((new ConfigTransfer)->export(false, null));
        foreach (['komunitas-rahasia', 'sandi-ssh-rahasia', 'token-rahasia'] as $s) {
            $this->assertStringNotContainsString($s, $json);
        }
        $this->assertStringContainsString('-100123', $json); // non-rahasia tetap ikut
    }

    public function test_rahasia_terenkripsi_frasa_sandi(): void
    {
        $this->isiData();
        $data = (new ConfigTransfer)->export(true, 'frasa-sandi-panjang');
        $this->assertStringNotContainsString('sandi-ssh-rahasia', json_encode($data));
        $this->assertSame('AES-256-GCM', $data['secrets']['alg']);
        $open = ConfigTransfer::open($data['secrets'], 'frasa-sandi-panjang');
        $this->assertSame('sandi-ssh-rahasia', $open['devices']['Core|10.0.0.1']['ssh_password']);
        $this->expectException(RuntimeException::class);
        ConfigTransfer::open($data['secrets'], 'frasa-sandi-salah!');
    }

    public function test_frasa_sandi_pendek_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        (new ConfigTransfer)->export(true, 'pendek');
    }

    public function test_impor_memulihkan_tanpa_duplikat(): void
    {
        $core = $this->isiData();
        $t = new ConfigTransfer;
        $file = $t->parse(json_encode($t->export(true, 'frasa-sandi-panjang')));
        DB::table('sites')->where('code', 'PIT-A')->update(['name' => 'DIUBAH']);
        DB::table('devices')->where('id', $core)->update(['ssh_password_enc' => null, 'thresholds' => '{}']);
        DB::table('devices')->where('name', 'Radio')->update(['parent_id' => null]);

        $plan = $t->plan($file);
        $this->assertSame(['total' => 2, 'new' => 0, 'update' => 2], $plan['devices']);
        $t->apply($file, 'frasa-sandi-panjang');

        $this->assertSame('Pit A', DB::table('sites')->where('code', 'PIT-A')->value('name'));
        $this->assertSame(2, DB::table('devices')->count());
        $d = Device::find($core);
        $this->assertSame('sandi-ssh-rahasia', $d->secret('ssh_password_enc'));
        $this->assertSame(['latency_ms' => 300], $d->thresholds);
        $this->assertSame($core, (int) DB::table('devices')->where('name', 'Radio')->value('parent_id'));
    }

    public function test_frasa_salah_tidak_mengubah_apa_pun(): void
    {
        $this->isiData();
        $t = new ConfigTransfer;
        $file = $t->parse(json_encode($t->export(true, 'frasa-sandi-panjang')));
        DB::table('sites')->update(['name' => 'DIUBAH']);
        try {
            $t->apply($file, 'frasa-sandi-salah!');
            $this->fail('seharusnya ditolak');
        } catch (RuntimeException) {
        }
        $this->assertSame('DIUBAH', DB::table('sites')->value('name'));
    }

    public function test_baris_tidak_valid_dilewati(): void
    {
        $t = new ConfigTransfer;
        $file = $t->parse(json_encode(['format' => ConfigTransfer::FORMAT, 'version' => 1,
            'sites' => [['code' => 'site salah!', 'name' => 'x'], ['code' => 'jetty', 'name' => 'Jetty']],
            'devices' => [['name' => 'Ok', 'host' => '10.9.9.9', 'category' => 'access', 'site' => 'JETTY', 'snmp_version' => '9', 'snmp_v3_level' => 'hack'],
                ['name' => 'Buruk', 'host' => 'ada spasi', 'category' => 'access'], ['name' => 'Kategori', 'host' => '10.9.9.8', 'category' => 'roket']],
            'settings' => ['threshold.latency_ms' => 200, 'topology.positions' => [1, 2], 'app.key' => 'x']]));
        $res = $t->apply($file, null);
        $this->assertSame(3, $res['skipped']);
        $this->assertSame(['JETTY'], DB::table('sites')->pluck('code')->all());
        $ok = DB::table('devices')->where('name', 'Ok')->first();
        $this->assertSame('2c', $ok->snmp_version);
        $this->assertNull($ok->snmp_v3_level);
        $this->assertNull(DB::table('settings')->where('key', 'app.key')->value('value'));
        $this->assertSame('200', DB::table('settings')->where('key', 'threshold.latency_ms')->value('value'));
        $this->expectException(RuntimeException::class);
        $t->parse('{"halo": 1}');
    }

    public function test_hanya_admin(): void
    {
        $this->actingAs($this->user('engineer'))->post('/ekspor-impor/ekspor')->assertForbidden();
        $this->flushSession(); // sesi terikat ke hash password pengguna sebelumnya (AuthenticateSession)
        $this->actingAs($this->user('admin'))->post('/ekspor-impor/ekspor')->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
