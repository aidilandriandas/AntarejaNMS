<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortErrorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_error_port_tampil_dan_bisa_jadi_trigger(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'Radio PTP Pit B', 'host' => '10.5.0.2', 'snmp_enabled' => true]);
        DB::table('interfaces')->insert([
            ['device_id' => $dev, 'if_index' => 2, 'name' => 'wlan1', 'oper_up' => true, 'admin_up' => true, 'err_rate' => 120.4, 'discard_rate' => 0.5],
            ['device_id' => $dev, 'if_index' => 3, 'name' => 'ether1', 'oper_up' => true, 'admin_up' => true, 'err_rate' => 0, 'discard_rate' => 0],
            ['device_id' => $dev, 'if_index' => 4, 'name' => 'ether2', 'oper_up' => true, 'admin_up' => true, 'err_rate' => null, 'discard_rate' => null],
        ]);
        $eng = $this->user('engineer');
        $eng->forceFill(['dashboard' => [['k' => 'porterrors', 'w' => 'half']]])->save();
        $this->flushSession();
        $this->actingAs($eng)->get("/perangkat/{$dev}")->assertOk()->assertSee('Error / discard')
            ->assertSee('<span class="txt-warn">120</span> / <span class="">0,5</span>', false);
        $this->actingAs($eng)->get('/')->assertOk()->assertSee('Port dengan error')->assertSee('Radio PTP Pit B, wlan1')->assertDontSee('Radio PTP Pit B, ether1');

        $this->actingAs($eng)->post('/trigger', ['name' => 'CRC radio', 'severity' => 'warning', 'metric' => 'port_errors', 'match_text' => 'wlan',
            'op' => '>', 'threshold' => 30, 'duration_min' => 10, 'scope' => 'all', 'days' => [0, 1, 2, 3, 4, 5, 6], 'enabled' => 1])->assertSessionHasNoErrors();
        $t = DB::table('triggers')->where('name', 'CRC radio')->first();
        $this->assertSame(['port_errors', 'wlan'], [$t->metric, $t->match_text]);
    }
}
