<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Insiden: satu gangguan nyata yang mengelompokkan beberapa problem. */
class Incident extends Model
{
    protected $fillable = ['title', 'severity', 'status', 'site_id', 'owner_id', 'auto', 'cause', 'action_taken', 'opened_at', 'created_by'];

    public const CREATED_AT = null;

    public const STATUSES = [
        'open' => ['Terbuka', 'crit'], 'handling' => ['Ditangani', 'warn'], 'recovered' => ['Pulih', 'ok'], 'closed' => ['Ditutup', ''],
    ];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'opened_at' => 'datetime', 'handled_at' => 'datetime', 'recovered_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function problems(): BelongsToMany
    {
        return $this->belongsToMany(Problem::class, 'incident_problems')->withPivot('added_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(IncidentNote::class)->orderBy('at')->orderBy('id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? '';
    }

    public function sevLabel(): string
    {
        return Problem::SEVERITIES[$this->severity][0] ?? $this->severity;
    }

    public function sevTone(): string
    {
        return Problem::SEVERITIES[$this->severity][1] ?? '';
    }

    /** Lama gangguan: sampai pulih (atau sekarang bila masih aktif). */
    public function downtimeSeconds(): int
    {
        $end = $this->recovered_at ?? ($this->status === 'closed' ? ($this->closed_at ?? now()) : now());

        return max(0, $this->opened_at->diffInSeconds($end));
    }

    public function note(string $kind, string $body, ?int $userId = null): void
    {
        $this->notes()->create(['kind' => $kind, 'body' => $body, 'user_id' => $userId, 'at' => now()]);
    }
}
