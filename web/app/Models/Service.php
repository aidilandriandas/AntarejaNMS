<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Layanan bisnis (mis. "CCTV Pit A") yang SLA-nya dihitung dari komponen perangkat. */
class Service extends Model
{
    protected $fillable = ['name', 'description', 'site_id', 'target_pct'];

    protected function casts(): array
    {
        return ['target_pct' => 'float'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(ServiceComponent::class)->orderBy('position')->orderBy('id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function target(): float
    {
        return $this->target_pct ?? (float) Setting::get('sla.target_pct', 99.5);
    }
}
