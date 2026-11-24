<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceSensor;
use App\Models\Site;
use Illuminate\Http\Request;

/** Versi perangkat lunak per model dan selisih jam perangkat. Hanya baca, semua peran. */
class VersionController extends Controller
{
    public function index(Request $request)
    {
        $site = $request->integer('site');
        $devices = Device::with('site')->where('enabled', true)->where('snmp_enabled', true)
            ->when($site, fn ($q) => $q->where('site_id', $site))->orderBy('name')->get();

        // Kelompok per model (atau kategori bila model tidak terbaca). Versi terbanyak dianggap acuan;
        // perangkat dengan versi lain ditandai supaya mudah dijadwalkan pembaruannya.
        $groups = $devices->filter(fn ($d) => $d->os_version)->groupBy(fn ($d) => $d->hw_model ?: 'Model tidak terbaca (' . ($d->category ?: 'lainnya') . ')')
            ->map(function ($list, $model) {
                $counts = $list->countBy('os_version')->sortDesc();
                $main = $counts->keys()->first();

                return ['model' => $model, 'devices' => $list, 'counts' => $counts, 'main' => $main,
                    'odd' => $counts->count() > 1 ? $list->where('os_version', '!=', $main)->values() : collect()];
            })->sortBy([fn ($a, $b) => $b['odd']->count() <=> $a['odd']->count(), fn ($a, $b) => strcmp($a['model'], $b['model'])])->values();

        $clocks = DeviceSensor::where('class', 'clock_skew')->whereIn('device_id', $devices->pluck('id'))->get()->keyBy('device_id');
        $skewed = $devices->filter(fn ($d) => isset($clocks[$d->id]) && $clocks[$d->id]->status !== 'ok')
            ->sortByDesc(fn ($d) => abs((float) $clocks[$d->id]->value))->values();

        return view('versions.index', [
            'groups' => $groups, 'clocks' => $clocks, 'skewed' => $skewed, 'sites' => Site::orderBy('code')->get(),
            'unknown' => $devices->filter(fn ($d) => ! $d->os_version)->count(), 'total' => $devices->count(),
            'noClock' => $devices->filter(fn ($d) => $d->snmp_ok && ! isset($clocks[$d->id]))->count(),
        ]);
    }

    /** "+75 detik", "-3 menit 20 detik" */
    public static function skew(?float $s): string
    {
        if ($s === null) {
            return '';
        }
        $a = (int) round(abs($s));
        $sign = $s < 0 ? 'lambat ' : 'cepat ';
        if ($a < 60) {
            return $sign . $a . ' detik';
        }
        if ($a < 3600) {
            return $sign . intdiv($a, 60) . ' menit' . ($a % 60 ? ' ' . $a % 60 . ' detik' : '');
        }

        return $sign . intdiv($a, 3600) . ' jam' . (intdiv($a % 3600, 60) ? ' ' . intdiv($a % 3600, 60) . ' menit' : '');
    }
}
