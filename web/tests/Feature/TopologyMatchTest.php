<?php

namespace Tests\Feature;

use App\Services\Topology;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopologyMatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tetangga_dengan_nama_perangkat_terdaftar_tidak_jadi_node_abu(): void
    {
        $r = DB::table('devices')->insertGetId(['name' => 'Router', 'host' => '10.5.0.1', 'category' => 'router']);
        $sw = DB::table('devices')->insertGetId(['name' => 'SW-RSERVERMESS', 'host' => '10.5.0.20', 'category' => 'switch']); // tanpa sysName
        $cam = DB::table('devices')->insertGetId(['name' => 'Kamera Gerbang', 'host' => '10.5.0.30', 'category' => 'camera']);
        DB::table('neighbors')->insert([
            ['device_id' => $r, 'local_if_index' => 2, 'protocol' => 'lldp', 'remote_name' => 'sw-rservermess.tambang.local', 'remote_ip' => null],
            ['device_id' => $r, 'local_if_index' => 3, 'protocol' => 'cdp', 'remote_name' => 'AP-LUAR', 'remote_ip' => '10.5.0.30'],
            ['device_id' => $r, 'local_if_index' => 4, 'protocol' => 'lldp', 'remote_name' => 'Belum-Terdaftar', 'remote_ip' => '10.5.9.9'],
        ]);

        $g = app(Topology::class)->graph();
        $ext = collect($g['nodes'])->where('state', 'external')->pluck('label')->all();
        $this->assertSame(['Belum-Terdaftar'], $ext);
        $pairs = collect($g['edges'])->where('external', '!=', true)->map(fn ($e) => [min($e['from'], $e['to']), max($e['from'], $e['to'])])->all();
        $this->assertContains([min($r, $sw), max($r, $sw)], $pairs);
        $this->assertContains([min($r, $cam), max($r, $cam)], $pairs); // cocok lewat IP
    }
}
