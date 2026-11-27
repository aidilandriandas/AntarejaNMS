<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Directory;
use App\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function setup()
    {
        return view('auth.setup');
    }

    public function storeSetup(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['username.regex' => 'Username hanya huruf kecil, angka, titik, garis bawah, atau strip.']);

        $user = DB::transaction(function () use ($data) {
            if (User::query()->lockForUpdate()->exists()) {
                abort(403, 'Setup sudah dilakukan.');
            }

            return User::create($data + ['role' => 'admin', 'active' => true]);
        });
        Cache::forever('antareja:has-users', true);
        Audit::log('setup', "Setup awal: akun admin {$user->username} dibuat", $user, null, $user->id);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('ok', 'Selamat datang di ANTAREJA NMS. Mulai dengan menambahkan site dan perangkat.');
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request, Directory $directory)
    {
        $cred = $request->validate(['username' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:1024']]);
        $username = Str::lower(trim($cred['username']));
        $key = 'login:' . $username . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan. Coba lagi dalam ' . RateLimiter::availableIn($key) . ' detik.']);
        }
        $user = $this->checkCredentials($username, $cred['password'], $directory);
        if (! $user) {
            RateLimiter::hit($key, 60);
            Audit::log('login_failed', 'Login gagal untuk username "' . mb_substr($cred['username'], 0, 64) . '"');
            throw ValidationException::withMessages(['username' => 'Username atau password salah, atau akun nonaktif.']);
        }
        RateLimiter::clear($key);
        if ($user->totp_enabled_at) {
            // Password benar; masih perlu kode dari aplikasi autentikator. Belum login sampai kode benar.
            $request->session()->regenerate();
            $request->session()->put('2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => time()]);

            return redirect()->route('login.2fa');
        }

        return $this->finishLogin($request, $user, $request->boolean('remember'));
    }

    /**
     * Akun lokal: password di database. Akun LDAP (atau username yang belum ada saat LDAP aktif): server direktori.
     * Akun admin lokal tetap bisa masuk walau server LDAP mati (akses darurat).
     */
    private function checkCredentials(string $username, string $password, Directory $directory): ?User
    {
        $user = User::where('username', $username)->first();
        if ($user && $user->auth_source === 'local') {
            try {
                return $user->active && Hash::check($password, $user->password) ? $user : null;
            } catch (\RuntimeException) {
                return null; // password tersimpan bukan bcrypt (mis. akun impor/contoh yang belum diberi password): tolak, jangan error 500
            }
        }
        if (! (Directory::config()['enabled'] ?? false)) {
            return null;
        }
        try {
            $d = $directory->authenticate($username, $password);
        } catch (\Throwable $e) {
            report($e);
            Audit::log('login_failed', 'Server LDAP tidak bisa dipakai saat login "' . mb_substr($username, 0, 64) . '": ' . mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! $d) {
            return null;
        }
        if (! $d['role']) {
            Audit::log('login_failed', "Login LDAP \"{$username}\" ditolak: bukan anggota grup ANTAREJA mana pun");

            return null;
        }
        if ($user && ! $user->active) {
            return null; // dinonaktifkan admin ANTAREJA: tetap ditolak walau akun direktori aktif
        }
        $user ??= new User(['username' => $username, 'active' => true]);
        $changes = $user->exists && $user->role !== $d['role'] ? ['role' => [$user->role, $d['role']]] : null;
        $user->forceFill(['name' => mb_substr($d['name'], 0, 128), 'email' => $d['email'] ? mb_substr($d['email'], 0, 255) : $user->email, 'role' => $d['role'],
            'auth_source' => 'ldap', 'ldap_dn' => mb_substr($d['dn'], 0, 500), 'password' => $user->password ?: Str::random(64)]);
        $new = ! $user->exists;
        $user->save();
        if ($new) {
            Audit::log('created', "Akun {$username} dibuat dari LDAP (peran {$d['role']})", $user, null, $user->id);
        } elseif ($changes) {
            Audit::log('updated', "Peran {$username} diperbarui dari grup LDAP", $user, $changes, $user->id);
        }

        return $user;
    }

    private function finishLogin(Request $request, User $user, bool $remember)
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget('2fa');
        $user->forceFill(['last_login_at' => now()])->save();
        $request->session()->put('last_active', time());
        Audit::log('login', "{$user->username} login" . ($user->totp_enabled_at ? ' (2FA)' : '') . ($user->auth_source === 'ldap' ? ' lewat LDAP' : ''));

        return redirect()->intended(route('dashboard'));
    }

    private function pending(Request $request): ?User
    {
        $p = $request->session()->get('2fa');
        if (! $p || time() - ($p['at'] ?? 0) > 300) {
            return null;
        }
        $u = User::find($p['id']);

        return $u && $u->active && $u->totp_enabled_at ? $u : null;
    }

    public function showTwoFactor(Request $request)
    {
        if (! $this->pending($request)) {
            return redirect()->route('login')->withErrors(['username' => 'Sesi login kedaluwarsa, ulangi dari awal.']);
        }

        return view('auth.two-factor');
    }

    /** Langkah kedua: kode 6 digit dari aplikasi autentikator, atau satu kode cadangan. */
    public function twoFactor(Request $request)
    {
        $user = $this->pending($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['username' => 'Sesi login kedaluwarsa, ulangi dari awal.']);
        }
        $request->validate(['code' => ['required', 'string', 'max:32']]);
        $key = '2fa:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Terlalu banyak percobaan. Coba lagi dalam ' . RateLimiter::availableIn($key) . ' detik.']);
        }
        $code = trim((string) $request->input('code'));
        $ok = false;
        if (preg_match('/^\d[\d ]{4,}\d$/', $code)) {
            // kunci baris supaya dua permintaan bersamaan tidak sama-sama menerima kode yang sama
            DB::transaction(function () use ($user, $code, &$ok) {
                $u = User::whereKey($user->id)->lockForUpdate()->first();
                $step = Totp::verify(Crypt::decryptString($u->totp_secret_enc), $code, $u->totp_last_step);
                if ($step !== null) {
                    $u->forceFill(['totp_last_step' => $step])->save();
                    $ok = true;
                }
            });
        } else {
            $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
            $codes = (array) ($user->totp_recovery ?? []);
            foreach ($codes as $i => $hash) {
                if ($norm !== '' && Hash::check($norm, $hash)) {
                    unset($codes[$i]);
                    $user->forceFill(['totp_recovery' => array_values($codes)])->save();
                    Audit::log('updated', "{$user->username} memakai kode cadangan 2FA (sisa " . count($codes) . ')', $user, null, $user->id);
                    $ok = true;
                    break;
                }
            }
        }
        if (! $ok) {
            RateLimiter::hit($key, 300);
            Audit::log('login_failed', "Kode 2FA salah untuk {$user->username}", $user, null, $user->id);
            throw ValidationException::withMessages(['code' => 'Kode salah atau sudah dipakai. Pastikan jam di HP tepat.']);
        }
        RateLimiter::clear($key);

        return $this->finishLogin($request, $user, (bool) $request->session()->get('2fa.remember'));
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            Audit::log('logout', "{$request->user()->username} logout");
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
