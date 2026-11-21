<?php

namespace App\Http\Controllers;

use App\Models\Problem;
use App\Models\Site;
use App\Services\Audit;
use Illuminate\Http\Request;

class ProblemController extends Controller
{
    public function index(Request $request)
    {
        $history = $request->query('tab') === 'riwayat';
        $q = Problem::with(['device.site', 'acker']);
        $history ? $q->whereNotNull('resolved_at')->orderByDesc('resolved_at') : $q->open()->triage();
        if ($sev = $request->query('severity')) {
            $q->where('severity', $sev);
        }
        if ($site = $request->query('site')) {
            $q->whereHas('device', fn ($d) => $d->where('site_id', $site));
        }
        if ($request->query('ack') === 'belum') {
            $q->whereNull('acked_at')->whereNull('suppressed');
        }
        if ($term = $request->query('q')) {
            $q->whereHas('device', fn ($d) => $d->search($term));
        }

        return view('problems.index', [
            'problems' => $q->paginate(50)->withQueryString(),
            'history' => $history,
            'sites' => Site::orderBy('code')->get(),
            'openCount' => Problem::open()->count(),
        ]);
    }

    public function ack(Request $request, Problem $problem)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        abort_if($problem->resolved_at !== null, 422, 'Problem sudah selesai.');
        $problem->forceFill(['acked_at' => now(), 'acked_by' => $request->user()->id, 'ack_note' => $data['note'] ?? null])->save();
        Audit::log('ack', "ACK problem #{$problem->id}: {$problem->title}" . (! empty($data['note']) ? " — {$data['note']}" : ''), $problem);

        return back()->with('ok', 'Problem di-ACK. Eskalasi untuk problem ini dihentikan.');
    }

    public function unack(Problem $problem)
    {
        abort_if($problem->resolved_at !== null, 422);
        $problem->forceFill(['acked_at' => null, 'acked_by' => null, 'ack_note' => null])->save();
        Audit::log('unack', "ACK problem #{$problem->id} dibatalkan", $problem);

        return back()->with('ok', 'ACK dibatalkan.');
    }
}
