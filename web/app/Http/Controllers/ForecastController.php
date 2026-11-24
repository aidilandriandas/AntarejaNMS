<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** Prediksi habis sensor (disk penuh, bahan bakar habis) dari collector; kapasitas port ada di halaman Kapasitas. */
class ForecastController extends Controller
{
    public function index()
    {
        $rows = DB::table('sensor_forecasts as f')->join('devices as d', 'd.id', '=', 'f.device_id')->leftJoin('sites as s', 's.id', '=', 'd.site_id')
            ->where('d.enabled', true)->orderByRaw('f.eta IS NULL, f.eta')->orderBy('d.name')
            ->get(['f.*', 'd.name as device', 's.code as site']);

        return view('forecast.index', ['rows' => $rows]);
    }

    /** "3 hari", "14 jam", "kurang dari 1 jam" */
    public static function left(?string $eta): string
    {
        if (! $eta) {
            return 'tidak menuju batas';
        }
        $h = (strtotime($eta) - time()) / 3600;

        return match (true) {
            $h < 1 => 'kurang dari 1 jam',
            $h < 48 => (int) $h . ' jam',
            $h < 24 * 365 => (int) ($h / 24) . ' hari',
            default => 'lebih dari setahun',
        };
    }

    /** status tampilan, sama dengan batas problem di collector */
    public static function tone(object $f): string
    {
        if (! $f->eta || ($f->class === 'disk' && ($f->r2 === null || $f->r2 < 0.5))) {
            return '';
        }
        $h = (strtotime($f->eta) - time()) / 3600;

        return match ($f->class) {
            'disk' => $h <= 48 ? 'crit' : ($h <= 168 ? 'warn' : ''),
            'fuel' => $h <= 6 ? 'crit' : ($h <= 24 ? 'warn' : ''),
            default => '',
        };
    }
}
