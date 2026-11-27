<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Akun yang dinonaktifkan langsung keluar, termasuk sesi "Ingat saya" yang masih berlaku (mis. PC layar NOC). */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if ($u && ! $u->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Akun dinonaktifkan.'], 401);
            }

            return redirect()->route('login')->withErrors(['username' => 'Akun Anda dinonaktifkan. Hubungi admin.']);
        }

        // Terakhir aktif, untuk tinjauan hak akses dan penonaktifan akun yang lama tidak dipakai (paling sering tiap 10 menit)
        if ($u && (! $u->last_seen_at || $u->last_seen_at->lt(now()->subMinutes(10)))) {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->update(['last_seen_at' => now()]);
            $u->last_seen_at = now();
        }

        return $next($request);
    }
}
