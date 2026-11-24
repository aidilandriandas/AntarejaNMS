<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Trigger custom: aturan problem buatan engineer, dinilai collector setiap putaran. */
class Trigger extends Model
{
    protected $fillable = ['name', 'enabled', 'severity', 'metric', 'sensor_class', 'match_text', 'op', 'threshold', 'duration_min',
        'scope', 'site_id', 'category', 'days', 'start_min', 'end_min', 'note', 'created_by'];

    /** kunci => [label, satuan] */
    public const METRICS = [
        'latency' => ['Latensi ping (rata-rata 5 menit)', 'ms'],
        'loss' => ['Packet loss (rata-rata 5 menit)', '%'],
        'sensor' => ['Nilai sensor', ''],
        'port_in' => ['Trafik masuk port', 'Mbps'],
        'port_out' => ['Trafik keluar port', 'Mbps'],
        'port_util' => ['Pemakaian port', '%'],
        'port_errors' => ['Error port (masuk + keluar)', 'per menit'],
        'port_discards' => ['Discard port (masuk + keluar)', 'per menit'],
    ];

    public const SENSOR_CLASSES = [
        'temperature' => 'Suhu', 'voltage' => 'Tegangan', 'current' => 'Arus', 'power' => 'Daya', 'signal' => 'Sinyal radio',
        'noise' => 'Noise', 'ccq' => 'CCQ / kualitas link', 'rate' => 'Kecepatan link radio', 'distance' => 'Jarak link',
        'frequency' => 'Frekuensi', 'clients' => 'Jumlah klien', 'cpu' => 'CPU', 'memory' => 'Memori', 'disk' => 'Disk',
        'load' => 'Beban (UPS dll.)', 'battery' => 'Baterai', 'runtime' => 'Cadangan waktu UPS', 'fan' => 'Kipas', 'ups_status' => 'Status UPS',
        'fuel' => 'Bahan bakar (Modbus)', 'other' => 'Lainnya (Modbus)',
    ];

    public const OPS = ['>' => 'lebih dari', '>=' => 'paling sedikit', '<' => 'kurang dari', '<=' => 'paling banyak'];

    public const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'threshold' => 'float', 'duration_min' => 'integer', 'days' => 'integer'];
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'trigger_devices')->orderBy('name');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function unit(): string
    {
        return $this->metric === 'sensor' ? '' : self::METRICS[$this->metric][1];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }

    /** "Trafik masuk port berisi "sfp" kurang dari 0,01 Mbps selama 10 menit" */
    public function conditionText(): string
    {
        $what = $this->metric === 'sensor' ? 'Sensor ' . strtolower(self::SENSOR_CLASSES[$this->sensor_class] ?? $this->sensor_class) : self::METRICS[$this->metric][0];
        $what = preg_replace('/ \(rata-rata 5 menit\)$/', '', $what);
        if ($this->match_text) {
            $what .= ' berisi "' . $this->match_text . '"';
        }
        $unit = $this->unit() !== '' ? ' ' . $this->unit() : '';

        return $what . ' ' . self::OPS[$this->op] . ' ' . self::num($this->threshold) . $unit
            . ($this->duration_min > 0 ? ' selama ' . $this->duration_min . ' menit' : ', langsung');
    }

    public function scopeText(): string
    {
        return match ($this->scope) {
            'site' => 'Site ' . ($this->site?->code ?? '?'),
            'category' => Device::CATEGORIES[$this->category] ?? $this->category,
            'devices' => $this->devices->count() . ' perangkat terpilih',
            default => 'Semua perangkat',
        };
    }

    public function scheduleText(): string
    {
        $days = collect(range(0, 6))->filter(fn ($i) => $this->days & (1 << $i))->values();
        $d = match (true) {
            $days->count() === 7 => 'Setiap hari',
            $days->all() === [0, 1, 2, 3, 4] => 'Senin sampai Jumat',
            $days->all() === [0, 1, 2, 3, 4, 5] => 'Senin sampai Sabtu',
            default => $days->map(fn ($i) => self::DAYS[$i])->implode(', '),
        };
        if ($this->start_min === null) {
            return $d . ', sepanjang hari';
        }
        $hm = fn ($m) => sprintf('%02d.%02d', intdiv($m, 60) % 24, $m % 60);

        return $d . ', ' . $hm($this->start_min) . ' sampai ' . $hm($this->end_min);
    }
}
