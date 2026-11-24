<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceTemplate extends Model
{
    protected $fillable = ['name', 'description', 'thresholds', 'macros', 'exporter_url', 'exporter_services'];

    protected function casts(): array
    {
        return ['thresholds' => 'array', 'macros' => 'array'];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(TemplateCheck::class, 'template_id')->orderBy('name');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'template_id');
    }
}
