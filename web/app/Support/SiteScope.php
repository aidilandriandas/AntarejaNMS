<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pembatasan akun ke site tertentu. Akun terbatas hanya melihat perangkat (dan problem, sensor, kejadian
 * perangkat itu) di site-nya, dan hanya membuka halaman di ALLOWED_ROUTES. Admin tidak bisa dibatasi.
 */
class SiteScope
{
    /** Halaman yang boleh dibuka akun terbatas; semuanya sudah difilter lewat scope model. */
    public const ALLOWED_ROUTES = [
        'dashboard', 'dashboard.layout', 'devices.index', 'devices.show', 'devices.metrics', 'ports.show', 'ports.data',
        'problems.index', 'problems.ack', 'problems.unack', 'sensors.index', 'events.index', 'help',
        'profile', 'profile.contact', 'profile.password', 'profile.2fa.start', 'profile.2fa.enable', 'profile.2fa.disable', 'profile.2fa.recovery',
        'logout', 'login.2fa', 'login.2fa.store',
    ];

    private static array $cache = [];

    /** @return int[]|null null = tidak dibatasi */
    public static function for(?User $u): ?array
    {
        if (! $u || $u->role === 'admin') {
            return null;
        }
        if (! array_key_exists($u->id, self::$cache)) {
            $ids = DB::table('user_sites')->where('user_id', $u->id)->pluck('site_id')->map(fn ($v) => (int) $v)->all();
            self::$cache[$u->id] = $ids ?: null;
        }

        return self::$cache[$u->id];
    }

    public static function current(): ?array
    {
        return Auth::hasUser() ? self::for(Auth::user()) : null;
    }

    /** Potongan SQL " AND kolom IN (..)" untuk query mentah; kosong bila tidak dibatasi. Isinya hanya angka. */
    public static function sqlIn(string $column): string
    {
        $ids = self::current();

        return $ids === null ? '' : ' AND ' . $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
    }

    public static function forget(int $userId): void
    {
        unset(self::$cache[$userId]);
    }
}
