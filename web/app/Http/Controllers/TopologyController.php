<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Collector;
use App\Services\Topology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TopologyController extends Controller
{
    public function index()
    {
        return view('topology.index', [
            'sites' => Site::orderBy('code')->get(),
            'devices' => Device::where('enabled', true)->orderBy('name')->get(['id', 'name', 'host']),
            'links' => DB::table('device_links as l')->join('devices as a', 'a.id', '=', 'l.a_device_id')->join('devices as b', 'b.id', '=', 'l.b_device_id')
                ->leftJoin('interfaces as ia', fn ($j) => $j->on('ia.device_id', '=', 'l.a_device_id')->on('ia.if_index', '=', 'l.a_if_index'))
                ->leftJoin('interfaces as ib', fn ($j) => $j->on('ib.device_id', '=', 'l.b_device_id')->on('ib.if_index', '=', 'l.b_if_index'))
                ->orderBy('a.name')->get(['l.id', 'a.name as a_name', 'ia.name as a_port', 'b.name as b_name', 'ib.name as b_port', 'l.note']),
        ]);
    }

    public function data(Request $request, Topology $topology): JsonResponse
    {
        return response()->json($topology->graph($request->integer('site') ?: null));
    }

    /** Trafik live: counter port saat ini dari collector (browser menghitung laju dari dua pembacaan). */
    public function live(Request $request, Collector $collector): JsonResponse
    {
        $d = $request->validate([
            'ports' => ['required', 'array', 'max:60'],
            'ports.*.device_id' => ['required', 'integer'],
            'ports.*.if_index' => ['required', 'integer'],
        ]);
        try {
            return response()->json(['samples' => $collector->live($d['ports'])]);
        } catch (\Throwable) {
            return response()->json(['samples' => []]);
        }
    }

    /** Port perangkat untuk form sambungan manual. */
    public function ports(Device $device): JsonResponse
    {
        return response()->json($device->ports()->get(['if_index', 'name', 'alias', 'oper_up'])->map(fn ($p) => [
            'if_index' => $p->if_index, 'label' => $p->name . ($p->alias ? " ({$p->alias})" : '') . ($p->oper_up ? '' : ', down'),
        ]));
    }

    public function storeLink(Request $request)
    {
        $d = $request->validate([
            'a_device_id' => ['required', 'integer', 'exists:devices,id'],
            'a_if_index' => ['nullable', 'integer'],
            'b_device_id' => ['required', 'integer', 'exists:devices,id', 'different:a_device_id'],
            'b_if_index' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['b_device_id.different' => 'Pilih dua perangkat yang berbeda.']);
        $id = DB::table('device_links')->insertGetId($d + ['created_by' => $request->user()->id, 'created_at' => now()]);
        $names = Device::whereIn('id', [$d['a_device_id'], $d['b_device_id']])->pluck('name', 'id');
        Audit::log('created', "Sambungan manual #{$id}: {$names[$d['a_device_id']]} ↔ {$names[$d['b_device_id']]}");

        return back()->with('ok', "Sambungan {$names[$d['a_device_id']]} ↔ {$names[$d['b_device_id']]} ditambahkan.");
    }

    public function destroyLink(int $link)
    {
        abort_unless(DB::table('device_links')->where('id', $link)->delete(), 404);
        Audit::log('deleted', "Sambungan manual #{$link} dihapus");

        return back()->with('ok', 'Sambungan dihapus.');
    }

    public function savePositions(Request $request): JsonResponse
    {
        $d = $request->validate(['positions' => ['required', 'array', 'max:5000'], 'positions.*' => ['array', 'size:2'], 'positions.*.*' => ['numeric']]);
        $pos = [];
        foreach ($d['positions'] as $id => [$x, $y]) {
            if (ctype_digit((string) $id)) {
                $pos[(int) $id] = [round((float) $x), round((float) $y)];
            }
        }
        Setting::put('topology.positions', array_replace((array) Setting::get('topology.positions', []), $pos));
        Audit::log('updated', 'Tata letak peta topologi disimpan (' . count($pos) . ' perangkat)');

        return response()->json(['ok' => true]);
    }

    /** Tombol "Sinkron LLDP sekarang": baca ulang tetangga LLDP/CDP semua perangkat SNMP tanpa menunggu jadwal. */
    public function sync(\App\Services\Collector $collector)
    {
        $r = $collector->syncTopology();
        if ($r === 'started') {
            \App\Services\Audit::log('updated', 'Sinkron topologi LLDP/CDP dijalankan manual');
        }

        return back()->with($r === 'down' ? 'error' : 'ok', match ($r) {
            'started' => 'Sinkron dimulai. Semua perangkat SNMP dibaca ulang; peta diperbarui sendiri dalam 1 sampai 2 menit.',
            'busy' => 'Putaran SNMP sedang berjalan. Hasilnya muncul di peta begitu selesai, tidak perlu menekan lagi.',
            default => 'Collector tidak dapat dihubungi, sinkron tidak dijalankan.',
        });
    }
}
