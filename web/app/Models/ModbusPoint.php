<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModbusPoint extends Model
{
    public $timestamps = false;

    public const CLASSES = ['voltage' => 'Tegangan', 'current' => 'Arus', 'power' => 'Daya', 'battery' => 'Kapasitas baterai (%)',
        'fuel' => 'Bahan bakar', 'temperature' => 'Suhu', 'other' => 'Lainnya'];

    public const DTYPES = ['u16' => '16-bit tanpa tanda', 's16' => '16-bit bertanda', 'u32' => '32-bit tanpa tanda', 's32' => '32-bit bertanda', 'f32' => '32-bit float'];

    protected $fillable = ['label', 'class', 'unit', 'fn', 'address', 'dtype', 'word_swap', 'scale', 'offset_value',
        'warn_low', 'warn_high', 'crit_low', 'crit_high', 'position', 'enabled'];

    protected function casts(): array
    {
        return ['word_swap' => 'boolean', 'enabled' => 'boolean', 'scale' => 'float', 'offset_value' => 'float',
            'warn_low' => 'float', 'warn_high' => 'float', 'crit_low' => 'float', 'crit_high' => 'float'];
    }

    public function sensorKey(): string
    {
        return 'mb:' . $this->id;
    }

    /** Ringkasan ambang, mis. "warning di bawah 12,2, kritis di bawah 11,8". */
    public function thresholdLabel(): string
    {
        $n = fn ($v) => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
        $p = [];
        foreach (['warn' => 'warning', 'crit' => 'kritis'] as $k => $lbl) {
            $lo = $this->{$k . '_low'};
            $hi = $this->{$k . '_high'};
            if ($lo !== null || $hi !== null) {
                $p[] = $lbl . ($lo !== null ? ' di bawah ' . $n($lo) : '') . ($lo !== null && $hi !== null ? ' atau' : '') . ($hi !== null ? ' di atas ' . $n($hi) : '');
            }
        }

        return $p ? implode(', ', $p) : 'tanpa ambang';
    }
}
