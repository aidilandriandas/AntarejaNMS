<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Trigger;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Kelola trigger custom. Penilaian dilakukan collector (internal/problems/triggers.go). */
class TriggerController extends Controller
{
    public function index()
    {
        $triggers = Trigger::with(['site', 'devices:id'])->orderBy('name')->get();
        $open = DB::table('problems')->where('kind', 'custom')->whereNull('resolved_at')
            ->selectRaw("split_part(sensor_key, ':', 2)::bigint AS tid, count(*) AS n")->groupBy('tid')->pluck('n', 'tid');

        return view('triggers.index', ['triggers' => $triggers, 'open' => $open]);
    }

    public function create()
    {
        return $this->form(new Trigger(['enabled' => true, 'severity' => 'average', 'metric' => 'port_in', 'op' => '<', 'duration_min' => 5, 'scope' => 'all', 'days' => 127]));
    }

    public function edit(Trigger $trigger)
    {
        return $this->form($trigger->load('devices:id'));
    }

    private function form(Trigger $t)
    {
        return view('triggers.form', ['t' => $t, 'sites' => Site::orderBy('code')->get(['id', 'code', 'name']),
            'devices' => Device::with('site:id,code')->orderBy('name')->get(['id', 'name', 'host', 'site_id'])]);
    }

    private function data(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'severity' => ['required', Rule::in(array_keys(Problem::SEVERITIES))],
            'metric' => ['required', Rule::in(array_keys(Trigger::METRICS))],
            'sensor_class' => ['nullable', 'required_if:metric,sensor', Rule::in(array_keys(Trigger::SENSOR_CLASSES))],
            'match_text' => ['nullable', 'string', 'max:120'],
            'op' => ['required', Rule::in(array_keys(Trigger::OPS))],
            'threshold' => ['required', 'numeric', 'between:-100000,1000000'],
            'duration_min' => ['required', 'integer', 'between:0,1440'],
            'scope' => ['required', Rule::in(['all', 'site', 'category', 'devices', 'template'])],
            'template_id' => ['nullable', 'required_if:scope,template', 'integer', 'exists:device_templates,id'],
            'site_id' => ['nullable', 'required_if:scope,site', 'integer', 'exists:sites,id'],
            'category' => ['nullable', 'required_if:scope,category', Rule::in(array_keys(Device::CATEGORIES))],
            'devices' => ['nullable', 'required_if:scope,devices', 'array', 'max:500'], 'devices.*' => ['integer', 'exists:devices,id'],
            'days' => ['required', 'array', 'min:1'], 'days.*' => ['integer', 'between:0,6'],
            'start' => ['nullable', 'date_format:H:i', 'required_with:end'],
            'end' => ['nullable', 'date_format:H:i', 'required_with:start', 'different:start'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['days.required' => 'Pilih minimal satu hari.', 'end.different' => 'Jam selesai harus berbeda dengan jam mulai.'],
            ['name' => 'nama', 'threshold' => 'ambang', 'duration_min' => 'durasi', 'site_id' => 'site', 'category' => 'kategori', 'devices' => 'perangkat', 'start' => 'jam mulai', 'end' => 'jam selesai']);

        $min = fn ($hm) => $hm === null ? null : ((int) substr($hm, 0, 2)) * 60 + (int) substr($hm, 3, 2);

        return [
            'name' => $d['name'], 'severity' => $d['severity'], 'metric' => $d['metric'],
            'sensor_class' => $d['metric'] === 'sensor' ? $d['sensor_class'] : null,
            'match_text' => in_array($d['metric'], ['sensor', 'port_in', 'port_out', 'port_util', 'port_errors', 'port_discards'], true) ? (trim((string) ($d['match_text'] ?? '')) ?: null) : null,
            'op' => $d['op'], 'threshold' => $d['threshold'], 'duration_min' => $d['duration_min'], 'scope' => $d['scope'],
            'site_id' => $d['scope'] === 'site' ? $d['site_id'] : null, 'category' => $d['scope'] === 'category' ? $d['category'] : null,
            'template_id' => $d['scope'] === 'template' ? $d['template_id'] : null,
            'days' => array_sum(array_map(fn ($i) => 1 << (int) $i, array_unique($d['days']))),
            'start_min' => $min($d['start'] ?? null), 'end_min' => ($e = $min($d['end'] ?? null)) === 0 ? 1440 : $e,
            'note' => $d['note'] ?? null,
            '_devices' => $d['scope'] === 'devices' ? $d['devices'] : [],
        ];
    }

    public function store(Request $request)
    {
        $d = $this->data($request);
        $devs = $d['_devices'];
        unset($d['_devices']);
        $t = DB::transaction(function () use ($d, $devs, $request) {
            $t = Trigger::create($d + ['created_by' => $request->user()->id, 'enabled' => true]);
            $t->devices()->sync($devs);

            return $t;
        });
        Audit::log('created', "Trigger {$t->name}: {$t->conditionText()}");

        return redirect()->route('triggers.index')->with('ok', "Trigger {$t->name} dibuat. Collector menilainya mulai putaran berikutnya.");
    }

    public function update(Request $request, Trigger $trigger)
    {
        $d = $this->data($request);
        $devs = $d['_devices'];
        unset($d['_devices']);
        DB::transaction(function () use ($trigger, $d, $devs) {
            $trigger->update($d);
            $trigger->devices()->sync($devs);
        });
        Audit::log('updated', "Trigger {$trigger->name}: {$trigger->conditionText()}");

        return redirect()->route('triggers.index')->with('ok', "Trigger {$trigger->name} disimpan.");
    }

    public function toggle(Trigger $trigger)
    {
        $trigger->update(['enabled' => ! $trigger->enabled]);
        Audit::log('updated', "Trigger {$trigger->name} " . ($trigger->enabled ? 'diaktifkan' : 'dimatikan'));

        return back()->with('ok', "Trigger {$trigger->name} " . ($trigger->enabled ? 'aktif.' : 'dimatikan. Problem-nya ditutup pada putaran berikutnya.'));
    }

    public function destroy(Trigger $trigger)
    {
        $trigger->delete();
        Audit::log('deleted', "Trigger {$trigger->name} dihapus");

        return redirect()->route('triggers.index')->with('ok', "Trigger {$trigger->name} dihapus. Problem-nya ditutup pada putaran berikutnya.");
    }
}
