<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IspLink extends Model
{
    protected $fillable = ['name', 'provider', 'device_id', 'target_pct', 'warn_pct', 'loss_down_pct', 'latency_target_ms', 'jitter_target_ms',
        'loss_target_pct', 'exclude_maintenance', 'notes'];

    protected function casts(): array
    {
        return ['target_pct' => 'float', 'warn_pct' => 'float', 'loss_down_pct' => 'float', 'latency_target_ms' => 'float', 'jitter_target_ms' => 'float',
            'loss_target_pct' => 'float', 'exclude_maintenance' => 'boolean'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
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
