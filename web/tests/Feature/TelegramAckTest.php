<?php

namespace Tests\Feature;

use App\Models\NotifyChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TelegramAckTest extends TestCase
{
    use DatabaseTransactions;

    public function test_kanal_telegram_dengan_tombol_ack(): void
    {
        $admin = $this->user('admin');
        $this->flushSession();
        $this->actingAs($admin)->get('/notifikasi/tambah?type=telegram')->assertOk()->assertSee('Tombol ACK di pesan problem');
        $base = ['type' => 'telegram', 'name' => 'Grup NOC', 'min_severity' => 'average', 'delay_minutes' => 0, 'enabled' => 1];
        $this->actingAs($admin)->post('/notifikasi', $base + ['cfg' => ['bot_token' => '123456:ABC-def_1', 'chat_id' => '-100123', 'ack_button' => '1']])->assertSessionHasNoErrors();
        $c = NotifyChannel::where('name', 'Grup NOC')->first();
        $this->assertSame('1', $c->config()['ack_button']);
        $this->assertSame('chat -100123, tombol ACK', $c->target());

        // Tanpa centang: tombol mati, token lama tetap
        $this->actingAs($admin)->put("/notifikasi/{$c->id}", $base + ['cfg' => ['chat_id' => '-100123']])->assertSessionHasNoErrors();
        $cfg = $c->fresh()->config();
        $this->assertSame('', $cfg['ack_button']);
        $this->assertSame('123456:ABC-def_1', $cfg['bot_token']);
        $this->actingAs($admin)->post('/notifikasi', $base + ['name' => 'X', 'cfg' => ['bot_token' => '1:a', 'chat_id' => '-1', 'ack_button' => 'ya']])->assertSessionHasErrors('cfg.ack_button');
    }
}
