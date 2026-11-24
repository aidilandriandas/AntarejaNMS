<?php

namespace App\Http\Middleware;

use App\Support\SiteScope;
use Closure;
use Illuminate\Http\Request;

/** Akun terbatas site hanya boleh membuka halaman yang datanya ikut terfilter. */
class RestrictToSites
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && SiteScope::current() !== null) {
            $name = $request->route()?->getName();
            abort_unless($name && in_array($name, SiteScope::ALLOWED_ROUTES, true), 403, 'Akun ini dibatasi ke site tertentu dan tidak bisa membuka halaman ini.');
        }

        return $next($request);
    }
}
