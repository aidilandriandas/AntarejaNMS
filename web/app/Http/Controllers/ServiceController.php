<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Service;
use App\Models\ServiceComponent;
use App\Models\Site;
use App\Services\Audit;
use App\Services\ServiceSla;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** SLA per layanan: daftar, detail, kelola layanan & komponen. */
class ServiceController extends Controller
{
    private function period(Request $request): array
    {
        $m = (string) $request->query('bulan');
        $start = preg_match('/^\d{4}-\d{2}$/', $m) ? Carbon::createFromFormat('Y-m-d H:i:s', "$m-01 00:00:00", config('app.timezone')) : now()->startOfMonth();

        return [$start->copy()->startOfMonth(), $start->copy()->addMonthNoOverflow()->startOfMonth()];
    }

    private function load()
    {
        return Service::with(['site', 'components.devices:id,name,host,status,site_id,created_at'])->orderBy('name');
    }

    public function index(Request $request)
    {
        [$start, $end] = $this->period($request);
        $q = $this->load();
        if ($site = $request->integer('site')) {
            $q->where('site_id', $site);
        }
        $services = $q->get();

        return view('services.index', [
            'services' => $services, 'sla' => (new ServiceSla)->compute($services, $start, $end),
            'start' => $start, 'partial' => $end->isFuture(), 'sites' => Site::orderBy('code')->get(),
            'months' => collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i)),
        ]);
    }

    public function show(Request $request, Service $service)
    {
        [$start, $end] = $this->period($request);
        $service = $this->load()->findOrFail($service->id);
        $calc = new ServiceSla;
        $history = collect(range(11, 0))->map(function ($i) use ($calc, $service) {
            $m = now()->startOfMonth()->subMonthsNoOverflow($i);

            return ['month' => $m] + $calc->compute(collect([$service]), $m, $m->copy()->addMonthNoOverflow())[$service->id];
        })->filter(fn ($h) => $h['monitored'] > 0);

        return view('services.show', [
            's' => $service, 'sla' => $calc->compute(collect([$service]), $start, $end)[$service->id],
            'history' => $history, 'start' => $start, 'partial' => $end->isFuture(),
            'months' => collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i)),
            'devices' => Device::with('site:id,code')->orderBy('name')->get(['id', 'name', 'host', 'site_id']),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$start, $end] = $this->period($request);
        $services = $this->load()->get();
        $sla = (new ServiceSla)->compute($services, $start, $end);

        return response()->streamDownload(function () use ($services, $sla) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Layanan', 'Site', 'Target (%)', 'Ketersediaan (%)', 'Downtime (menit)', 'Jumlah gangguan', 'Gangguan terlama (menit)', 'Tanpa cadangan (menit)', 'Memenuhi target'], ';');
            $n = fn ($v) => str_replace('.', ',', (string) $v);
            foreach ($services as $s) {
                $r = $sla[$s->id];
                fputcsv($out, [$s->name, $s->site?->code, $n($s->target()), $n($r['availability']), $n(round($r['down'] / 60, 1)), $r['count'],
                    $n(round($r['longest'] / 60, 1)), $n(round($r['degraded'] / 60, 1)), $r['availability'] === null ? '' : ($r['availability'] >= $s->target() ? 'ya' : 'tidak')], ';');
            }
            fclose($out);
        }, 'sla-layanan-antareja-' . $start->format('Y-m') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rules(?Service $s = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('services', 'name')->ignore($s?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'target_pct' => ['nullable', 'numeric', 'between:50,100'],
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules(), [], ['name' => 'nama layanan', 'target_pct' => 'target']);
        $s = Service::create($d);
        Audit::log('created', "Layanan {$s->name} dibuat");

        return redirect()->route('services.show', $s)->with('ok', 'Layanan dibuat. Tambahkan komponennya.');
    }

    public function update(Request $request, Service $service)
    {
        $d = $request->validate($this->rules($service), [], ['name' => 'nama layanan', 'target_pct' => 'target']);
        $service->update($d);
        Audit::log('updated', "Layanan {$service->name} diubah");

        return back()->with('ok', 'Layanan disimpan.');
    }

    public function destroy(Service $service)
    {
        $service->delete();
        Audit::log('deleted', "Layanan {$service->name} dihapus");

        return redirect()->route('services.index')->with('ok', "Layanan {$service->name} dihapus.");
    }

    private function componentData(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'devices' => ['required', 'array', 'min:1', 'max:500'], 'devices.*' => ['integer', 'distinct', 'exists:devices,id'],
            'min_up' => ['required', 'integer', 'min:1'],
        ], ['devices.required' => 'Pilih minimal satu perangkat.'], ['name' => 'nama komponen', 'min_up' => 'minimal hidup']);
        if ($d['min_up'] > count($d['devices'])) {
            throw ValidationException::withMessages(['min_up' => 'Minimal hidup tidak boleh lebih dari jumlah perangkat (' . count($d['devices']) . ').']);
        }

        return $d;
    }

    public function storeComponent(Request $request, Service $service)
    {
        $d = $this->componentData($request);
        DB::transaction(function () use ($service, $d) {
            $c = $service->components()->create(['name' => $d['name'], 'min_up' => $d['min_up'], 'position' => (int) $service->components()->max('position') + 1]);
            $c->devices()->sync($d['devices']);
        });
        Audit::log('updated', "Layanan {$service->name}: komponen {$d['name']} ditambahkan");

        return back()->with('ok', "Komponen {$d['name']} ditambahkan.");
    }

    public function updateComponent(Request $request, Service $service, ServiceComponent $component)
    {
        abort_unless($component->service_id === $service->id, 404);
        $d = $this->componentData($request);
        DB::transaction(function () use ($component, $d) {
            $component->update(['name' => $d['name'], 'min_up' => $d['min_up']]);
            $component->devices()->sync($d['devices']);
        });
        Audit::log('updated', "Layanan {$service->name}: komponen {$d['name']} diubah");

        return back()->with('ok', "Komponen {$d['name']} disimpan.");
    }

    public function destroyComponent(Service $service, ServiceComponent $component)
    {
        abort_unless($component->service_id === $service->id, 404);
        $component->delete();
        Audit::log('updated', "Layanan {$service->name}: komponen {$component->name} dihapus");

        return back()->with('ok', "Komponen {$component->name} dihapus.");
    }

    /** Untuk tampilan: "3 jam 2 menit". */
    public static function dur(float $s): string
    {
        return $s > 0 ? Fmt::duration((int) round($s)) : '–';
    }
}
