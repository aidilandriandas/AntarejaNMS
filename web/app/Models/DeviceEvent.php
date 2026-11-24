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
        'config_change' => ['Konfigurasi berubah', 'info', 'edit'],
        'syslog' => ['Syslog kritis', 'crit', 'terminal'],
        'anomaly' => ['Anomali', 'warn', 'wave'],
        'trap' => ['SNMP trap', 'info', 'bell'],
        'check_down' => ['Cek gagal', 'crit', 'x'],
        'check_up' => ['Cek pulih', 'ok', 'check'],
        'check_slow' => ['Cek lambat', 'warn', 'gauge'],
        'firmware' => ['Versi berubah', 'info', 'rotate'],
        'routing' => ['Routing berubah', 'warn', 'share'],
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
