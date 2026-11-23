<?php

namespace App\Http\Controllers;

use App\Services\Impact;
use Illuminate\Http\Request;

/** Peta dampak (semua peran, hanya baca): dampak gangguan yang sedang terjadi dan simulasi bila perangkat mati. */
class ImpactController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['sim' => ['nullable', 'array', 'max:20'], 'sim.*' => ['integer']]);
        $impact = new Impact;
        $now = collect($impact->downRoots())->map(fn ($id) => $impact->of([$id]))
            ->sortByDesc(fn ($r) => count($r['devices']) * 10 + $r['cameras']->count() + count($r['services']) * 50)->values();
        $all = $impact->of($impact->downRoots());
        $sim = array_values(array_filter(array_map('intval', (array) $request->query('sim', []))));

        return view('impact.index', [
            'now' => $now, 'all' => $all,
            'sim' => $sim ? $impact->of($sim) : null, 'simIds' => $sim,
            'candidates' => $impact->candidates(),
        ]);
    }
}
