<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * Perangkat yang dipantau. Kolom status_* , last_*, sys_* , uptime_seconds ditulis oleh collector.
 * Kolom *_enc berisi kredensial terenkripsi (Crypt::encryptString, kunci APP_KEY = ANTAREJA_APP_KEY)
 * dan tidak pernah dikirim ke tampilan maupun browser.
 */
class Device extends Model
{
    public const CATEGORIES = [
        'core' => 'Core Switch / Router',
        'distribution' => 'Distribution Switch',
        'access' => 'Access Switch',
        'wireless' => 'Access Point / Wireless',
        'ptp' => 'Radio PTP / PTMP',
        'firewall' => 'Firewall',
        'server' => 'Server',
        'nvr' => 'NVR',
        'cctv' => 'Kamera CCTV',
        'ups' => 'UPS',
        'other' => 'Lainnya',
    ];

    /** Ambang yang bisa diatur per perangkat: kunci => [label, satuan, bawaan, jenis (single|pair)] */
    public const THRESHOLDS = [
        'latency_ms' => ['Latensi tinggi', 'ms', '150', 'single'],
        'loss_pct' => ['Packet loss', '%', '20', 'single'],
        'util_pct' => ['Port dipantau penuh', '%', '90', 'single'],
        'signal' => ['Sinyal radio', 'dBm', '-70 / -78', 'pair'],
        'ccq' => ['CCQ / kualitas radio', '%', '70 / 50', 'pair'],
        'temperature' => ['Suhu', '°C', '60 / 75', 'pair'],
        'cpu' => ['CPU', '%', '80 / 95', 'pair'],
        'battery' => ['Muatan baterai UPS', '%', '50 / 20', 'pair'],
        'runtime' => ['Waktu cadangan UPS', 'menit', '15 / 5', 'pair'],
        'lte_rsrp' => ['RSRP modem LTE', 'dBm', '-105 / -115', 'pair'],
        'lte_sinr' => ['SINR modem LTE', 'dB', '3 / 0', 'pair'],
    ];

    public const CATEGORY_ICONS = [
        'core' => 'router', 'distribution' => 'switch', 'access' => 'switch', 'wireless' => 'wifi', 'ptp' => 'antenna',
        'firewall' => 'shield', 'server' => 'server', 'nvr' => 'nvr', 'cctv' => 'camera', 'ups' => 'battery', 'other' => 'box',
    ];

    protected $fillable = [
        'name', 'host', 'site_id', 'parent_id', 'collector_id', 'category', 'enabled', 'notes', 'ping_enabled', 'snmp_enabled', 'snmp_version', 'snmp_port',
        'snmp_v3_user', 'snmp_v3_level', 'snmp_v3_auth_proto', 'snmp_v3_priv_proto', 'cfg_type', 'ssh_port', 'ssh_user',
    ];

    protected $hidden = ['snmp_community_enc', 'snmp_v3_auth_enc', 'snmp_v3_priv_enc', 'ssh_password_enc'];

    public const CFG_TYPES = ['mikrotik' => 'MikroTik RouterOS (/export hide-sensitive)', 'cisco' => 'Cisco IOS / IOS-XE (show running-config)'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'enabled' => 'boolean',
            'modbus_at' => 'datetime',
            'exporter_at' => 'datetime',
            'ping_enabled' => 'boolean',
            'snmp_enabled' => 'boolean',
            'snmp_ok' => 'boolean',
            'last_rtt_ms' => 'float',
            'last_loss_pct' => 'float',
            'last_jitter_ms' => 'float',
            'thresholds' => 'array',
            'status_changed_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'last_snmp_at' => 'datetime',
            'inventory_at' => 'datetime',
            'cfg_last_at' => 'datetime',
            'cfg_requested' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Device::class, 'parent_id');
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(DeviceSensor::class)->orderBy('grp')->orderBy('key');
    }

    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class);
    }

    /** if_index port yang dipantau. */
    public function monitoredPorts(): array
    {
        return \Illuminate\Support\Facades\DB::table('monitored_ports')->where('device_id', $this->id)->pluck('if_index')->all();
    }

    public function ports(): HasMany
    {
        return $this->hasMany(Port::class)->orderBy('if_index');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class)->orderByDesc('at');
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        return $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('host', 'ilike', $like)->orWhere('sys_name', 'ilike', $like));
    }

    /** Simpan kredensial baru; nilai kosong = pertahankan yang lama. */
    public function setSecret(string $column, ?string $plain): void
    {
        if ($plain !== null && $plain !== '') {
            $this->{$column} = Crypt::encryptString($plain);
        }
    }

    public function secret(string $column): ?string
    {
        $enc = $this->getAttribute($column);

        return $enc ? Crypt::decryptString($enc) : null;
    }

    public function hasSecret(string $column): bool
    {
        return (bool) $this->getAttribute($column);
    }

    public function collector(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SiteCollector::class, 'collector_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        if (! $this->enabled) {
            return 'Nonaktif';
        }

        return ['up' => 'Online', 'down' => 'Down', 'unknown' => 'Menunggu'][$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        if (! $this->enabled) {
            return 'off';
        }

        return ['up' => 'ok', 'down' => 'crit', 'unknown' => 'muted'][$this->status] ?? 'muted';
    }

    public function uptimeHuman(): ?string
    {
        return $this->uptime_seconds !== null ? \App\Support\Fmt::duration((int) $this->uptime_seconds) : null;
    }
}
