<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Port perangkat (tabel interfaces), ditulis collector dari IF-MIB. Hanya dibaca oleh web. */
class Port extends Model
{
    protected $table = 'interfaces';

    public $incrementing = false;

    protected $primaryKey = 'if_index';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['admin_up' => 'boolean', 'oper_up' => 'boolean', 'last_change_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function label(): string
    {
        return $this->name ?: ($this->descr ?: 'if' . $this->if_index);
    }
}
