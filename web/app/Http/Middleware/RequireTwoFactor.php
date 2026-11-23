<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Kebijakan auth.require_2fa: akun yang wajib 2FA tetapi belum mengaktifkannya hanya bisa membuka Profil sampai aktif. */
class RequireTwoFactor
{
    private const ALLOWED = ['profile', 'profile.2fa.start', 'profile.2fa.enable', 'logout', 'help'];

    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if ($u && ! in_array($request->route()?->getName(), self::ALLOWED, true) && $u->mustEnroll2fa()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Aktifkan verifikasi dua langkah di Profil terlebih dahulu.'], 403);
            }

            return redirect()->to(route('profile') . '#dua-langkah');
        }

        return $next($request);
    }
}
