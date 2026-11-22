<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SLA per layanan.
 *
 * Interval down tiap perangkat = problem "down" (termasuk yang ditekan karena induknya down) yang beririsan dengan
 * periode, dikurangi jendela maintenance yang berlaku untuk perangkat itu (rumus yang sama dengan SLA perangkat).
 * Komponen down saat jumlah anggota yang hidup < min_up. Layanan down = gabungan interval down semua komponen.
 * "Tanpa cadangan" = ada anggota yang down tetapi layanan masih jalan (redundansi sedang hilang).
 * Interval disimpan sebagai [awal, akhir] detik epoch (float).
 */
class ServiceSla
{
    /**
     * @param  Collection<int, Service>  $services  dengan relasi components.devices
     * @return array<int, array> per id layanan
     */
    public function compute(Collection $services, Carbon $start, Carbon $end): array
    {
        $s = $start->getTimestamp();
        $e = min($end->getTimestamp(), time());
        $devIds = $services->flatMap(fn ($sv) => $sv->components->flatMap(fn ($c) => $c->devices->pluck('id')))->unique()->values();
        $down = $e > $s ? $this->deviceDown($devIds->all(), $s, $e) : [];

        $out = [];
        foreach ($services as $sv) {
            $svcDown = [];
            $memberDown = [];
            $cause = [];
            foreach ($sv->components as $c) {
                $ints = $c->devices->map(fn ($d) => $down[$d->id] ?? [])->all();
                foreach ($ints as $list) {
                    array_push($memberDown, ...$list);
                }
                $cd = self::belowMin($ints, $c->devices->count(), (int) $c->min_up);
                foreach ($cd as $iv) {
                    $cause[] = [$iv[0], $iv[1], $c->name];
                }
                array_push($svcDown, ...$cd);
            }
            $svcDown = self::union($svcDown);
            $degraded = self::subtract(self::union($memberDown), $svcDown);
            // dipantau sejak perangkat pertama layanan ini ditambahkan (sebelum itu belum ada data)
            $first = $sv->components->flatMap->devices->min(fn ($d) => $d->created_at?->getTimestamp());
            $mon = max(0, $e - max($s, $first ?? $s));
            $downS = self::length($svcDown);
            $outages = array_map(function ($iv) use ($cause) {
                $names = collect($cause)->filter(fn ($c) => $c[0] < $iv[1] && $c[1] > $iv[0])->pluck(2)->unique()->values()->all();

                return ['start' => $iv[0], 'end' => $iv[1], 'seconds' => $iv[1] - $iv[0], 'components' => $names];
            }, $svcDown);
            $out[$sv->id] = [
                'monitored' => $mon, 'down' => $downS, 'degraded' => self::length($degraded),
                'availability' => $mon > 0 ? round((1 - $downS / $mon) * 100, 3) : null,
                'outages' => $outages, 'count' => count($outages),
                'longest' => $outages ? max(array_column($outages, 'seconds')) : 0,
            ];
        }

        return $out;
    }

    /** Status sekarang dari devices.status: up | degraded | down | empty, plus status per komponen. */
    public static function liveStatus(Service $sv): array
    {
        $comps = [];
        $state = $sv->components->isEmpty() ? 'empty' : 'up';
        foreach ($sv->components as $c) {
            $n = $c->devices->count();
            $alive = $c->devices->filter(fn ($d) => $d->status !== 'down')->count();
            $st = $alive < $c->min_up ? 'down' : ($alive < $n ? 'degraded' : 'up');
            $comps[$c->id] = $st;
            if ($st === 'down') {
                $state = 'down';
            } elseif ($st === 'degraded' && $state !== 'down') {
                $state = 'degraded';
            }
        }

        return [$state, $comps];
    }

    public const STATES = ['up' => ['Normal', 'ok'], 'degraded' => ['Tanpa cadangan', 'warn'], 'down' => ['Terganggu', 'crit'], 'empty' => ['Belum diatur', '']];

    /** @return array<int, array<int, array{0: float, 1: float}>> */
    private function deviceDown(array $devIds, int $s, int $e): array
    {
        if (! $devIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $devIds));
        $rows = DB::select("SELECT device_id, EXTRACT(EPOCH FROM GREATEST(started_at, to_timestamp(?))) AS a,
                EXTRACT(EPOCH FROM LEAST(COALESCE(resolved_at, now()), to_timestamp(?))) AS b
            FROM problems WHERE kind = 'down' AND device_id IN ($in) AND started_at < to_timestamp(?) AND COALESCE(resolved_at, now()) > to_timestamp(?)", [$s, $e, $e, $s]);
        $maint = DB::select("SELECT d.id AS device_id, EXTRACT(EPOCH FROM GREATEST(m.starts_at, to_timestamp(?))) AS a, EXTRACT(EPOCH FROM LEAST(m.ends_at, to_timestamp(?))) AS b
            FROM maintenances m JOIN devices d ON d.id IN ($in)
            WHERE m.starts_at < to_timestamp(?) AND m.ends_at > to_timestamp(?)
              AND (m.scope = 'all' OR (m.scope = 'site' AND m.site_id = d.site_id)
                   OR (m.scope = 'devices' AND EXISTS (SELECT 1 FROM maintenance_devices md WHERE md.maintenance_id = m.id AND md.device_id = d.id)))", [$s, $e, $e, $s]);
        $m = [];
        foreach ($maint as $r) {
            $m[$r->device_id][] = [(float) $r->a, (float) $r->b];
        }
        $d = [];
        foreach ($rows as $r) {
            if ((float) $r->b > (float) $r->a) {
                $d[$r->device_id][] = [(float) $r->a, (float) $r->b];
            }
        }
        foreach ($d as $id => $list) {
            $d[$id] = self::subtract(self::union($list), self::union($m[$id] ?? []));
        }

        return $d;
    }

    /** Interval saat jumlah anggota hidup < $min. $ints = daftar interval down per anggota (sudah digabung). */
    public static function belowMin(array $ints, int $n, int $min): array
    {
        $need = $n - $min + 1; // jumlah anggota down yang membuat komponen down
        if ($n === 0 || $need <= 0) {
            return [];
        }
        $ev = [];
        foreach ($ints as $list) {
            foreach ($list as [$a, $b]) {
                $ev[] = [$a, 1];
                $ev[] = [$b, -1];
            }
        }
        usort($ev, fn ($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]); // akhir (-1) sebelum awal (+1) di detik yang sama
        $out = [];
        $cnt = 0;
        $from = null;
        foreach ($ev as [$t, $dx]) {
            $cnt += $dx;
            if ($cnt >= $need && $from === null) {
                $from = $t;
            } elseif ($cnt < $need && $from !== null) {
                if ($t > $from) {
                    $out[] = [$from, $t];
                }
                $from = null;
            }
        }

        return self::union($out);
    }

    public static function union(array $ints): array
    {
        usort($ints, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($ints as $iv) {
            if ($out && $iv[0] <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $iv[1]);
            } elseif ($iv[1] > $iv[0]) {
                $out[] = $iv;
            }
        }

        return $out;
    }

    /** $a − $b, keduanya sudah digabung & urut. */
    public static function subtract(array $a, array $b): array
    {
        $out = [];
        foreach ($a as [$x, $y]) {
            foreach ($b as [$p, $q]) {
                if ($q <= $x || $p >= $y) {
                    continue;
                }
                if ($p > $x) {
                    $out[] = [$x, $p];
                }
                $x = max($x, $q);
                if ($x >= $y) {
                    break;
                }
            }
            if ($x < $y) {
                $out[] = [$x, $y];
            }
        }

        return $out;
    }

    public static function length(array $ints): float
    {
        return array_sum(array_map(fn ($iv) => $iv[1] - $iv[0], $ints));
    }
}
