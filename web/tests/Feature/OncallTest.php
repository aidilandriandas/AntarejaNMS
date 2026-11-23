<?php

namespace Tests\Feature;

use App\Models\OncallSlot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OncallTest extends TestCase
{
    use DatabaseTransactions;

    /** Ganti pengguna: sesi lama dibuang supaya AuthenticateSession tidak mengeluarkan pengguna baru. */
    private function as($u): static
    {
        static $last = null;
        if ($last !== $u->id) {
            $this->flushSession();
            $last = $u->id;
        }

        return $this->actingAs($u);
    }

    public function test_rotasi_dan_siapa_yang_piket(): void
    {
        DB::table('oncall_slots')->delete();
        $budi = $this->user('engineer', ['name' => 'Budi Piket', 'email' => 'budi@tambang.id']);
        $sari = $this->user('engineer', ['name' => 'Sari Piket', 'telegram_chat_id' => '5551234']);
        $andi = $this->user('engineer', ['name' => 'Andi Tanpa Kontak']);
        $eng = $this->user('engineer');
        $viewer = $this->user('viewer');
        $this->flushSession();

        // Viewer hanya melihat
        $this->as($viewer)->get('/piket')->assertOk()->assertSee('Jadwal piket')->assertDontSee('Buat rotasi');
        $this->as($viewer)->post('/piket/rotasi', [])->assertForbidden();

        // Rotasi 12 jam, 2 hari, mulai kemarin 07.00: 4 giliran Budi, Sari, Budi, Sari
        $start = now()->subDay()->startOfDay();
        $this->as($eng)->post('/piket/rotasi', ['people' => [$budi->id, $sari->id, ''], 'start_date' => $start->format('Y-m-d'),
            'start_hour' => 7, 'shift' => 12, 'days' => 2, 'replace' => 1])->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('ok', '4 giliran piket dibuat.');
        $slots = OncallSlot::orderBy('starts_at')->get();
        $this->assertCount(4, $slots);
        $this->assertSame([$budi->id, $sari->id, $budi->id, $sari->id], $slots->pluck('user_id')->all());
        $this->assertSame($start->copy()->setHour(7)->toIso8601String(), $slots[0]->starts_at->toIso8601String());
        $this->assertSame($start->copy()->addDays(2)->setHour(7)->toIso8601String(), $slots[3]->ends_at->toIso8601String());

        // Ganti rentang yang sama: yang lama dihapus, bukan ditumpuk
        $this->as($eng)->post('/piket/rotasi', ['people' => [$andi->id], 'start_date' => $start->format('Y-m-d'),
            'start_hour' => 7, 'shift' => 24, 'days' => 2, 'replace' => 1])->assertSessionHas('ok', '2 giliran piket dibuat.');
        $this->assertSame(2, OncallSlot::count());

        // Satu giliran manual yang sedang berjalan untuk Sari, ditumpuk dengan Andi
        $this->as($eng)->post('/piket', ['user_id' => $sari->id, 'starts_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHours(3)->format('Y-m-d\TH:i')])->assertSessionHas('ok');
        $cur = OncallSlot::current()->pluck('user.name')->sort()->values()->all();
        $this->assertSame(['Andi Tanpa Kontak', 'Sari Piket'], $cur);

        $page = $this->as($eng)->get('/piket')->assertOk();
        $page->assertSee('Buat rotasi')->assertSee('Andi Tanpa Kontak</b> punya giliran piket', false)->assertSee('bertugas');

        // Validasi: selesai sebelum mulai, lebih dari 7 hari, pengguna nonaktif
        $this->as($eng)->post('/piket', ['user_id' => $sari->id, 'starts_at' => '2026-12-02T10:00', 'ends_at' => '2026-12-02T09:00'])->assertSessionHasErrors('ends_at');
        $this->as($eng)->post('/piket', ['user_id' => $sari->id, 'starts_at' => '2026-12-01T10:00', 'ends_at' => '2026-12-10T10:00'])->assertSessionHasErrors('ends_at');
        $off = $this->user('engineer', ['active' => false]);
        $this->as($eng)->post('/piket', ['user_id' => $off->id, 'starts_at' => '2026-12-01T10:00', 'ends_at' => '2026-12-01T12:00'])->assertSessionHasErrors('user_id');

        // Hapus giliran
        $id = OncallSlot::where('user_id', $sari->id)->value('id');
        $this->as($eng)->delete("/piket/{$id}")->assertSessionHas('ok');
        $this->assertSame(['Andi Tanpa Kontak'], OncallSlot::current()->pluck('user.name')->all());

        // Layar NOC membawa nama yang piket
        $this->as($viewer)->getJson('/noc/data')->assertOk()->assertJsonPath('oncall.0.name', 'Andi Tanpa Kontak');
    }

    public function test_kontak_profil_dan_kanal_ke_piket(): void
    {
        $u = $this->user('viewer');
        $this->flushSession();
        $this->as($u)->put('/profil/kontak', ['email' => 'saya@tambang.id', 'telegram_chat_id' => '123456789', 'phone' => '+62 812 3456 7890'])->assertSessionHas('ok');
        $u->refresh();
        $this->assertSame(['saya@tambang.id', '123456789', '+62 812 3456 7890'], [$u->email, $u->telegram_chat_id, $u->phone]);
        $this->as($u)->put('/profil/kontak', ['telegram_chat_id' => '@budi'])->assertSessionHasErrors('telegram_chat_id');

        $admin = $this->user('admin');
        $this->as($admin)->post('/notifikasi', ['type' => 'email', 'name' => 'Email piket', 'min_severity' => 'high', 'delay_minutes' => 0,
            'cfg' => ['host' => 'smtp.tambang.id', 'port' => 587, 'security' => 'starttls', 'from' => 'nms@tambang.id', 'to' => 'noc@tambang.id'],
            'to_oncall' => 1, 'enabled' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue((bool) DB::table('notify_channels')->where('name', 'Email piket')->value('to_oncall'));
        $this->as($admin)->get('/notifikasi')->assertSee('yang sedang piket, cadangan noc@tambang.id');
    }
}
