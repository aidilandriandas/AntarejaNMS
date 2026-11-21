<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pembacaan sensor dari profil perangkat (ditulis collector, hanya dibaca web). */
class DeviceSensor extends Model
{
    protected $table = 'device_sensors';

    public $incrementing = false;

    public $timestamps = false;

    public const GROUPS = ['radio' => 'Radio / wireless', 'daya' => 'Daya & UPS', 'sistem' => 'Sistem', 'lingkungan' => 'Suhu & lingkungan'];

    public const PROFILES = [
        'host-resources' => 'HOST-RESOURCES', 'entity-sensor' => 'ENTITY-SENSOR', 'mikrotik' => 'MikroTik RouterOS',
        'ubiquiti-airmax' => 'Ubiquiti airMAX', 'cisco' => 'Cisco IOS', 'ups-mib' => 'UPS-MIB', 'apc' => 'APC PowerNet',
    ];

    protected function casts(): array
    {
        return ['value' => 'float', 'updated_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function display(): string
    {
        if ($this->text) {
            return $this->text;
        }
        if ($this->value === null) {
            return '–';
        }
        $v = abs($this->value) >= 100 || floor($this->value) == $this->value
            ? number_format($this->value, 0, ',', '.')
            : number_format($this->value, 1, ',', '.');

        return trim($v . ' ' . $this->unit);
    }

    public function tone(): string
    {
        return ['ok' => 'ok', 'warn' => 'warn', 'crit' => 'crit'][$this->status] ?? 'muted';
    }

    public function stale(): bool
    {
        return $this->updated_at && $this->updated_at->lt(now()->subMinutes(20));
    }
}
