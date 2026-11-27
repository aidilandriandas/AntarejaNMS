<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AuthSettingsController;
use App\Services\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keluar otomatis bila sesi admin/engineer diam terlalu lama (ISO/IEC 27001:2022 A.8.5). Yang dihitung aktivitas
 * hanya membuka halaman dan mengirim form; permintaan latar belakang (polling layar, gambar CCTV, trafik live) tidak,
 * supaya halaman yang dibiarkan terbuka tetap berakhir. Viewer (layar NOC) tidak terkena.
 */
class IdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if (! $u || $u->role === 'viewer') {
            return $next($request);
        }
        $limit = AuthSettingsController::security()['idle_minutes'];
        if ($limit <= 0) {
            return $next($request);
        }
        $now = time();
        $last = (int) $request->session()->get('last_active', $now);
        if ($now - $last > $limit * 60) {
            Audit::log('logout', "{$u->username} keluar otomatis setelah diam lebih dari {$limit} menit");
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Sesi berakhir karena tidak ada aktivitas.'], 401);
            }

            return redirect()->route('login')->withErrors(['username' => "Sesi berakhir karena tidak ada aktivitas selama {$limit} menit. Silakan masuk lagi."]);
        }
        if (! $request->session()->has('last_active') || $this->isActivity($request)) {
            $request->session()->put('last_active', $now);
        }

        return $next($request);
    }

    private function isActivity(Request $r): bool
    {
        if (! $r->isMethod('GET') && ! $r->isMethod('HEAD')) {
            return true;
        }
        $mode = $r->headers->get('Sec-Fetch-Mode');

        return $mode !== null ? $mode === 'navigate' : ! $r->expectsJson();
    }
}
