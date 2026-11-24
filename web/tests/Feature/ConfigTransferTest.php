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

    public function test_collector_cek_runbook_dan_modbus_ikut(): void
    {
        $core = $this->isiData();
        $site = DB::table('sites')->where('code', 'PIT-A')->value('id');
        $col = DB::table('site_collectors')->insertGetId(['name' => 'collector-pit-a', 'site_id' => $site, 'token_hash' => str_repeat('f', 64), 'token_hint' => 'acs_lama']);
        DB::table('devices')->where('id', $core)->update(['collector_id' => $col, 'modbus_port' => 502, 'modbus_unit' => 2, 'exporter_url' => 'http://10.1.0.1:9100/metrics', 'exporter_services' => 'nginx']);
        DB::table('modbus_points')->insert([
            ['device_id' => $core, 'label' => 'Baterai', 'class' => 'voltage', 'unit' => 'V', 'fn' => 4, 'address' => 12548, 'scale' => 0.01, 'warn_low' => 12.2, 'position' => 0],
            ['device_id' => $core, 'label' => 'Suhu', 'class' => 'temperature', 'unit' => '°C', 'fn' => 3, 'address' => 10, 'scale' => 1, 'warn_low' => null, 'position' => 1],
        ]);
        DB::table('service_checks')->insert(['name' => 'Login FMS', 'device_id' => $core, 'type' => 'http', 'url' => 'https://fms.local/login', 'expect_text' => 'Masuk', 'severity' => 'average']);
        DB::table('runbooks')->insert(['title' => 'Core down', 'body' => "1. Cek listrik\n2. Telepon", 'problem_kind' => 'down', 'device_id' => $core]);

        $t = new ConfigTransfer;
        $raw = $t->export(false, null);
        $json = json_encode($raw);
        $this->assertStringNotContainsString(str_repeat('f', 64), $json, 'hash token collector tidak ikut');
        $file = $t->parse($json);

        DB::table('service_checks')->delete();
        DB::table('runbooks')->delete();
        DB::table('modbus_points')->delete();
        DB::table('devices')->update(['collector_id' => null, 'modbus_port' => null, 'exporter_url' => null, 'exporter_services' => null]);
        DB::table('site_collectors')->delete();

        $plan = $t->plan($file);
        $this->assertSame(1, $plan['site_collectors']['new']);
        $this->assertSame(2, $plan['modbus_points']['total']);
        $this->assertTrue(collect($plan['warnings'])->contains(fn ($w) => str_contains($w, 'Ganti token')));
        $res = $t->apply($file, null);
        $this->assertSame([1, 1, 1, 2], [$res['site_collectors'], $res['checks'], $res['runbooks'], $res['modbus_points']]);

        $newCol = DB::table('site_collectors')->where('name', 'collector-pit-a')->first();
        $this->assertNotSame(str_repeat('f', 64), $newCol->token_hash);
        $dev = DB::table('devices')->where('id', $core)->first();
        $this->assertSame([$newCol->id, 502, 2, 'http://10.1.0.1:9100/metrics', 'nginx'], [$dev->collector_id, $dev->modbus_port, $dev->modbus_unit, $dev->exporter_url, $dev->exporter_services]);
        $this->assertSame(['Baterai', 'Suhu'], DB::table('modbus_points')->where('device_id', $core)->orderBy('position')->pluck('label')->all());
        $this->assertSame('Masuk', DB::table('service_checks')->where('device_id', $core)->value('expect_text'));
        $this->assertSame($core, (int) DB::table('runbooks')->where('title', 'Core down')->value('device_id'));
        // impor ulang tidak menggandakan
        $t->apply($file, null);
        $this->assertSame([1, 1, 2], [DB::table('service_checks')->count(), DB::table('runbooks')->count(), DB::table('modbus_points')->count()]);
    }

    public function test_layanan_trigger_dan_kanal_piket_ikut(): void
    {
        $core = $this->isiData();
        $radio = (int) DB::table('devices')->where('name', 'Radio')->value('id');
        $sid = DB::table('services')->insertGetId(['name' => 'CCTV Pit A', 'target_pct' => 99.0, 'site_id' => DB::table('sites')->value('id')]);
        $c1 = DB::table('service_components')->insertGetId(['service_id' => $sid, 'name' => 'Uplink', 'min_up' => 1, 'position' => 0]);
        $c2 = DB::table('service_components')->insertGetId(['service_id' => $sid, 'name' => 'Radio', 'min_up' => 1, 'position' => 1]);
        DB::table('service_component_devices')->insert([['component_id' => $c1, 'device_id' => $core], ['component_id' => $c2, 'device_id' => $radio]]);
        $tid = DB::table('triggers')->insertGetId(['name' => 'Radio lambat', 'severity' => 'high', 'metric' => 'latency', 'op' => '>', 'threshold' => 80,
            'duration_min' => 10, 'scope' => 'devices', 'start_min' => 1080, 'end_min' => 360]);
        DB::table('trigger_devices')->insert(['trigger_id' => $tid, 'device_id' => $radio]);
        DB::table('notify_channels')->insert(['name' => 'Email piket', 'type' => 'email', 'to_oncall' => true,
            'config_enc' => Crypt::encryptString(json_encode(['host' => 'smtp', 'port' => 587, 'security' => 'starttls', 'from' => 'a@b.id', 'to' => 'noc@b.id']))]);
        DB::table('settings')->updateOrInsert(['key' => 'anomaly.problem_severity'], ['value' => '"average"']);

        $t = new ConfigTransfer;
        $file = $t->parse(json_encode($t->export(false, null)));
        $this->assertSame('CCTV Pit A', $file['services'][0]['name']);
        $this->assertSame([['name' => 'Radio', 'host' => '10.0.0.2']], $file['triggers'][0]['devices']);

        // Hapus lalu pulihkan
        DB::table('services')->delete();
        DB::table('triggers')->delete();
        DB::table('notify_channels')->where('name', 'Email piket')->update(['to_oncall' => false]);
        DB::table('settings')->where('key', 'anomaly.problem_severity')->update(['value' => '"off"']);
        $plan = $t->plan($file);
        $this->assertSame(['total' => 1, 'new' => 1, 'update' => 0], $plan['services']);
        $res = $t->apply($file, null);
        $this->assertSame(1, $res['services']);
        $this->assertSame(1, $res['triggers']);

        $sv = DB::table('services')->where('name', 'CCTV Pit A')->first();
        $this->assertEquals(99.0, (float) $sv->target_pct);
        $comps = DB::table('service_components')->where('service_id', $sv->id)->orderBy('position')->get();
        $this->assertSame(['Uplink', 'Radio'], $comps->pluck('name')->all());
        $this->assertSame([$radio], DB::table('service_component_devices')->where('component_id', $comps[1]->id)->pluck('device_id')->map(fn ($v) => (int) $v)->all());
        $tr = DB::table('triggers')->where('name', 'Radio lambat')->first();
        $this->assertSame(['high', 'latency', 1080, 360], [$tr->severity, $tr->metric, $tr->start_min, $tr->end_min]);
        $this->assertSame([$radio], DB::table('trigger_devices')->where('trigger_id', $tr->id)->pluck('device_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue((bool) DB::table('notify_channels')->where('name', 'Email piket')->value('to_oncall'));
        $this->assertSame('"average"', DB::table('settings')->where('key', 'anomaly.problem_severity')->value('value'));

        // Impor kedua tidak menggandakan komponen
        $t->apply($file, null);
        $this->assertSame(2, DB::table('service_components')->where('service_id', $sv->id)->count());
        $this->assertSame(1, DB::table('triggers')->where('name', 'Radio lambat')->count());
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
