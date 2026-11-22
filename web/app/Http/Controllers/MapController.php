<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Topology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Peta lokasi: site & perangkat berkoordinat di atas peta (Leaflet). Gambar peta diambil browser dari server tile
 * yang diatur admin (bawaan OpenStreetMap; bisa server tile internal untuk lokasi tanpa internet, atau kosong).
 */
class MapController extends Controller
{
    public function index()
    {
        return view('map.index', [
            'tileUrl' => (string) Setting::get('map.tile_url', ''),
            'attribution' => (string) Setting::get('map.attribution', ''),
            'sites' => Site::orderBy('code')->get(['id', 'code', 'name', 'lat', 'lng']),
            'devices' => request()->user()->can('manage-devices')
                ? Device::where('enabled', true)->with('site:id,code')->orderBy('name')->get(['id', 'name', 'host', 'site_id', 'lat', 'lng'])
                : collect(),
            'unplaced' => Device::where('enabled', true)->whereNull('lat')
                ->whereDoesntHave('site', fn ($q) => $q->whereNotNull('lat'))->count(),
        ]);
    }

    /** Status & posisi untuk peta (diperbarui tiap 30 detik). */
    public function data(Topology $topology): JsonResponse
    {
        $g = $topology->graph(null);
        $sites = Site::get(['id', 'code', 'name', 'lat', 'lng'])->keyBy('id');
        $devs = Device::where('enabled', true)->get(['id', 'site_id', 'lat', 'lng'])->keyBy('id');

        $siteAgg = [];
        $devices = [];
        $pos = [];
        foreach ($g['nodes'] as $n) {
            $d = $devs[$n['id']] ?? null;
            if (! $d) {
                continue;
            }
            $s = $d->site_id ? ($sites[$d->site_id] ?? null) : null;
            if ($s) {
                $a = &$siteAgg[$s->id];
                $a ??= ['total' => 0, 'down' => 0, 'problem' => 0, 'maintenance' => 0, 'down_list' => []];
                $a['total']++;
                if ($n['state'] === 'down') {
                    $a['down']++;
                    if (count($a['down_list']) < 8) {
                        $a['down_list'][] = ['name' => $n['label'], 'url' => $n['url']];
                    }
                } elseif (in_array($n['state'], ['problem', 'maintenance'], true)) {
                    $a[$n['state']]++;
                }
                unset($a);
            }
            // posisi efektif: koordinat perangkat sendiri, atau posisi site
            if ($d->lat !== null && $d->lng !== null) {
                $pos[$d->id] = [$d->lat, $d->lng];
                $devices[] = ['id' => $d->id, 'name' => $n['label'], 'host' => $n['host'], 'site' => $n['site'], 'category' => $n['category'],
                    'state' => $n['state'], 'lat' => $d->lat, 'lng' => $d->lng, 'url' => $n['url'], 'reason' => $n['reason']];
            } elseif ($s && $s->lat !== null) {
                $pos[$d->id] = [$s->lat, $s->lng];
            }
        }

        $outSites = [];
        foreach ($sites as $s) {
            $a = $siteAgg[$s->id] ?? ['total' => 0, 'down' => 0, 'problem' => 0, 'maintenance' => 0, 'down_list' => []];
            $state = $a['total'] === 0 ? 'idle' : ($a['down'] > 0 ? 'down' : ($a['problem'] > 0 ? 'problem' : ($a['maintenance'] > 0 ? 'maintenance' : 'up')));
            $outSites[] = ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'lat' => $s->lat, 'lng' => $s->lng, 'state' => $state,
                'url' => route('devices.index', ['site' => $s->id])] + $a;
        }

        // Sambungan antarperangkat yang posisinya berbeda (radio PTP, uplink antarsite)
        $links = [];
        $seen = [];
        foreach ($g['edges'] as $e) {
            if (! is_int($e['from']) || ! is_int($e['to']) || ! isset($pos[$e['from']], $pos[$e['to']])) {
                continue;
            }
            [$a, $b] = [$pos[$e['from']], $pos[$e['to']]];
            if (abs($a[0] - $b[0]) < 1e-6 && abs($a[1] - $b[1]) < 1e-6) {
                continue;
            }
            $k = min($e['from'], $e['to']) . '-' . max($e['from'], $e['to']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $links[] = ['from' => $e['from'], 'to' => $e['to'], 'a' => $a, 'b' => $b, 'down' => (bool) ($e['down'] ?? false),
                'logical' => ($e['sources'] ?? []) === ['parent'], 'util' => $e['util'] ?? null];
        }

        return response()->json(['sites' => $outSites, 'devices' => $devices, 'links' => $links, 'at' => now()->format('H:i:s')]);
    }

    /** Simpan/hapus koordinat site atau perangkat (geser di peta / pilih titik). */
    public function position(Request $request): JsonResponse
    {
        $d = $request->validate([
            'type' => ['required', 'in:site,device'],
            'id' => ['required', 'integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $model = $d['type'] === 'site' ? Site::findOrFail($d['id']) : Device::findOrFail($d['id']);
        $lat = isset($d['lat']) ? round((float) $d['lat'], 6) : null;
        $lng = $lat !== null ? round((float) $d['lng'], 6) : null;
        $model->forceFill(['lat' => $lat, 'lng' => $lng])->save();
        $label = $d['type'] === 'site' ? "Site {$model->code}" : "Perangkat {$model->name}";
        Audit::log('updated', $lat === null ? "{$label}: posisi peta dihapus" : "{$label}: posisi peta {$lat}, {$lng}");

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request)
    {
        $d = $request->validate([
            'tile_url' => ['nullable', 'string', 'max:300', 'regex:/^https?:\/\/[^\s"<>]+\{z\}[^\s"<>]*\{x\}[^\s"<>]*\{y\}[^\s"<>]*$/'],
            'attribution' => ['nullable', 'string', 'max:200'],
        ], ['tile_url.regex' => 'URL tile harus http(s) dan memuat {z}, {x}, {y} — mis. https://tile.openstreetmap.org/{z}/{x}/{y}.png']);
        Setting::put('map.tile_url', $d['tile_url'] ?? '');
        Setting::put('map.attribution', $d['attribution'] ?? '');
        Audit::log('updated', 'Pengaturan sumber peta diubah');

        return back()->with('ok', 'Pengaturan peta disimpan.');
    }
}
