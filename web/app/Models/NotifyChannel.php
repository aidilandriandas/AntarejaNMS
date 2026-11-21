<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/** Kanal notifikasi. Konfigurasi (token bot, password SMTP, URL) disimpan terenkripsi di config_enc. */
class NotifyChannel extends Model
{
    public const TYPES = ['telegram' => 'Telegram', 'email' => 'Email (SMTP)', 'webhook' => 'Webhook (WhatsApp gateway, n8n, dll.)'];

    /** Kolom konfigurasi per jenis; yang bertanda true adalah rahasia (tidak pernah ditampilkan ulang). */
    public const FIELDS = [
        'telegram' => ['bot_token' => true, 'chat_id' => false],
        'email' => ['host' => false, 'port' => false, 'security' => false, 'username' => false, 'password' => true, 'from' => false, 'to' => false],
        'webhook' => ['url' => false, 'secret' => true],
    ];

    protected $fillable = ['name', 'type', 'min_severity', 'delay_minutes', 'notify_resolve', 'site_id', 'enabled'];

    protected $hidden = ['config_enc'];

    protected function casts(): array
    {
        return ['notify_resolve' => 'boolean', 'enabled' => 'boolean', 'delay_minutes' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'channel_id');
    }

    public function config(): array
    {
        return $this->config_enc ? (json_decode(Crypt::decryptString($this->config_enc), true) ?: []) : [];
    }

    public function setConfig(array $cfg): void
    {
        $this->config_enc = Crypt::encryptString(json_encode($cfg, JSON_UNESCAPED_SLASHES));
    }

    /** Konfigurasi aman untuk ditampilkan: rahasia diganti penanda. */
    public function publicConfig(): array
    {
        $cfg = $this->config();
        foreach (self::FIELDS[$this->type] ?? [] as $k => $secret) {
            if ($secret) {
                $cfg[$k] = ! empty($cfg[$k]) ? '••••••' : '';
            }
        }

        return $cfg;
    }

    public function target(): string
    {
        $c = $this->config();

        return match ($this->type) {
            'telegram' => 'chat ' . ($c['chat_id'] ?? '?'),
            'email' => $c['to'] ?? '?',
            'webhook' => parse_url($c['url'] ?? '', PHP_URL_HOST) ?: '?',
            default => '',
        };
    }
}
