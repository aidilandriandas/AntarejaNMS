<?php

namespace App\Support;

/** Format angka & waktu untuk tampilan (bahasa Indonesia). */
class Fmt
{
    public static function duration(int $s): string
    {
        return match (true) {
            $s < 60 => $s . ' detik',
            $s < 3600 => intdiv($s, 60) . ' menit',
            $s < 86400 => intdiv($s, 3600) . ' jam' . (($m = intdiv($s % 3600, 60)) ? " {$m} menit" : ''),
            default => intdiv($s, 86400) . ' hari' . (($h = intdiv($s % 86400, 3600)) ? " {$h} jam" : ''),
        };
    }

    public static function bps(?float $v): string
    {
        if ($v === null) {
            return '–';
        }
        foreach (['Gbps' => 1e9, 'Mbps' => 1e6, 'Kbps' => 1e3] as $unit => $div) {
            if ($v >= $div) {
                return rtrim(rtrim(number_format($v / $div, $v / $div >= 100 ? 0 : 1, ',', '.'), '0'), ',') . ' ' . $unit;
            }
        }

        return number_format($v, 0, ',', '.') . ' bps';
    }

    public static function ms(?float $v): string
    {
        if ($v === null) {
            return '–';
        }

        return number_format($v, $v >= 100 ? 0 : ($v >= 10 ? 1 : 2), ',', '.') . ' ms';
    }
}
