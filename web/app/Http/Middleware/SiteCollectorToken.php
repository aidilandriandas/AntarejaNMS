<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi collector site: "Authorization: Bearer acs_..." (token dibuat admin di menu Collector site,
 * disimpan sebagai hash SHA-256). Token salah, collector dinonaktifkan, atau dihapus: 401 dengan pesan yang sama.
 */
class SiteCollectorToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->bearerToken();
        $c = strlen($raw) === 52 && str_starts_with($raw, 'acs_')
            ? DB::table('site_collectors')->where('token_hash', hash('sha256', $raw))->where('enabled', true)->first() : null;
        if (! $c) {
            return response()->json(['error' => 'token tidak valid'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        DB::table('site_collectors')->where('id', $c->id)->update(['last_seen_at' => now(), 'last_ip' => $request->ip()]);
        $request->attributes->set('site_collector', $c);
        $res = $next($request);
        $res->headers->set('Cache-Control', 'no-store');

        return $res;
    }
}
