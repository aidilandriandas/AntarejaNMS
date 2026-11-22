<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Daftar "Langkah awal" untuk admin setelah pemasangan; tiap langkah dicek otomatis dari data. */
class Onboarding
{
    /** @return array<int, array{key:string, title:string, hint:string, done:bool, route:string}> */
    public static function steps(): array
    {
        $q = fn (string $sql) => (bool) DB::selectOne("SELECT EXISTS ($sql) AS e")->e;

        return [
            ['key' => 'site', 'title' => 'Tambahkan site', 'hint' => 'Kode singkat tiap lokasi, mis. PIT-A, JETTY.', 'route' => 'sites.index',
                'done' => $q('SELECT 1 FROM sites')],
            ['key' => 'device', 'title' => 'Tambahkan perangkat', 'hint' => 'Satu per satu, Discovery (pindai subnet), atau impor CSV.', 'route' => 'devices.index',
                'done' => $q('SELECT 1 FROM devices')],
            ['key' => 'snmp', 'title' => 'SNMP terbaca di minimal satu perangkat', 'hint' => 'Aktifkan SNMP read-only di perangkat, lalu "Uji sekarang" di form perangkat.', 'route' => 'devices.index',
                'done' => $q('SELECT 1 FROM devices WHERE snmp_enabled AND snmp_ok')],
            ['key' => 'parent', 'title' => 'Isi perangkat induk (uplink)', 'hint' => 'Agar saat uplink down, perangkat di bawahnya tidak membanjiri alarm.', 'route' => 'devices.index',
                'done' => $q('SELECT 1 FROM devices WHERE parent_id IS NOT NULL')],
            ['key' => 'notify', 'title' => 'Kanal notifikasi terkirim', 'hint' => 'Telegram/email/webhook, lalu tekan "Kirim uji" sampai berhasil.', 'route' => 'channels.index',
                'done' => $q("SELECT 1 FROM notifications WHERE status = 'sent'")],
            ['key' => 'users', 'title' => 'Buat akun per orang', 'hint' => 'Engineer & viewer (layar NOC) — hindari akun bersama.', 'route' => 'users.index',
                'done' => (int) DB::table('users')->where('active', true)->count() > 1],
            ['key' => 'backup', 'title' => 'Backup database berhasil', 'hint' => 'Tekan "Backup sekarang"; arahkan folder backup ke NAS.', 'route' => 'backups.index',
                'done' => $q("SELECT 1 FROM backups WHERE status = 'ok'")],
            ['key' => 'syslog', 'title' => 'Syslog dari perangkat masuk', 'hint' => 'Arahkan syslog perangkat ke IP server port 514 (petunjuk di halaman Log).', 'route' => 'logs.index',
                'done' => $q('SELECT 1 FROM syslog WHERE device_id IS NOT NULL')],
            ['key' => 'map', 'title' => 'Tempatkan site di peta', 'hint' => 'Peta lokasi → Atur posisi.', 'route' => 'map.index',
                'done' => $q('SELECT 1 FROM sites WHERE lat IS NOT NULL')],
        ];
    }

    public static function hidden(): bool
    {
        return (bool) Setting::get('onboarding.hidden', false);
    }
}
