<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLES = [
        'admin' => 'Admin',
        'engineer' => 'Engineer',
        'viewer' => 'Viewer (baca saja)',
    ];

    protected $fillable = ['name', 'username', 'email', 'telegram_chat_id', 'phone', 'password', 'role', 'active'];

    protected $hidden = ['password', 'remember_token', 'totp_secret_enc', 'totp_recovery', 'totp_last_step', 'ldap_dn'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'dashboard' => 'array',
            'totp_enabled_at' => 'datetime',
            'totp_recovery' => 'array',
        ];
    }

    /** Kebijakan auth.require_2fa mewajibkan 2FA untuk akun ini tetapi belum diaktifkan. */
    public function mustEnroll2fa(): bool
    {
        if ($this->totp_enabled_at) {
            return false;
        }

        return match ((string) Setting::get('auth.require_2fa', 'none')) {
            'all' => true,
            'admin' => $this->role === 'admin',
            default => false,
        };
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canManageDevices(): bool
    {
        return in_array($this->role, ['admin', 'engineer'], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /** Terakhir benar-benar dipakai: login lewat form atau sesi "Ingat saya". */
    public function lastActive(): ?\Illuminate\Support\Carbon
    {
        return collect([$this->last_login_at, $this->last_seen_at])->filter()->max();
    }
}
