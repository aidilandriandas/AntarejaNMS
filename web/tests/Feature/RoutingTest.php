<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoutingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_halaman_routing_dan_lupakan(): void
    {
        $core = DB::table('devices')->insertGetId(['name' => 'core-ho-x', 'host' => '10.0.9.1', 'category' => 'core']);
        $peer = DB::table('devices')->insertGetId(['name' => 'core-pit-x', 'host' => '10.0.9.2', 'category' => 'core']);
        DB::table('bgp_peers')->insert([
            ['device_id' => $core, 'remote_addr' => '10.0.9.2', 'remote_as' => 64512, 'local_as' => 65000, 'state' => 3, 'admin_status' => 2, 'since_s' => 600],
            ['device_id' => $core, 'remote_addr' => '10.0.9.3', 'remote_as' => 64513, 'local_as' => 65000, 'state' => 6, 'admin_status' => 2, 'since_s' => 90000],
        ]);
        DB::table('ospf_neighbors')->insert(['device_id' => $core, 'addr' => '10.1.9.2', 'router_id' => '2.2.2.2', 'state' => 8, 'missing' => true]);
        $v = $this->user('viewer');
        $this->actingAs($v)->get('/routing')->assertOk()->assertSeeInOrder(['10.0.9.2', 'core-pit-x', '64512', 'active', '10.0.9.3', 'established', '1 hari'])
            ->assertSee('1 dari 2 established')->assertSee('hilang')->assertDontSee('Lupakan');
        $this->actingAs($v)->get("/perangkat/{$core}")->assertOk()->assertSee('Sesi BGP')->assertSee('Tetangga OSPF');
        $this->actingAs($v)->delete("/perangkat/{$core}/routing", ['proto' => 'ospf', 'addr' => '10.1.9.2'])->assertForbidden();

        $this->flushSession();
        $eng = $this->user('engineer');
        $this->actingAs($eng)->get('/routing')->assertSee('Lupakan');
        $this->actingAs($eng)->delete("/perangkat/{$core}/routing", ['proto' => 'ospf', 'addr' => '10.1.9.2'])->assertRedirect();
        $this->assertSame(0, DB::table('ospf_neighbors')->where('device_id', $core)->count());
        $this->actingAs($eng)->delete("/perangkat/{$core}/routing", ['proto' => 'bgp', 'addr' => '10.9.9.9'])->assertNotFound();
    }
}
