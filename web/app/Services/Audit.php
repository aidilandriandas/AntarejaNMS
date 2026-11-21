<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** Jejak audit perubahan. Kredensial (kolom *_enc, password) tidak pernah dicatat. */
class Audit
{
    private const SECRET_KEYS = ['password', 'remember_token', 'snmp_community_enc', 'snmp_v3_auth_enc', 'snmp_v3_priv_enc'];

    public static function log(string $action, string $description, ?Model $subject = null, ?array $changes = null, ?int $userId = null): void
    {
        if ($changes !== null) {
            foreach (self::SECRET_KEYS as $k) {
                if (array_key_exists($k, $changes)) {
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
            $out[$k] = in_array($k, self::SECRET_KEYS, true) ? '(diubah)' : [$m->getOriginal($k), $new];
        }

        return $out;
    }
}
