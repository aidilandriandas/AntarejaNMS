<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotifyTemplateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_templat_pesan_kanal(): void
    {
        $admin = $this->user('admin');
        $base = ['type' => 'webhook', 'name' => 'Hook templat', 'min_severity' => 'high', 'delay_minutes' => 0, 'cfg' => ['url' => 'https://hook.tambang.id/x'], 'enabled' => 1];
        $this->actingAs($admin)->post('/notifikasi', $base + ['template_open' => "{severity} {judul}\nSite {site}", 'template_resolve' => '   '])
            ->assertSessionHasNoErrors()->assertRedirect();
        $c = DB::table('notify_channels')->where('name', 'Hook templat')->first();
        $this->assertSame(["{severity} {judul}\nSite {site}", null], [$c->template_open, $c->template_resolve]);
        $this->actingAs($admin)->get("/notifikasi/{$c->id}/ubah")->assertOk()->assertSee('Templat pesan')->assertSee('{severity} {judul}');
        $this->actingAs($admin)->post('/notifikasi', array_merge($base, ['name' => 'Panjang', 'template_open' => str_repeat('x', 2001)]))->assertSessionHasErrors('template_open');
    }
}
