<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Problem dari engine collector. Web hanya membaca dan mencatat ACK. */
class Problem extends Model
{
    public $timestamps = false;

    public const SEVERITIES = [
        'disaster' => ['Disaster', 'crit', 4],
        'high' => ['High', 'crit', 3],
        'average' => ['Average', 'warn', 2],
        'warning' => ['Warning', 'warn', 1],
        'info' => ['Info', 'info', 0],
    ];

    public const KINDS = [
        'down' => 'Perangkat down',
        'loss' => 'Packet loss',
        'latency' => 'Latensi tinggi',
        'snmp' => 'SNMP tidak terbaca',
        'port_down' => 'Port putus',
        'port_util' => 'Port penuh',
        'sensor' => 'Sensor',
        'ip_conflict' => 'Konflik IP',
        'camera' => 'Gambar kamera',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'resolved_at' => 'datetime', 'acked_at' => 'datetime', 'last_value' => 'float'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function acker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acked_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    /** Urutan tampilan: belum ACK & tidak ditekan dulu, lalu severity tertinggi, lalu terbaru. */
    public function scopeTriage(Builder $q): Builder
    {
        return $q->orderByRaw("(acked_at IS NOT NULL OR suppressed IS NOT NULL), case severity when 'disaster' then 0 when 'high' then 1 when 'average' then 2 when 'warning' then 3 else 4 end, started_at desc");
    }

    public function sevLabel(): string
    {
        return self::SEVERITIES[$this->severity][0] ?? $this->severity;
    }

    public function sevTone(): string
    {
        return self::SEVERITIES[$this->severity][1] ?? 'muted';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function durationHuman(): string
    {
        return \App\Support\Fmt::duration((int) $this->started_at->diffInSeconds($this->resolved_at ?? now(), true));
    }

    public function incidents(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'incident_problems');
    }
}
