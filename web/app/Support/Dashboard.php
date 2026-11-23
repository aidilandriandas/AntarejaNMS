<?php

namespace App\Support;

/** Daftar widget Ringkasan & susunan per pengguna (kolom users.dashboard). */
class Dashboard
{
    /** kunci => [judul, ukuran bawaan (half|full), keterangan, boleh setengah?] */
    public const WIDGETS = [
        'kpi' => ['Angka utama', 'full', 'Ketersediaan, online, down, SNMP gagal, menunggu', false],
        'problems' => ['Problem aktif', 'half', '15 problem terpenting yang masih terbuka', true],
        'events' => ['Kejadian 24 jam', 'half', 'Down, pulih, reboot, perubahan konfigurasi, syslog kritis', true],
        'slow' => ['Latensi & loss tertinggi', 'half', 'Dari ping terakhir', true],
        'sites' => ['Per site', 'full', 'Online/down tiap site', true],
        'sensors' => ['Sensor di luar ambang', 'half', 'Suhu, sinyal radio, baterai UPS, CPU, dll.', true],
        'cameras' => ['Kamera bermasalah', 'half', 'Gelap, tertutup, beku, tanpa sinyal', true],
        'logs' => ['Syslog error terbaru', 'half', 'Tingkat Error ke atas, 24 jam', true],
        'config' => ['Perubahan konfigurasi', 'half', 'Dari backup konfigurasi SSH, 7 hari', true],
        'maintenance' => ['Maintenance', 'half', 'Yang sedang berjalan & 7 hari ke depan', true],
        'map' => ['Peta lokasi', 'full', 'Status site di peta', true],
        'services' => ['Layanan', 'half', 'Status & ketersediaan bulan ini per layanan (SLA layanan)', true],
        'oncall' => ['Piket', 'half', 'Siapa yang sedang piket dan siapa berikutnya', true],
    ];

    /** Susunan bawaan: antrean problem di depan, angka sesudahnya. */
    public const DEFAULT = [
        ['k' => 'problems', 'w' => 'full'], ['k' => 'kpi', 'w' => 'full'], ['k' => 'events', 'w' => 'half'],
        ['k' => 'slow', 'w' => 'half'], ['k' => 'sensors', 'w' => 'half'], ['k' => 'oncall', 'w' => 'half'], ['k' => 'sites', 'w' => 'full'],
    ];

    /** Bersihkan susunan tersimpan: kunci dikenal, tanpa duplikat, ukuran valid. */
    public static function normalize(?array $layout): array
    {
        $out = [];
        $seen = [];
        foreach ($layout ?? self::DEFAULT as $w) {
            $k = $w['k'] ?? null;
            if (! is_string($k) || ! isset(self::WIDGETS[$k]) || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $size = ($w['w'] ?? '') === 'half' && self::WIDGETS[$k][3] ? 'half' : (($w['w'] ?? '') === 'full' ? 'full' : self::WIDGETS[$k][1]);
            $out[] = ['k' => $k, 'w' => $size];
        }

        return $out;
    }
}
