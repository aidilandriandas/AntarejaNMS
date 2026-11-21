<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $table = 'notifications';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotifyChannel::class, 'channel_id');
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }
}
