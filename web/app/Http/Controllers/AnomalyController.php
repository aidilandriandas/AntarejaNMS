<?php

namespace App\Http\Controllers;

use App\Models\Anomaly;
use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnomalyController extends Controller
{
    public function index(Request $request)
    {
        $active = Anomaly::with('device.site')->whereNull('ended_at')->orderByDesc('started_at')->get();
        $history = Anomaly::with(['device:id,name', 'dismisser:id,name'])->whereNotNull('ended_at')
            ->where('started_at', '>=', now()->subDays(30))->orderByDesc('started_at')->limit(100)->get();
        $week = Anomaly::where('started_at', '>=', now()->subDays(7));

        return view('anomalies.index', [
            'active' => $active, 'history' => $history,
            'weekN' => (clone $week)->count(),
            'weekDev' => (clone $week)->distinct('device_id')->count('device_id'),
            'enabled' => (bool) Setting::get('anomaly.enabled', true),
            'severity' => (string) Setting::get('anomaly.problem_severity', 'warning'),
            'mutes' => DB::table('anomaly_mutes as m')->join('devices as d', 'd.id', '=', 'm.device_id')->where('m.until', '>', now())
                ->orderBy('m.until')->get(['m.device_id', 'm.metric', 'm.if_index', 'm.until', 'd.name']),
        ]);
    }

    /** Tandai wajar: tutup anomali dan jangan catat yang sama selama durasi pilihan. */
    public function dismiss(Request $request, Anomaly $anomaly)
    {
        $d = $request->validate(['hours' => ['required', Rule::in(array_keys(Anomaly::MUTES))], 'note' => ['nullable', 'string', 'max:255']]);
        DB::transaction(function () use ($anomaly, $d, $request) {
            $anomaly->forceFill(['ended_at' => $anomaly->ended_at ?? now(), 'dismissed_by' => $request->user()->id, 'dismiss_note' => $d['note'] ?? null])->save();
            DB::table('anomaly_mutes')->upsert([[
                'device_id' => $anomaly->device_id, 'metric' => $anomaly->metric, 'if_index' => $anomaly->if_index ?? -1,
                'until' => now()->addHours((int) $d['hours']), 'created_by' => $request->user()->id,
            ]], ['device_id', 'metric', 'if_index'], ['until', 'created_by']);
        });
        $anomaly->load('device');
        Audit::log('updated', "Anomali {$anomaly->label()} di {$anomaly->device?->name} ditandai wajar, diabaikan " . Anomaly::MUTES[$d['hours']], $anomaly->device);

        return back()->with('ok', 'Anomali ditandai wajar. Yang sama tidak dicatat selama ' . Anomaly::MUTES[$d['hours']] . '. Problem-nya tertutup pada penilaian berikutnya.');
    }

    public function unmute(Request $request)
    {
        $d = $request->validate(['device_id' => ['required', 'integer'], 'metric' => ['required', Rule::in(array_keys(Anomaly::METRICS))], 'if_index' => ['required', 'integer']]);
        DB::table('anomaly_mutes')->where($d)->delete();
        Audit::log('updated', "Pengabaian anomali {$d['metric']} perangkat #{$d['device_id']} dicabut");

        return back()->with('ok', 'Pengabaian dicabut.');
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['problem_severity' => ['required', Rule::in(array_keys(Anomaly::SEVERITIES))]]);
        Setting::put('anomaly.enabled', $request->boolean('enabled'));
        Setting::put('anomaly.problem_severity', $d['problem_severity']);
        Audit::log('updated', 'Setelan deteksi anomali: ' . ($request->boolean('enabled') ? 'aktif' : 'mati') . ', problem ' . $d['problem_severity']);

        return back()->with('ok', 'Setelan anomali disimpan. Berlaku pada putaran berikutnya (paling lama 5 menit).');
    }
}
