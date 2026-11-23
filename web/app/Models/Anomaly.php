<?php

namespace App\Models;

use App\Support\Fmt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nilai yang menyimpang jauh dari kebiasaan jam yang sama (dicatat collector, tabel anomalies). */
class Anomaly extends Model
{
    public $timestamps = false;

    public const METRICS = ['latency' => 'Latensi', 'port_in' => 'Trafik masuk', 'port_out' => 'Trafik keluar'];

    public const SEVERITIES = ['off' => 'Tidak jadi problem (hanya dicatat)', 'info' => 'Info', 'warning' => 'Warning', 'average' => 'Average'];

    public const MUTES = [1 => '1 jam', 24 => '24 jam', 168 => '7 hari'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'updated_at' => 'datetime', 'ended_at' => 'datetime',
            'value' => 'float', 'baseline' => 'float', 'spread' => 'float', 'score' => 'float'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function fmt(?float $v): string
    {
        if ($v === null) {
            return 'tidak ada';
        }

        return $this->metric === 'latency' ? Fmt::num($v) . ' ms' : Fmt::bps($v);
    }

    /** "Latensi" atau "Trafik masuk ether3". */
    public function label(): string
    {
        return self::METRICS[$this->metric] . ($this->if_name ? ' ' . $this->if_name : '');
    }

    /** Berapa kali lipat dari biasanya, untuk dibaca cepat. */
    public function ratio(): string
    {
        if ($this->baseline <= 0) {
            return '';
        }
        $r = $this->value / $this->baseline;

        return $r >= 1 ? number_format($r, $r >= 10 ? 0 : 1, ',', '.') . 'x biasanya' : number_format($r * 100, 0, ',', '.') . '% dari biasanya';
    }
}
