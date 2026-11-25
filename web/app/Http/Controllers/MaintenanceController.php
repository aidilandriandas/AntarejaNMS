<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Maintenance;
use App\Models\Site;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    public function index()
    {
        $now = now();

        return view('maintenance.index', [
            'active' => Maintenance::with('site', 'devices', 'creator')->where('ends_at', '>', $now)->orderBy('starts_at')->get(),
            'past' => Maintenance::with('site', 'devices', 'creator')->where('ends_at', '<=', $now)->orderByDesc('ends_at')->limit(30)->get(),
            'sites' => Site::orderBy('code')->get(),
            'devices' => Device::orderBy('name')->get(['id', 'name', 'host']),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'scope' => ['required', Rule::in(['all', 'site', 'devices'])],
            'site_id' => ['required_if:scope,site', 'nullable', 'integer', 'exists:sites,id'],
            'device_ids' => ['required_if:scope,devices', 'array'],
            'device_ids.*' => ['integer', 'exists:devices,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ], [
            'site_id.required_if' => 'Pilih site.',
            'device_ids.required_if' => 'Pilih minimal satu perangkat.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
        ]);
        $tz = config('app.timezone');
        $m = Maintenance::create([
            'title' => $d['title'], 'note' => $d['note'] ?? null, 'scope' => $d['scope'],
            'site_id' => $d['scope'] === 'site' ? $d['site_id'] : null,
            'starts_at' => Carbon::parse($d['starts_at'], $tz), 'ends_at' => Carbon::parse($d['ends_at'], $tz),
            'created_by' => $request->user()->id,
        ]);
        if ($d['scope'] === 'devices') {
            $m->devices()->sync($d['device_ids']);
        }
        $m->load('site', 'devices');
        Audit::log('created', "Maintenance \"{$m->title}\" ({$m->scopeLabel()}) {$m->starts_at->format('d/m H:i')} sampai {$m->ends_at->format('d/m H:i')}", $m);

        return back()->with('ok', "Maintenance \"{$m->title}\" dijadwalkan. Problem di dalamnya tidak dinotifikasi.");
    }

    public function finish(Maintenance $maintenance)
    {
        abort_if($maintenance->ends_at <= now(), 422);
        $maintenance->update(['ends_at' => now()->max($maintenance->starts_at->copy()->addSecond())]);
        Audit::log('updated', "Maintenance \"{$maintenance->title}\" diakhiri lebih awal", $maintenance);

        return back()->with('ok', "Maintenance \"{$maintenance->title}\" diakhiri.");
    }

    public function destroy(Maintenance $maintenance)
    {
        $maintenance->delete();
        Audit::log('deleted', "Maintenance \"{$maintenance->title}\" dihapus", $maintenance);

        return back()->with('ok', 'Maintenance dihapus.');
    }
}
