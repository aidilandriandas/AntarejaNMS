<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteCollector extends Model
{
    protected $fillable = ['name', 'site_id', 'notes', 'enabled'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'last_seen_at' => 'datetime', 'newest_sample_at' => 'datetime', 'last_ping_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'collector_id');
    }

    /** online | late | never | off */
    public function state(): string
    {
        if (! $this->enabled) {
            return 'off';
        }
        if (! $this->last_seen_at) {
            return 'never';
        }

        return $this->last_seen_at->gt(now()->subMinutes(3)) ? 'online' : 'late';
    }

    public function stateLabel(): string
    {
        return ['online' => 'terhubung', 'late' => 'tidak melapor', 'never' => 'belum terhubung', 'off' => 'nonaktif'][$this->state()];
    }
}
