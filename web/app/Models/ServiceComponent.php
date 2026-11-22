<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Komponen layanan: sekumpulan perangkat, layanan butuh minimal min_up di antaranya hidup. */
class ServiceComponent extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'min_up', 'position'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'service_component_devices', 'component_id', 'device_id')->orderBy('name');
    }

    public function ruleLabel(): string
    {
        $n = $this->devices->count();

        return match (true) {
            $n <= 1 => 'wajib hidup',
            $this->min_up >= $n => "semua {$n} wajib hidup",
            $this->min_up === 1 => "cukup 1 dari {$n} (cadangan)",
            default => "minimal {$this->min_up} dari {$n}",
        };
    }
}
