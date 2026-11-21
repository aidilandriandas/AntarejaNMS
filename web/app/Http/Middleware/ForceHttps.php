<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bila APP_FORCE_HTTPS aktif, akses http:// dialihkan ke https:// (kecuali cek kesehatan /up). */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https') && ! $request->secure() && ! $request->is('up')) {
            $port = (int) config('app.https_port', 443);
            $host = $request->getHost() . ($port !== 443 ? ':' . $port : '');

            return redirect()->to('https://' . $host . $request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
