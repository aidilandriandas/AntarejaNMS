<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceSensor;
use App\Models\Site;
use Illuminate\Http\Request;

/** Ringkasan sensor lintas perangkat: radio/PTP, daya/UPS, dan kesehatan sistem — satu baris per perangkat. */
class SensorController extends Controller
{
    /** Kolom per tab: [judul kolom, kelas sensor, cara memilih bila banyak: worst|first|sum|max] */
    public const TABS = [
        'radio' => ['label' => 'Radio & PTP', 'cols' => [
            ['Sinyal', 'signal', 'worst'], ['CCQ', 'ccq', 'worst'], ['Kualitas', 'quality', 'worst'], ['Kapasitas', 'capacity', 'worst'],
            ['Noise', 'noise', 'first'], ['TX', 'rate', 'first'], ['Klien', 'clients', 'sum'], ['Frekuensi', 'frequency', 'first'], ['Jarak', 'distance', 'first'],
            ['RSRP LTE', 'lte_rsrp', 'worst'], ['SINR LTE', 'lte_sinr', 'worst'],
        ]],
        'daya' => ['label' => 'Daya & UPS', 'cols' => [
            ['Status', 'ups_status', 'worst'], ['Baterai', 'battery', 'worst'], ['Cadangan', 'runtime', 'worst'], ['Beban', 'load', 'max'], ['Tegangan', 'voltage', 'worst'],
            ['Arus', 'current', 'first'], ['Daya', 'power', 'first'], ['Bahan bakar', 'fuel', 'worst'],
        ]],
        'sistem' => ['label' => 'Sistem & suhu', 'cols' => [
            ['CPU', 'cpu', 'worst'], ['Memori', 'memory', 'worst'], ['Disk', 'disk', 'worst'], ['Suhu', 'temperature', 'max'], ['Kipas', 'fan', 'first'],
        ]],
    ];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'radio';
        $classes = array_column(self::TABS[$tab]['cols'], 1);
        $sensors = DeviceSensor::whereIn('class', $classes)
            ->whereHas('device', fn ($q) => $q->where('enabled', true)->when($request->integer('site'), fn ($q, $s) => $q->where('site_id', $s)))
            ->get()->groupBy('device_id');
        $devices = Device::with('site')->whereIn('id', $sensors->keys())->orderBy('name')->get();
        $rank = ['crit' => 0, 'warn' => 1, 'ok' => 2];

        $rows = $devices->map(function ($d) use ($sensors, $tab, $rank) {
            $mine = $sensors[$d->id]->groupBy('class');
            $cells = [];
            $worst = 'ok';
            foreach (self::TABS[$tab]['cols'] as [$title, $class, $how]) {
                $list = $mine[$class] ?? collect();
                $cell = match (true) {
                    $list->isEmpty() => null,
                    $how === 'sum' => ['text' => (string) (int) $list->sum('value'), 'status' => 'ok', 'n' => $list->count()],
                    $how === 'max' => $list->sortByDesc('value')->first(),
                    $how === 'worst' => $list->sortBy(fn ($s) => [$rank[$s->status] ?? 3, in_array($class, ['signal', 'lte_rsrp', 'lte_sinr', 'battery', 'runtime', 'ccq', 'quality', 'fuel'], true) ? $s->value : -$s->value])->first(),
                    default => $list->first(),
                };
                if ($cell instanceof DeviceSensor) {
                    $cell = ['text' => $cell->display(), 'status' => $cell->status, 'n' => $list->count(), 'label' => $cell->label];
                }
                if ($cell && ($rank[$cell['status']] ?? 2) < $rank[$worst]) {
                    $worst = $cell['status'];
                }
                $cells[] = $cell;
            }

            return ['device' => $d, 'cells' => $cells, 'worst' => $worst];
        })->sortBy(fn ($r) => [$rank[$r['worst']], $r['device']->name])->values();

        return view('sensors.index', ['tab' => $tab, 'rows' => $rows, 'sites' => Site::orderBy('code')->get(),
            'counts' => collect(self::TABS)->map(fn ($t) => DeviceSensor::whereIn('class', array_column($t['cols'], 1))->distinct()->count('device_id'))]);
    }
}
