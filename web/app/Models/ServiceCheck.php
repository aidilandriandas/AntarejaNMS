<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCheck extends Model
{
    public const TYPES = ['http' => 'HTTP(S)', 'tcp' => 'Port TCP', 'dns' => 'DNS'];

    public const INTERVALS = [30 => '30 detik', 60 => '1 menit', 120 => '2 menit', 300 => '5 menit', 900 => '15 menit', 3600 => '1 jam'];

    public const STATES = ['up' => ['Normal', 'ok'], 'slow' => ['Lambat', 'warn'], 'down' => ['Gagal', 'crit'], 'unknown' => ['Menunggu', '']];

    protected $fillable = ['name', 'device_id', 'type', 'url', 'port', 'query', 'expect_status', 'expect_text', 'expect_answer', 'verify_tls',
        'interval_s', 'timeout_s', 'slow_ms', 'cert_warn_days', 'severity', 'enabled'];

    protected function casts(): array
    {
        return ['verify_tls' => 'boolean', 'enabled' => 'boolean', 'last_at' => 'datetime', 'status_changed_at' => 'datetime',
            'cert_expires_at' => 'datetime', 'last_ms' => 'float'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Ringkasan sasaran, mis. "https://fms/login", "10.1.0.5 port 3306", "fms.local ke 10.1.0.2". */
    public function target(): string
    {
        return match ($this->type) {
            'http' => (string) $this->url,
            'tcp' => ($this->device?->host ?? '') . ' port ' . $this->port,
            'dns' => $this->query . ' ke ' . ($this->device?->host ?? '') . ($this->port ? ':' . $this->port : ''),
            default => '',
        };
    }

    public function certDaysLeft(): ?int
    {
        return $this->cert_expires_at ? (int) floor(now()->diffInHours($this->cert_expires_at, false) / 24) : null;
    }
}
