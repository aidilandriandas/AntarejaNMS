<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua halaman. Respons yang sudah menetapkan header sendiri (mis. pratinjau laporan
 * dengan CSP sandbox) tidak ditimpa.
 *
 * Skrip hanya dari server sendiri (tanpa skrip inline / atribut on*). Gaya inline masih diizinkan (atribut style di
 * template & Leaflet). Gambar https diizinkan untuk tile peta dari server yang diatur admin.
 */
class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; worker-src 'self' blob:; "
        . "frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $res = $next($request);
        $h = $res->headers;
        $set = function (string $k, string $v) use ($h) {
            if (! $h->has($k)) {
                $h->set($k, $v);
            }
        };
        $set('X-Content-Type-Options', 'nosniff');
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Referrer-Policy', 'same-origin');
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $set('Cross-Origin-Opener-Policy', 'same-origin');
        $set('Content-Security-Policy', self::CSP);
        if ($request->isSecure()) {
            $set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $res;
    }
}
