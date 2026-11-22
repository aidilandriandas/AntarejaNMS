<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_setup_awal_hanya_sekali(): void
    {
        Cache::flush();
        // Belum ada akun: halaman mana pun berakhir di Setup Awal ('/' → login → setup)
        $this->followingRedirects()->get('/')->assertOk()->assertSee('name="password_confirmation"', false);
        $this->get('/login')->assertRedirect(route('setup'));
        $this->post('/setup', ['name' => 'Admin', 'username' => 'admin', 'password' => 'Rahasia12345', 'password_confirmation' => 'Rahasia12345'])
            ->assertRedirect(route('dashboard'));
        $this->assertSame('admin', User::firstWhere('username', 'admin')->role);
        auth()->logout();
        // setelah ada akun: setup tertutup, tidak bisa membuat admin kedua
        $this->get('/setup')->assertRedirect(route('login'));
        $this->post('/setup', ['name' => 'Penyusup', 'username' => 'penyusup', 'password' => 'Rahasia12345', 'password_confirmation' => 'Rahasia12345']);
        $this->assertNull(User::firstWhere('username', 'penyusup'));
    }

    public function test_login_dibatasi_setelah_5_kali_gagal(): void
    {
        $u = $this->user('viewer');
        RateLimiter::clear('login:' . $u->username . '|127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $u->username, 'password' => 'salah-salah'])->assertSessionHasErrors('username');
        }
        // password benar pun ditolak selama dibatasi
        $this->post('/login', ['username' => $u->username, 'password' => 'Rahasia12345'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_akun_nonaktif_langsung_keluar(): void
    {
        $u = $this->user('viewer');
        $this->actingAs($u)->get('/perangkat')->assertOk();
        $u->forceFill(['active' => false])->save();
        $this->actingAs($u)->get('/perangkat')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_header_keamanan(): void
    {
        $this->user('admin');
        $r = $this->get('/login');
        $r->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'same-origin');
        $csp = $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_tidak_ada_skrip_inline_di_template(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php')) {
                continue;
            }
            $src = file_get_contents($f->getPathname());
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $src, "Skrip inline di {$f->getFilename()}");
            $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|input|load|error)\s*=/i', $src, "Atribut on* di {$f->getFilename()}");
        }
    }
}
