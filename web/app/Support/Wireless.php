<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Link radio Ubiquiti (airMAX, airFiber, LTU) hasil baca collector: siapa tersambung ke siapa dan kualitasnya. */
class Wireless
{
    public const KINDS = ['airmax' => 'airMAX', 'airfiber' => 'airFiber', 'ltu' => 'LTU', 'mikrotik' => 'MikroTik'];

    /** Ambang sinyal bawaan (dBm), sama dengan sensor sinyal radio: di bawah -70 peringatan, di bawah -78 kritis. */
    public const SIGNAL_WARN = -70;

    public const SIGNAL_CRIT = -78;

    public static function tone(?float $dbm): string
    {
        return match (true) {
            $dbm === null => 'muted',
            $dbm <= self::SIGNAL_CRIT => 'crit',
            $dbm <= self::SIGNAL_WARN => 'warn',
            default => 'ok',
        };
    }

    /** Kueri dasar dengan nama perangkat pelapor dan lawan; akun terbatas site hanya melihat link perangkat di site-nya. */
    public static function query()
    {
        $q = DB::table('wireless_links as w')->join('devices as d', 'd.id', '=', 'w.device_id')
            ->leftJoin('devices as r', 'r.id', '=', 'w.remote_device_id')
            ->select('w.*', 'd.name as dev_name', 'd.status as dev_status', 'r.name as remote_name', 'r.status as remote_status');
        if ($ids = SiteScope::current()) {
            $q->whereIn('d.site_id', $ids);
        }

        return $q;
    }

    /** Link yang dilaporkan perangkat ini (AP / sisi airFiber) dan link tempat perangkat ini menjadi lawan (CPE). */
    public static function forDevice(int $id): array
    {
        return [
            'own' => self::query()->where('w.device_id', $id)->orderByDesc('w.connected')->orderBy('w.signal_dbm')->get(),
            'peer' => self::query()->where('w.remote_device_id', $id)->where('w.connected', true)->get(),
        ];
    }

    public static function uptime(?int $s): ?string
    {
        if ($s === null) {
            return null;
        }

        return match (true) {
            $s >= 86400 => intdiv($s, 86400) . ' hari' . (intdiv($s % 86400, 3600) ? ' ' . intdiv($s % 86400, 3600) . ' jam' : ''),
            $s >= 3600 => intdiv($s, 3600) . ' jam' . (intdiv($s % 3600, 60) ? ' ' . intdiv($s % 3600, 60) . ' menit' : ''),
            default => max(1, intdiv($s, 60)) . ' menit',
        };
    }

    /** Info radio per pasangan perangkat untuk garis topologi: "kecil-besar" => sinyal terburuk dan sumbernya. */
    public static function edgeInfo(): Collection
    {
        return DB::table('wireless_links')->where('connected', true)->whereNotNull('remote_device_id')
            ->get(['device_id', 'remote_device_id', 'kind', 'signal_dbm', 'remote_signal_dbm', 'ccq_pct', 'capacity_pct', 'distance_m', 'tx_mbps', 'rx_mbps'])
            ->groupBy(fn ($w) => min($w->device_id, $w->remote_device_id) . '-' . max($w->device_id, $w->remote_device_id))
            ->map(function ($rows) {
                $sig = $rows->flatMap(fn ($w) => [$w->signal_dbm, $w->remote_signal_dbm])->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
                $w = $rows->first();

                return ['kind' => self::KINDS[$w->kind] ?? $w->kind, 'signal' => $sig->isNotEmpty() ? $sig->min() : null,
                    'ccq' => $rows->pluck('ccq_pct')->filter(fn ($v) => $v !== null)->min(),
                    'distance_km' => $w->distance_m ? round($w->distance_m / 1000, 1) : null,
                    'tx' => $w->tx_mbps !== null ? (float) $w->tx_mbps : null, 'rx' => $w->rx_mbps !== null ? (float) $w->rx_mbps : null];
            });
    }
}
