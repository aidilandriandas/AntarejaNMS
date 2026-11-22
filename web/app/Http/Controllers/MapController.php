<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Topology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Peta lokasi: site & perangkat berkoordinat di atas peta (Leaflet). Gambar peta diambil browser dari server tile
 * yang diatur admin (bawaan OpenStreetMap; bisa server tile internal untuk lokasi tanpa internet, atau kosong).
 */
class MapController extends Controller
{
    public function index(Request $request)
    {
        $plans = DB::table('map_plans')->orderBy('name')->get(['id', 'name', 'width', 'height', 'updated_at']);
        $plan = $request->integer('denah') ? $plans->firstWhere('id', $request->integer('denah')) : null;
        $sites = Site::orderBy('code')->get(['id', 'code', 'name', 'lat', 'lng']);
        $devices = $request->user()->can('manage-devices')
            ? Device::where('enabled', true)->with('site:id,code')->orderBy('name')->get(['id', 'name', 'host', 'site_id', 'lat', 'lng'])
            : collect();
        if ($plan) {
            $this->applyPlan($plan, $sites, $devices);
        }

        return view('map.index', [
            'plans' => $plans,
            'plan' => $plan,
            'tileUrl' => (string) Setting::get('map.tile_url', ''),
            'attribution' => (string) Setting::get('map.attribution', ''),
            'sites' => $sites,
            'devices' => $devices,
            'unplaced' => Device::where('enabled', true)->whereNull('lat')
                ->whereDoesntHave('site', fn ($q) => $q->whereNotNull('lat'))->count(),
        ]);
    }

    /** Status & posisi untuk peta (diperbarui tiap 30 detik). */
    public function data(Request $request, Topology $topology): JsonResponse
    {
        $g = $topology->graph(null);
        $sites = Site::get(['id', 'code', 'name', 'lat', 'lng'])->keyBy('id');
        $devs = Device::where('enabled', true)->get(['id', 'site_id', 'lat', 'lng'])->keyBy('id');
        if ($request->integer('denah')) {
            $plan = DB::table('map_plans')->where('id', $request->integer('denah'))->first(['id', 'width', 'height']);
            abort_unless($plan, 404);
            $this->applyPlan($plan, $sites, $devs);
        }

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
            'plan_id' => ['nullable', 'integer'],
            'x' => ['nullable', 'numeric', 'min:0', 'required_with:y'],
            'y' => ['nullable', 'numeric', 'min:0', 'required_with:x'],
        ]);
        $model = $d['type'] === 'site' ? Site::findOrFail($d['id']) : Device::findOrFail($d['id']);
        $label = $d['type'] === 'site' ? "Site {$model->code}" : "Perangkat {$model->name}";
        if (! empty($d['plan_id'])) {
            $plan = DB::table('map_plans')->where('id', $d['plan_id'])->first(['id', 'name', 'width', 'height']);
            abort_unless($plan, 404);
            $key = ['plan_id' => $plan->id, 'kind' => $d['type'], 'ref_id' => $model->id];
            if (isset($d['x'])) {
                $x = round(min((float) $d['x'], $plan->width), 1);
                $y = round(min((float) $d['y'], $plan->height), 1);
                DB::table('map_plan_points')->updateOrInsert($key, ['x' => $x, 'y' => $y]);
                Audit::log('updated', "{$label}: posisi di denah \"{$plan->name}\" ({$x}, {$y})");
            } else {
                DB::table('map_plan_points')->where($key)->delete();
                Audit::log('updated', "{$label}: posisi di denah \"{$plan->name}\" dihapus");
            }

            return response()->json(['ok' => true]);
        }
        $lat = isset($d['lat']) ? round((float) $d['lat'], 6) : null;
        $lng = $lat !== null ? round((float) $d['lng'], 6) : null;
        $model->forceFill(['lat' => $lat, 'lng' => $lng])->save();
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

    /** Ganti koordinat peta dunia dengan posisi di denah (lat = tinggi − y, lng = x; CRS sederhana Leaflet). */
    private function applyPlan(object $plan, $sites, $devices): void
    {
        $pts = DB::table('map_plan_points')->where('plan_id', $plan->id)->get()->keyBy(fn ($p) => $p->kind . ':' . $p->ref_id);
        foreach ([['site', $sites], ['device', $devices]] as [$kind, $list]) {
            foreach ($list as $m) {
                $p = $pts[$kind . ':' . $m->id] ?? null;
                $m->setAttribute('lat', $p ? $plan->height - $p->y : null);
                $m->setAttribute('lng', $p ? (float) $p->x : null);
            }
        }
    }

    public function planStore(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'image' => ['required', 'file', 'max:15360', 'mimetypes:image/png,image/jpeg,image/webp'],
        ], ['image.max' => 'Ukuran gambar maksimal 15 MB.', 'image.mimetypes' => 'Gunakan gambar PNG, JPG, atau WebP.'], ['name' => 'nama denah', 'image' => 'gambar']);
        $path = $d['image']->getRealPath();
        $info = @getimagesize($path);
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || $info[0] > 16000 || $info[1] > 16000) {
            return back()->withErrors(['image' => 'Gambar tidak bisa dibaca atau lebih dari 16.000 piksel.'])->withInput();
        }
        $bytes = file_get_contents($path);
        $id = DB::selectOne("INSERT INTO map_plans (name, mime, width, height, sha256, data) VALUES (?, ?, ?, ?, ?, decode(?, 'base64')) RETURNING id",
            [$d['name'], $info['mime'], $info[0], $info[1], hash('sha256', $bytes), base64_encode($bytes)])->id;
        Audit::log('created', "Denah peta \"{$d['name']}\" diunggah ({$info[0]}×{$info[1]} px)");

        return redirect()->route('map.index', ['denah' => $id])->with('ok', 'Denah diunggah. Tekan "Atur posisi" untuk menempatkan site/perangkat.');
    }

    public function planImage(int $plan)
    {
        $p = DB::table('map_plans')->where('id', $plan)->first(['mime', 'sha256']);
        abort_unless($p, 404);
        $etag = '"' . $p->sha256 . '"';
        if (request()->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }
        $data = DB::selectOne('SELECT encode(data, \'base64\') AS b FROM map_plans WHERE id = ?', [$plan])->b;

        return response(base64_decode(str_replace(["\n", "\r"], '', $data)), 200, [
            'Content-Type' => $p->mime, 'ETag' => $etag, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function planDestroy(int $plan)
    {
        $name = DB::table('map_plans')->where('id', $plan)->value('name');
        abort_unless($name !== null, 404);
        DB::table('map_plans')->where('id', $plan)->delete();
        Audit::log('deleted', "Denah peta \"{$name}\" dihapus");

        return redirect()->route('map.index')->with('ok', 'Denah dihapus.');
    }
}
