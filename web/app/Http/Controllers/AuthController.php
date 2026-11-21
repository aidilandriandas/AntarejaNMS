<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
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

    public function login(Request $request)
    {
        $cred = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);
        $key = 'login:' . Str::lower($cred['username']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan. Coba lagi dalam ' . RateLimiter::availableIn($key) . ' detik.']);
        }
        if (! Auth::attempt(['username' => Str::lower($cred['username']), 'password' => $cred['password'], 'active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            Audit::log('login_failed', 'Login gagal untuk username "' . mb_substr($cred['username'], 0, 64) . '"');
            throw ValidationException::withMessages(['username' => 'Username atau password salah, atau akun nonaktif.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('login', "{$user->username} login");

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
