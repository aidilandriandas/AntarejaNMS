<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortFlapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_riwayat_putus_sambung_dan_ambang(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'Radio Pit A', 'host' => '10.6.0.1', 'snmp_enabled' => true]);
        DB::table('interfaces')->insert(['device_id' => $dev, 'if_index' => 2, 'name' => 'wlan1', 'oper_up' => true, 'admin_up' => true]);
        DB::table('port_changes')->insert([
            ['device_id' => $dev, 'if_index' => 2, 'up' => false, 'source' => 'trap', 'at' => now()->subMinutes(9)],
            ['device_id' => $dev, 'if_index' => 2, 'up' => true, 'source' => 'trap', 'at' => now()->subMinutes(8)],
            ['device_id' => $dev, 'if_index' => 2, 'up' => true, 'source' => 'poll', 'at' => now()->subMinutes(7)],
            ['device_id' => $dev, 'if_index' => 2, 'up' => false, 'source' => 'poll', 'at' => now()->subDays(9)],
        ]);
        $v = $this->user('viewer');
        $this->flushSession();
        $r = $this->actingAs($v)->get("/perangkat/{$dev}/port/2")->assertOk()->assertSee('Riwayat putus-sambung')
            ->assertSee('2 perubahan dalam 24 jam')->assertSee('dari trap perangkat')->assertSee('terlihat saat polling');
        $this->assertSame(3, substr_count($r->getContent(), '<b>Tersambung</b>') + substr_count($r->getContent(), '<b>Putus</b>'), 'yang lebih dari 7 hari tidak tampil');

        $a = $this->user('admin');
        $this->flushSession();
        $this->actingAs($a)->get('/notifikasi')->assertSee('Port naik-turun (kali)');
        $this->actingAs($a)->put('/ambang', ['latency_ms' => 150, 'loss_pct' => 20, 'util_pct' => 90, 'snmp_fail_cycles' => 2, 'flap_changes' => 6, 'flap_minutes' => 30])
            ->assertSessionHas('ok');
        $this->assertSame(['6', '30'], [DB::table('settings')->where('key', 'threshold.flap_changes')->value('value'), DB::table('settings')->where('key', 'threshold.flap_minutes')->value('value')]);
        $this->actingAs($a)->put('/ambang', ['latency_ms' => 150, 'loss_pct' => 20, 'util_pct' => 90, 'snmp_fail_cycles' => 2, 'flap_changes' => 1, 'flap_minutes' => 30])
            ->assertSessionHasErrors('flap_changes');
    }
}
