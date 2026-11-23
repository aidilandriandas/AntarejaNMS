<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Directory;
use App\Support\Totp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_totp_sesuai_vektor_rfc6238(): void
    {
        $s = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
        $this->assertSame('12345678901234567890', Totp::unbase32($s));
        $this->assertSame('287082', Totp::code($s, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($s, intdiv(1111111109, 30)));
        $this->assertSame('005924', Totp::code($s, intdiv(1234567890, 30)));
        $this->assertSame(intdiv(59, 30), Totp::verify($s, '287 082', null, 59));
        $this->assertNull(Totp::verify($s, '287082', intdiv(59, 30), 59), 'kode yang sudah dipakai ditolak');
        $this->assertNull(Totp::verify($s, '000000', null, 59));
        $this->assertSame($s, Totp::base32(Totp::unbase32($s)));
    }

    private function enrolled(string $role = 'engineer'): array
    {
        $secret = Totp::secret();
        $u = $this->user($role);
        $u->forceFill(['totp_secret_enc' => Crypt::encryptString($secret), 'totp_enabled_at' => now(),
            'totp_recovery' => [\Illuminate\Support\Facades\Hash::make('AAAAABBBBB')]])->save();
        RateLimiter::clear('2fa:' . $u->id);

        return [$u, $secret];
    }

    public function test_login_dua_langkah_dan_kode_tidak_bisa_dipakai_ulang(): void
    {
        [$u, $secret] = $this->enrolled();
        $this->post('/login', ['username' => $u->username, 'password' => 'Rahasia12345'])->assertRedirect(route('login.2fa'));
        $this->assertGuest();
        $this->get('/perangkat')->assertRedirect(route('login'));
        $this->post('/login/verifikasi', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $code = Totp::code($secret, intdiv(time(), 30));
        $this->post('/login/verifikasi', ['code' => $code])->assertRedirect('/perangkat'); // kembali ke halaman yang tadi diminta
        $this->assertAuthenticatedAs($u);
        // kode yang sama ditolak di login berikutnya
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['username' => $u->username, 'password' => 'Rahasia12345']);
        $this->post('/login/verifikasi', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_kode_cadangan_sekali_pakai(): void
    {
        [$u] = $this->enrolled();
        $this->post('/login', ['username' => $u->username, 'password' => 'Rahasia12345']);
        $this->post('/login/verifikasi', ['code' => 'aaaaa-bbbbb'])->assertRedirect(route('dashboard'));
        $this->assertSame([], $u->fresh()->totp_recovery);
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['username' => $u->username, 'password' => 'Rahasia12345']);
        $this->post('/login/verifikasi', ['code' => 'AAAAA-BBBBB'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_langkah_kedua_tanpa_password_ditolak(): void
    {
        $this->user('admin'); // tanpa akun sama sekali semua halaman ke Setup Awal
        $this->get('/login/verifikasi')->assertRedirect(route('login'));
        $this->post('/login/verifikasi', ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_aktivasi_dari_profil(): void
    {
        $u = $this->user('viewer');
        $this->actingAs($u)->post('/profil/2fa/mulai')->assertRedirect();
        $secret = session('2fa_setup');
        $this->assertNotEmpty($secret);
        $this->get('/profil')->assertOk()->assertSee('data-otpauth="otpauth://totp/', false);
        $this->post('/profil/2fa/aktifkan', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/profil/2fa/aktifkan', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertSessionHas('recovery_codes');
        $u->refresh();
        $this->assertNotNull($u->totp_enabled_at);
        $this->assertSame($secret, Crypt::decryptString($u->totp_secret_enc));
        $this->assertCount(10, $u->totp_recovery);
        $this->assertArrayNotHasKey('totp_secret_enc', $u->toArray());
    }

    public function test_kebijakan_wajib_2fa(): void
    {
        $admin = $this->user('admin');
        // admin tanpa 2FA tidak bisa mewajibkan (supaya tidak mengunci diri)
        $this->actingAs($admin)->put('/pengguna/kebijakan-login/2fa', ['require_2fa' => 'all'])->assertSessionHas('error');
        Setting::put('auth.require_2fa', 'admin');
        $this->actingAs($admin)->get('/perangkat')->assertRedirect(route('profile') . '#dua-langkah');
        $this->actingAs($admin)->get('/profil')->assertOk()->assertSee('mewajibkan');
        $this->flushSession();
        $this->actingAs($this->user('viewer'))->get('/perangkat')->assertOk();
        [$enrolledAdmin] = $this->enrolled('admin');
        $this->flushSession();
        $this->actingAs($enrolledAdmin)->get('/perangkat')->assertOk();
        // tidak bisa dimatikan selagi wajib
        $this->actingAs($enrolledAdmin)->delete('/profil/2fa', ['code' => '000000'])->assertSessionHas('error');
        $this->assertNotNull($enrolledAdmin->fresh()->totp_enabled_at);
    }

    public function test_admin_reset_2fa_dan_hanya_admin(): void
    {
        [$u] = $this->enrolled('viewer');
        $this->actingAs($this->user('engineer'))->post("/pengguna/{$u->id}/reset-2fa")->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->user('admin'))->post("/pengguna/{$u->id}/reset-2fa")->assertSessionHas('ok');
        $this->assertNull($u->fresh()->totp_enabled_at);
        $this->assertNull($u->fresh()->totp_secret_enc);
    }

    private function fakeDirectory(?array $result): void
    {
        $this->user('admin'); // supaya tidak diarahkan ke Setup Awal
        Setting::put('auth.ldap', ['enabled' => true, 'host' => 'dc01', 'encryption' => 'ldaps', 'base_dn' => 'DC=x']);
        $this->app->instance(Directory::class, new class($result) extends Directory {
            public function __construct(private ?array $r) {}

            public function authenticate(string $username, string $password, bool $evenIfDisabled = false): ?array
            {
                return $password === 'BenarLdap1' ? $this->r : null;
            }
        });
    }

    public function test_login_ldap_membuat_akun_dengan_peran_dari_grup(): void
    {
        $this->fakeDirectory(['dn' => 'CN=Budi,DC=x', 'name' => 'Budi Santoso', 'email' => 'budi@x.local', 'role' => 'engineer']);
        $this->post('/login', ['username' => 'Budi', 'password' => 'salah'])->assertSessionHasErrors('username');
        $this->assertNull(User::firstWhere('username', 'budi'));
        RateLimiter::clear('login:budi|127.0.0.1');
        $this->post('/login', ['username' => 'Budi', 'password' => 'BenarLdap1'])->assertRedirect(route('dashboard'));
        $u = User::firstWhere('username', 'budi');
        $this->assertSame(['ldap', 'engineer', 'Budi Santoso'], [$u->auth_source, $u->role, $u->name]);
        $this->assertAuthenticatedAs($u);
        // password lokal acak tidak bisa dipakai, dan halaman ganti password ditolak
        $this->put('/profil/password', ['current_password' => 'x', 'password' => 'Baru123456', 'password_confirmation' => 'Baru123456'])->assertStatus(422);
    }

    public function test_ldap_tanpa_grup_ditolak_dan_admin_lokal_tetap_bisa_masuk(): void
    {
        $this->fakeDirectory(['dn' => 'CN=Tamu,DC=x', 'name' => 'Tamu', 'email' => null, 'role' => null]);
        $this->post('/login', ['username' => 'tamu', 'password' => 'BenarLdap1'])->assertSessionHasErrors('username');
        $this->assertNull(User::firstWhere('username', 'tamu'));
        // akun lokal tidak pernah dicek ke LDAP: password LDAP tidak berlaku untuknya
        $admin = $this->user('admin');
        $this->post('/login', ['username' => $admin->username, 'password' => 'BenarLdap1'])->assertSessionHasErrors('username');
        RateLimiter::clear('login:' . $admin->username . '|127.0.0.1');
        // akun lokal tanpa password bcrypt (data impor) ditolak biasa, bukan error 500
        $odd = $this->user('viewer');
        \Illuminate\Support\Facades\DB::table('users')->where('id', $odd->id)->update(['password' => 'x']);
        $this->post('/login', ['username' => $odd->username, 'password' => 'x'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => $admin->username, 'password' => 'Rahasia12345'])->assertRedirect(route('dashboard'));
    }

    public function test_setelan_ldap_menyimpan_password_terenkripsi(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->put('/pengguna/kebijakan-login/ldap', ['enabled' => '1', 'host' => 'dc01.kantor.local', 'encryption' => 'ldaps',
            'base_dn' => 'DC=kantor,DC=local', 'bind_dn' => 'CN=svc', 'bind_password' => 'RahasiaBind9', 'user_filter' => '(uid={username})', 'verify_cert' => '1'])
            ->assertSessionHas('ok');
        $this->assertTrue(Setting::get('auth.ldap')['enabled']);
        $enc = Setting::get('auth.ldap_bind_password');
        $this->assertSame('RahasiaBind9', Crypt::decryptString($enc));
        $this->actingAs($admin)->get('/pengguna/kebijakan-login')->assertOk()->assertDontSee('RahasiaBind9');
        // tanpa enkripsi harus dikonfirmasi
        $this->actingAs($admin)->put('/pengguna/kebijakan-login/ldap', ['enabled' => '1', 'host' => 'dc01', 'encryption' => 'none', 'base_dn' => 'DC=x'])->assertSessionHas('error');
        $this->flushSession();
        $this->actingAs($this->user('engineer'))->get('/pengguna/kebijakan-login')->assertForbidden();
    }
}
