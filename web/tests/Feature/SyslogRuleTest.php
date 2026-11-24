<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyslogRuleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_aturan_log_buat_uji_dan_problem(): void
    {
        $eng = $this->user('engineer');
        $dev = DB::table('devices')->insertGetId(['name' => 'rt-gerbang', 'host' => '10.81.0.1']);
        DB::table('syslog')->insert([
            ['received_at' => now()->subMinutes(5), 'device_id' => $dev, 'source' => '10.81.0.1', 'severity' => 4, 'message' => 'login failure for user admin from 10.9.0.7 via ssh'],
            ['received_at' => now()->subMinutes(4), 'device_id' => $dev, 'source' => '10.81.0.1', 'severity' => 6, 'message' => 'user admin logged in from 10.9.0.7'],
        ]);
        $base = ['name' => 'Gagal login', 'pattern' => 'login failure.*from \d+\.\d+', 'is_regex' => 1, 'max_severity' => 5, 'min_count' => 3,
            'window_m' => 1, 'clear_m' => 15, 'severity' => 'average', 'enabled' => 1];
        $this->actingAs($eng)->post('/log/aturan/uji', $base)->assertSessionHas('rule_test', fn ($t) => $t['count'] === 1 && $t['scanned'] === 1);
        $this->actingAs($eng)->get('/log/aturan')->assertOk()->assertSee('Hasil uji pola')->assertSee('login failure for user admin');
        $this->actingAs($eng)->post('/log/aturan', array_merge($base, ['pattern' => '(?<=x)y']))->assertSessionHasErrors('pattern');
        $this->actingAs($eng)->post('/log/aturan', array_merge($base, ['pattern' => '(abc']))->assertSessionHasErrors('pattern');
        $this->actingAs($eng)->post('/log/aturan', $base)->assertSessionHasNoErrors();
        $rule = DB::table('syslog_rules')->where('name', 'Gagal login')->first();
        $this->assertSame([60, 900, true], [$rule->window_s, $rule->clear_s, $rule->is_regex]);

        DB::table('syslog_rule_hits')->insert(['rule_id' => $rule->id, 'device_id' => $dev, 'fired_at' => now(), 'last_match_at' => now(), 'hits' => 3, 'last_message' => 'x']);
        $this->actingAs($eng)->get('/log/aturan')->assertOk()->assertSee('1 perangkat');
        $this->actingAs($eng)->get('/log')->assertOk()->assertSee('Aturan log');

        $this->flushSession();
        $this->actingAs($this->user('viewer'))->get('/log/aturan')->assertForbidden();
    }
}
