<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Satu giliran piket: satu orang, dari starts_at sampai ends_at. */
class OncallSlot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'starts_at', 'ends_at', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Slot yang menyentuh rentang [from, to). */
    public function scopeOverlapping(Builder $q, Carbon $from, Carbon $to): Builder
    {
        return $q->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /** Slot yang sedang berjalan, untuk pengguna aktif saja (sama seperti collector). */
    public static function current(): \Illuminate\Support\Collection
    {
        $now = now();

        return static::with('user')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->whereHas('user', fn ($q) => $q->where('active', true))->orderBy('starts_at')->get();
    }

    /** Pergantian berikutnya: slot terdekat yang mulai setelah sekarang (beserta slot lain yang mulai di jam yang sama). */
    public static function upcoming(): \Illuminate\Support\Collection
    {
        $first = static::where('starts_at', '>', now())->whereHas('user', fn ($q) => $q->where('active', true))->min('starts_at');

        return $first ? static::with('user')->where('starts_at', $first)->whereHas('user', fn ($q) => $q->where('active', true))->get() : collect();
    }

    /** Ringkasan untuk widget dan layar NOC. */
    public static function brief(): array
    {
        $cur = static::current();
        $next = static::upcoming();

        return [
            'now' => $cur->map(fn ($s) => ['name' => $s->user->name, 'until' => $s->ends_at])->values(),
            'next' => $next->map(fn ($s) => ['name' => $s->user->name, 'from' => $s->starts_at])->values(),
        ];
    }
}
