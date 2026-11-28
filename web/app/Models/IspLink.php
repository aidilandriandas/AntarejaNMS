<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IspLink extends Model
{
    protected $fillable = ['name', 'provider', 'device_id', 'if_index', 'ping_device_id', 'bandwidth_mbps', 'target_pct', 'warn_pct', 'loss_down_pct', 'latency_target_ms', 'jitter_target_ms',
        'loss_target_pct', 'exclude_maintenance', 'notes'];

    protected function casts(): array
    {
        return ['target_pct' => 'float', 'warn_pct' => 'float', 'loss_down_pct' => 'float', 'latency_target_ms' => 'float', 'jitter_target_ms' => 'float',
            'loss_target_pct' => 'float', 'exclude_maintenance' => 'boolean', 'if_index' => 'integer', 'bandwidth_mbps' => 'float'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function pingDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'ping_device_id');
    }

    /** Port ke ISP pada router (null untuk link lama berbasis ping). */
    public function port(): ?object
    {
        return $this->if_index === null ? null
            : \Illuminate\Support\Facades\DB::table('interfaces')->where('device_id', $this->device_id)->where('if_index', $this->if_index)->first(['if_index', 'name', 'alias', 'oper_up', 'speed_bps']);
    }

    /** "R.AMM-PIK, ether1 (ISP Telkom)" atau "nama perangkat" */
    public function sourceLabel(): string
    {
        $p = $this->port();

        return ($this->device?->name ?? '?') . ($this->if_index !== null ? ', ' . ($p ? $p->name . ($p->alias ? " ({$p->alias})" : '') : 'port ' . $this->if_index) : '');
    }

    /** compliant | warning | breach | null (tanpa data) */
    public function verdict(?float $availability): ?string
    {
        if ($availability === null) {
            return null;
        }

        return $availability >= $this->target_pct ? 'compliant' : ($availability >= $this->warn_pct ? 'warning' : 'breach');
    }

    public const VERDICTS = ['compliant' => ['Memenuhi SLA', 'ok'], 'warning' => ['Peringatan', 'warn'], 'breach' => ['Tidak memenuhi', 'crit']];
}
