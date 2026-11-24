<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyslogRule extends Model
{
    protected $fillable = ['name', 'pattern', 'is_regex', 'max_severity', 'app', 'site_id', 'device_id', 'min_count', 'window_s', 'clear_s', 'severity', 'enabled'];

    protected function casts(): array
    {
        return ['is_regex' => 'boolean', 'enabled' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Pola sebagai regex PHP untuk uji di web (RE2 di collector; pola umum berperilaku sama). */
    public function phpRegex(): string
    {
        return $this->is_regex ? '~' . str_replace('~', '\~', $this->pattern) . '~iu' : '~' . preg_quote($this->pattern, '~') . '~iu';
    }
}
