<?php

namespace App\Http\Controllers;

use App\Support\Wireless;
use Illuminate\Http\Request;

/** Ringkasan semua link radio Ubiquiti: sinyal terlemah dan yang terputus di atas. */
class WirelessController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'kondisi' => ['nullable', 'in:lemah,putus,belum']]);
        $q = Wireless::query()
            ->when(isset($f['q']) ? addcslashes($f['q'], '%_\\') : null, fn ($q, $t) => $q->where(fn ($q) => $q->where('d.name', 'ilike', "%{$t}%")->orWhere('r.name', 'ilike', "%{$t}%")
                ->orWhere('w.name', 'ilike', "%{$t}%")->orWhere('w.ip', $t)->orWhere('w.mac', 'ilike', "%{$t}%")))
            ->when(($f['kondisi'] ?? null) === 'lemah', fn ($q) => $q->where('w.connected', true)->where('w.signal_dbm', '<=', Wireless::SIGNAL_WARN))
            ->when(($f['kondisi'] ?? null) === 'putus', fn ($q) => $q->where('w.connected', false))
            ->when(($f['kondisi'] ?? null) === 'belum', fn ($q) => $q->where('w.connected', true)->whereNull('w.remote_device_id'));
        $links = $q->orderBy('w.connected')->orderByRaw('w.signal_dbm ASC NULLS LAST')->orderBy('d.name')->limit(500)->get();
        $all = Wireless::query()->get(['w.device_id', 'w.mac', 'w.connected', 'w.signal_dbm', 'w.remote_device_id']);
        // Satu link fisik bisa dilaporkan dua sisi (AP melihat station, station melihat AP): hitung per pasangan perangkat
        $pair = fn ($w) => $w->remote_device_id ? min($w->device_id, $w->remote_device_id) . '-' . max($w->device_id, $w->remote_device_id) : "{$w->device_id}/{$w->mac}";
        $up = $all->where('connected', true);

        return view('wireless.index', ['links' => $links, 'f' => $f, 'stats' => [
            'total' => $up->unique($pair)->count(),
            'weak' => $up->filter(fn ($w) => $w->signal_dbm !== null && $w->signal_dbm <= Wireless::SIGNAL_WARN)->unique($pair)->count(),
            'lost' => $all->where('connected', false)->unique($pair)->count(),
            'unknown' => $up->whereNull('remote_device_id')->count(),
        ]]);
    }
}
