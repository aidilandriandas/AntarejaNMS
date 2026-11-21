<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Jendela maintenance: problem perangkat di dalamnya tetap tercatat tetapi tidak dinotifikasi. */
class Maintenance extends Model
{
    protected $fillable = ['title', 'note', 'scope', 'site_id', 'starts_at', 'ends_at', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'maintenance_devices');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function state(): string
    {
        $now = now();

        return $this->ends_at <= $now ? 'selesai' : ($this->starts_at <= $now ? 'berjalan' : 'terjadwal');
    }

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            'all' => 'Semua perangkat',
            'site' => 'Site ' . ($this->site?->code ?? '?'),
            default => $this->devices->count() . ' perangkat: ' . $this->devices->pluck('name')->take(4)->implode(', ') . ($this->devices->count() > 4 ? ', …' : ''),
        };
    }
}
