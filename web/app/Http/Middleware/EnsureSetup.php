<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Sebelum ada akun, semua halaman diarahkan ke Setup Awal; sesudahnya Setup Awal tertutup. */
class EnsureSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $hasUsers = Cache::get('antareja:has-users') ?: tap(User::query()->exists(), function ($v) {
            if ($v) {
                Cache::forever('antareja:has-users', true);
            }
        });
        $onSetup = $request->routeIs('setup', 'setup.store');
        if (! $hasUsers && ! $onSetup && ! $request->routeIs('health')) {
            return redirect()->route('setup');
        }
        if ($hasUsers && $onSetup) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
