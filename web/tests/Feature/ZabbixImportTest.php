<?php

namespace Tests\Feature;

use App\Services\Collector;
use App\Services\ZabbixImport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZabbixImportTest extends TestCase
{
    use DatabaseTransactions;

    private function export(): string
    {
        $snmp = fn ($ip, $c, $v = 'SNMPV1') => [['type' => 'SNMP', 'ip' => $ip, 'port' => '161', 'details' => ['version' => $v, 'community' => $c], 'interface_ref' => 'if1']];
        $hosts = [
            ['host' => 'AP - CTN ENG', 'name' => 'AP - CTN ENG', 'templates' => [['name' => 'Ubiquiti AirOS by SNMP']], 'groups' => [['name' => 'ACCES POINT ALL AREA']], 'interfaces' => $snmp('10.91.0.1', 'ict')],
            ['host' => 'AP PTP - OFFICE TO COMBAT', 'name' => 'AP PTP - OFFICE TO COMBAT', 'templates' => [['name' => 'Ubiquiti AirOS by SNMP']], 'groups' => [['name' => 'Network Infras']], 'interfaces' => $snmp('10.91.0.2', '{$SNMP_COMMUNITY}')],
            ['host' => 'AP - RUIJIE', 'name' => 'AP - RUIJIE', 'templates' => [['name' => 'Generic by SNMP']], 'groups' => [], 'interfaces' => $snmp('10.91.0.3', '{$SNMP_COMMUNITY}'),
                'macros' => [['macro' => '{$SNMP_COMMUNITY}', 'value' => 'Ruijie123']]],
            ['host' => 'CCTV - GERBANG', 'name' => 'CCTV - GERBANG', 'templates' => [['name' => 'ICMP Ping']], 'groups' => [['name' => 'NVR - COMBAT 01']], 'interfaces' => $snmp('10.91.0.4', 'public'),
                'status' => 'DISABLED', 'description' => 'Dismantle'],
            ['host' => 'CCTV - POS 02', 'name' => 'CCTV - POS 02', 'templates' => [['name' => 'ICMP Ping']], 'groups' => [], 'interfaces' => $snmp('10.91.0.4', 'public')],
            ['host' => 'FORTIGATE', 'name' => 'FORTIGATE', 'templates' => [['name' => 'Template Net Fortinet FortiGate SNMP']], 'groups' => [['name' => 'Router']], 'interfaces' => $snmp('10.91.0.5', 'ict')],
            ['host' => 'SAP', 'name' => 'SAP', 'templates' => [['name' => 'ICMP Ping']], 'groups' => [['name' => 'SERVICE']], 'interfaces' => [['type' => 'AGENT', 'useip' => 'NO', 'dns' => 'sap.contoh.id', 'port' => '10050']]],
            ['host' => 'VM1', 'name' => 'VM1.AMM', 'templates' => [['name' => 'VMware Hypervisor']], 'groups' => [['name' => 'Hypervisors']], 'interfaces' => [['type' => 'AGENT']]],
        ];

        return json_encode(['zabbix_export' => ['version' => '7.4', 'hosts' => $hosts]]);
    }

    public function test_kategori(): void
    {
        $this->assertSame('ptp', ZabbixImport::category('STATION - PIT', ['Ubiquiti AirOS by SNMP']));
        $this->assertSame('ptp', ZabbixImport::category('EXCA - E508', ['Ubiquiti AirOS by SNMP']));
        $this->assertSame('wireless', ZabbixImport::category('AP - MESS BLOK B', ['Ubiquiti AirOS by SNMP']));
        $this->assertSame('cctv', ZabbixImport::category('CCTV - MESS 01', ['ICMP Ping']));
        $this->assertSame('nvr', ZabbixImport::category('MDVR CONNECTION', ['ICMP Ping']));
        $this->assertSame('distribution', ZabbixImport::category('CRS317 - CORE', ['MikroTik CRS317-1G-16SRM by SNMP']));
        $this->assertSame('core', ZabbixImport::category('CCR2116 - PIK', ['Mikrotik by SNMP']));
        $this->assertSame('access', ZabbixImport::category('USW - OFFICE', ['Unifi USW']));
        $this->assertSame('server', ZabbixImport::category('Server Synology', ['ICMP Ping']));
        $this->assertSame('other', ZabbixImport::category('WAN - LINTASMAYA', ['ICMP Ping']));
    }

    public function test_pratinjau_dan_impor(): void
    {
        $this->app->instance(Collector::class, new class extends Collector {
            public function pollNow(int $deviceId): bool { return true; }
        });
        $eng = $this->user('engineer');
        $site = DB::table('sites')->insertGetId(['code' => 'ZBX' . random_int(10, 99), 'name' => 'Site Zabbix']);
        DB::table('devices')->insert(['name' => 'Sudah ada', 'host' => '10.91.0.5']);
        $file = UploadedFile::fake()->createWithContent('zbx_export_hosts.json', $this->export());
        $r = $this->actingAs($eng)->post('/perangkat/impor/pratinjau', ['file' => $file, 'zbx_community' => 'RahasiaGlobal', 'site_id' => $site])->assertOk();
        $r->assertSee('host sudah terdaftar (Sudah ada)')->assertSee('IP sama dengan baris 5 (yang diimpor), dilewati')->assertSee('tanpa IP atau nama DNS')
            ->assertSee('nonaktif');
        $this->actingAs($eng)->post('/perangkat/impor')->assertRedirect();
        $d = fn ($n) => DB::table('devices')->where('name', $n)->first();
        $this->assertSame(['wireless', '1', true], [$d('AP - CTN ENG')->category, $d('AP - CTN ENG')->snmp_version, (bool) $d('AP - CTN ENG')->snmp_enabled]);
        $this->assertSame('ict', Crypt::decryptString($d('AP - CTN ENG')->snmp_community_enc));
        $this->assertSame('RahasiaGlobal', Crypt::decryptString($d('AP PTP - OFFICE TO COMBAT')->snmp_community_enc), 'makro global dari isian');
        $this->assertSame('Ruijie123', Crypt::decryptString($d('AP - RUIJIE')->snmp_community_enc), 'makro host menang');
        $this->assertFalse((bool) $d('CCTV - POS 02')->snmp_enabled, 'templat ICMP saja: ping');
        $this->assertNull($d('CCTV - GERBANG'), 'IP ganda: yang nonaktif dilewati');
        $this->assertSame('sap.contoh.id', $d('SAP')->host);
        $this->assertSame((int) $site, (int) $d('SAP')->site_id);
        $this->assertStringContainsString('Grup Zabbix: ACCES POINT ALL AREA', $d('AP - CTN ENG')->notes);
        $this->assertNull($d('VM1.AMM'));

        // tanpa nilai makro global: host bermakro jadi ping saja dengan peringatan
        $this->actingAs($eng)->post('/perangkat/impor/pratinjau', ['file' => UploadedFile::fake()->createWithContent('z.json', $this->export())])
            ->assertOk()->assertSee('community memakai makro {$SNMP_COMMUNITY}');
    }
}
