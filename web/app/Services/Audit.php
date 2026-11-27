<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** Jejak audit perubahan (ISO/IEC 27001 A.8.15). Kredensial (kolom *_enc, password, secret, token) tidak pernah dicatat. */
class Audit
{
    private const SECRET_KEYS = ['password', 'remember_token', 'snmp_community_enc', 'snmp_v3_auth_enc', 'snmp_v3_priv_enc'];

    /** Kolom rahasia: daftar tetap, berakhiran _enc, atau mengandung password, secret, token. */
    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true) || str_ends_with($key, '_enc') || (bool) preg_match('/pass|secret|token|totp_recovery/i', $key);
    }

    public static function log(string $action, string $description, ?Model $subject = null, ?array $changes = null, ?int $userId = null): void
    {
        if ($changes !== null) {
            foreach (array_keys($changes) as $k) {
                if (is_string($k) && self::isSecret($k)) {
                    $changes[$k] = '(diubah)';
                }
            }
        }
        AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 500),
            'changes' => $changes,
            'ip_address' => Request::ip(),
            'at' => now(),
        ]);
    }

    /** Selisih atribut model sebelum simpan: ['kolom' => [lama, baru]]. */
    public static function diff(Model $m): array
    {
        $out = [];
        foreach ($m->getDirty() as $k => $new) {
            if (in_array($k, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $out[$k] = self::isSecret($k) ? '(diubah)' : [$m->getOriginal($k), $new];
        }

        return $out;
    }
}
