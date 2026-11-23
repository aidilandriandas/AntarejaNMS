<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_halaman_trap(): void
    {
        $dev = DB::table('devices')->insertGetId(['name' => 'sw-pit-a', 'host' => '10.9.0.1']);
        DB::table('traps')->insert([
            ['device_id' => $dev, 'source' => '10.9.0.1', 'version' => '2c', 'trap_oid' => '.1.3.6.1.6.3.1.1.5.3', 'name' => 'linkDown', 'severity' => 'average',
                'if_index' => 3, 'summary' => 'Port ether3 putus (linkDown)', 'varbinds' => json_encode([['oid' => '.1.3.6.1.2.1.2.2.1.1.3', 'value' => '3']])],
            ['device_id' => null, 'source' => '10.9.9.9', 'version' => '1', 'trap_oid' => '.1.3.6.1.4.1.39165.0.7', 'name' => null, 'severity' => 'info',
                'if_index' => null, 'summary' => 'Trap .1.3.6.1.4.1.39165.0.7: HDD error', 'varbinds' => '[]'],
        ]);
        $v = $this->user('viewer');
        $this->flushSession();
        $this->actingAs($v)->get('/trap')->assertOk()->assertSee('Port ether3 putus (linkDown)')->assertSee('HDD error')->assertSee('belum terdaftar')
            ->assertSee('.1.3.6.1.2.1.2.2.1.1.3')->assertDontSee('Simpan trap selama');
        $this->actingAs($v)->get('/trap?known=1')->assertSee('ether3')->assertDontSee('HDD error');
        $this->actingAs($v)->get('/trap?unknown=1')->assertDontSee('ether3')->assertSee('HDD error');
        $this->actingAs($v)->get('/trap?sev=average')->assertSee('ether3')->assertDontSee('HDD error');
        $this->actingAs($v)->get('/trap?q=hdd')->assertSee('HDD error')->assertDontSee('Port ether3');
        $this->actingAs($v)->get('/trap?q=.1.3.6.1.4.1.39165')->assertSee('HDD error');
        $this->actingAs($v)->post('/trap/pengaturan', ['keep' => 7])->assertForbidden();

        $a = $this->user('admin');
        $this->flushSession();
        $this->actingAs($a)->get('/trap')->assertSee('Simpan trap selama');
        $this->actingAs($a)->post('/trap/pengaturan', ['keep' => 7])->assertSessionHas('ok');
        $this->assertSame('7', DB::table('settings')->where('key', 'trap.keep_days')->value('value'));
        $this->actingAs($a)->post('/trap/pengaturan', ['keep' => 0])->assertSessionHasErrors('keep');
    }
}
