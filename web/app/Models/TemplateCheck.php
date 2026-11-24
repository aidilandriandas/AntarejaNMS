<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateCheck extends Model
{
    protected $fillable = ['name', 'type', 'url', 'port', 'query', 'expect_status', 'expect_text', 'expect_answer', 'verify_tls',
        'interval_s', 'timeout_s', 'slow_ms', 'cert_warn_days', 'severity', 'enabled'];

    protected function casts(): array
    {
        return ['verify_tls' => 'boolean', 'enabled' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DeviceTemplate::class, 'template_id');
    }
}
