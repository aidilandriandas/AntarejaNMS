<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi API baca-saja: header "Authorization: Bearer <token>". Token disimpan sebagai hash SHA-256.
 * Token dicabut, kedaluwarsa, atau dari IP yang tidak diizinkan ditolak dengan pesan yang sama (tidak membocorkan alasan).
 */
class ApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->bearerToken();
        $t = strlen($raw) === 48 && str_starts_with($raw, 'ant_') ? DB::table('api_tokens')->where('token_hash', hash('sha256', $raw))->first() : null;
        $ok = $t && ! $t->revoked_at && (! $t->expires_at || now()->lessThan($t->expires_at));
        if ($ok && $t->allowed_ips) {
            $ok = IpUtils::checkIp((string) $request->ip(), array_filter(array_map('trim', explode(',', $t->allowed_ips))));
        }
        if (! $ok) {
            return response()->json(['error' => 'token tidak valid'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        if ($request->method() !== 'GET') {
            return response()->json(['error' => 'API ini hanya baca (GET)'], 405, ['Allow' => 'GET']);
        }
        DB::table('api_tokens')->where('id', $t->id)->update(['last_used_at' => now(), 'last_ip' => $request->ip(), 'use_count' => DB::raw('use_count + 1')]);
        $request->attributes->set('api_token_id', $t->id);

        $res = $next($request);
        $res->headers->set('Cache-Control', 'no-store');
        $res->headers->set('X-Content-Type-Options', 'nosniff');

        return $res;
    }
}
