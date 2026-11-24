<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Runbook extends Model
{
    protected $fillable = ['title', 'body', 'problem_kind', 'category', 'site_id', 'device_id', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Skor kekhususan: perangkat 8, site 4, kategori 2, jenis problem 1. */
    public function score(): int
    {
        return ($this->device_id ? 8 : 0) + ($this->site_id ? 4 : 0) + ($this->category ? 2 : 0) + ($this->problem_kind ? 1 : 0);
    }

    /** Cocok untuk perangkat ini dan jenis problem ini ($kind null = abaikan syarat jenis). */
    public function matches(Device $d, ?string $kind): bool
    {
        return (! $this->device_id || $this->device_id === $d->id)
            && (! $this->site_id || $this->site_id === $d->site_id)
            && (! $this->category || $this->category === $d->category)
            && ($kind === null || ! $this->problem_kind || $this->problem_kind === $kind);
    }

    /** Runbook paling spesifik untuk problem (jenis + perangkat). */
    public static function best(Collection $all, Device $d, string $kind): ?self
    {
        return $all->filter(fn (self $r) => $r->enabled && $r->matches($d, $kind))
            ->sortBy([fn ($a, $b) => $b->score() <=> $a->score(), fn ($a, $b) => $a->id <=> $b->id])->first();
    }

    /** Ringkasan cakupan, mis. "Perangkat down, Radio PTP, site PIT-A". */
    public function scopeLabel(): string
    {
        $p = [];
        $p[] = $this->problem_kind ? (Problem::KINDS[$this->problem_kind] ?? $this->problem_kind) : 'semua jenis problem';
        if ($this->category) {
            $p[] = Device::CATEGORIES[$this->category] ?? $this->category;
        }
        if ($this->site) {
            $p[] = 'site ' . $this->site->code;
        }
        if ($this->device) {
            $p[] = $this->device->name;
        }

        return implode(', ', $p);
    }
}
