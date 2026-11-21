<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kejadian perangkat (tabel events), ditulis collector. */
class DeviceEvent extends Model
{
    protected $table = 'events';

    public $timestamps = false;

    public const KINDS = [
        'down' => ['Down', 'crit', 'arrow-down'],
        'up' => ['Pulih', 'ok', 'arrow-up'],
        'reboot' => ['Reboot', 'warn', 'rotate'],
        'flapping' => ['Flapping', 'crit', 'wave'],
        'latency_high' => ['Latensi tinggi', 'warn', 'gauge'],
        'snmp_fail' => ['SNMP gagal', 'warn', 'plug-off'],
        'snmp_ok' => ['SNMP pulih', 'ok', 'plug'],
    ];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function meta(): array
    {
        return self::KINDS[$this->kind] ?? [$this->kind, 'muted', 'info'];
    }
}
