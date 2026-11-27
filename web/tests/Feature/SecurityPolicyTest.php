<?php

namespace Tests\Feature;

use App\Http\Controllers\AuditController;
use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Kontrol ISO/IEC 27001:2022 yang didukung aplikasi: A.8.15 log audit, A.8.5 sesi, A.5.18 hak akses, A.5.10 pemberitahuan. */
class SecurityPolicyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_log_audit_tahan_ubah_saring_ekspor_dan_periksa(): void
    {
        $admin = $this->user('admin');
        Audit::log('updated', 'Ambang radio PTP-HAULING diubah', null, ['ssh_password_enc' => ['a', 'b'], 'cam_secret' => 'x', 'name' => ['A', 'B']]);
        $row = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertSame(64, strlen(trim($row->hash)));
        $ch = json_decode($row->changes, true);
        $this->assertSame(['(diubah)', '(diubah)', ['A', 'B']], [$ch['ssh_password_enc'], $ch['cam_secret'], $ch['name']]);

        // Database menolak perubahan dan penghapusan
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $row->id)->update(['description' => 'palsu'])), \Illuminate\Database\QueryException::class);
        $this->assertTrue(AuditController::verifyChain()['ok']);

        $this->actingAs($admin)->get('/audit?q=PTP-HAULING')->assertOk()->assertSee('Ambang radio PTP-HAULING diubah')->assertSee('Periksa keutuhan');
        $this->actingAs($admin)->get('/audit?q=tidak-ada-yang-cocok')->assertOk()->assertSee('Tidak ada catatan yang cocok');
        $csv = $this->actingAs($admin)->get('/audit/ekspor?q=PTP-HAULING')->assertOk()->streamedContent();
        $this->assertStringContainsString('hash_sebelumnya', $csv);
        $this->assertStringContainsString(trim($row->hash), $csv);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'exported')->where('description', 'like', 'Log audit diekspor (1 baris%')->exists());
        $this->actingAs($admin)->post('/audit/periksa')->assertSessionHas('ok', fn ($m) => str_starts_with($m, 'Log audit utuh'));

        // Viewer dan engineer tidak boleh membuka log audit
        $this->flushSession();
        $this->actingAs($this->user('engineer'))->get('/audit/ekspor')->assertForbidden();
    }

    public function test_keluar_otomatis_bila_diam_untuk_engineer_tidak_untuk_viewer(): void
    {
        Setting::put('auth.idle_minutes', 15);
        $eng = $this->user('engineer');
        $this->actingAs($eng)->withSession(['last_active' => time() - 10 * 60])->get('/perangkat')->assertOk();
        // polling latar belakang tidak memperpanjang sesi
        $this->actingAs($eng)->withSession(['last_active' => time() - 14 * 60])->withHeader('Sec-Fetch-Mode', 'cors')->getJson('/noc/state')->assertOk();
        $this->assertLessThan(time() - 13 * 60, session('last_active'));
        $this->actingAs($eng)->withSession(['last_active' => time() - 16 * 60])->get('/perangkat')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(DB::table('audit_logs')->where('action', 'logout')->where('description', 'like', '%diam lebih dari 15 menit%')->exists());

        $this->flushSession();
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->withSession(['last_active' => time() - 300 * 60])->get('/noc')->assertOk();
    }

    public function test_kebijakan_keamanan_pemberitahuan_login_dan_tinjauan_hak_akses(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->put('/pengguna/kebijakan-login/keamanan', ['idle_minutes' => 3, 'disable_inactive_days' => 10, 'audit_keep_days' => 100, 'login_notice' => ''])
            ->assertSessionHasErrors(['idle_minutes', 'disable_inactive_days', 'audit_keep_days']);
        $this->actingAs($admin)->put('/pengguna/kebijakan-login/keamanan', ['idle_minutes' => 20, 'disable_inactive_days' => 90, 'audit_keep_days' => 730,
            'login_notice' => 'Hanya untuk karyawan berwenang.'])->assertSessionHasNoErrors();
        $this->assertSame([20, 90, 730], [(int) Setting::get('auth.idle_minutes'), (int) Setting::get('auth.disable_inactive_days'), (int) Setting::get('audit.keep_days')]);
        $this->assertTrue(DB::table('audit_logs')->where('description', 'Kebijakan keamanan diubah')->exists());

        $old = $this->user('engineer');
        DB::table('users')->where('id', $old->id)->update(['last_login_at' => now()->subDays(120), 'last_seen_at' => now()->subDays(120)]);
        $this->actingAs($admin)->get('/pengguna')->assertOk()->assertSee('lebih dari 90 hari')->assertSee('Tinjauan hak akses');
        $csv = $this->actingAs($admin)->get('/pengguna/tinjauan')->assertOk()->streamedContent();
        $this->assertStringContainsString('hari tidak aktif', $csv);
        $this->assertStringContainsString($old->username, $csv);

        // diaktifkan lagi oleh admin: alasan hilang, dihitung aktif sejak sekarang
        DB::table('users')->where('id', $old->id)->update(['active' => false, 'disabled_reason' => 'Dinonaktifkan otomatis: tidak dipakai lebih dari 90 hari']);
        $this->actingAs($admin)->put("/pengguna/{$old->id}", ['name' => $old->name, 'username' => $old->username, 'role' => 'engineer', 'active' => 1])->assertSessionHasNoErrors();
        $fresh = DB::table('users')->find($old->id);
        $this->assertTrue((bool) $fresh->active);
        $this->assertNull($fresh->disabled_reason);

        $this->flushSession();
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Hanya untuk karyawan berwenang.');
    }
}
