<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Problem;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab', 'aktif');
        $q = Incident::with(['site', 'owner'])->withCount('problems');
        $tab === 'aktif' ? $q->whereIn('status', ['open', 'handling', 'recovered']) : $q->where('status', 'closed');
        if ($s = $request->integer('site')) {
            $q->where('site_id', $s);
        }
        $q->orderByRaw("array_position(ARRAY['open','handling','recovered','closed'], status)")->orderByDesc('opened_at');

        $stats = DB::selectOne("SELECT count(*) FILTER (WHERE status IN ('open','handling')) AS active,
                count(*) FILTER (WHERE opened_at > now() - interval '30 days') AS d30,
                avg(EXTRACT(EPOCH FROM (recovered_at - opened_at))) FILTER (WHERE recovered_at IS NOT NULL AND opened_at > now() - interval '30 days') AS mttr,
                count(*) FILTER (WHERE status = 'recovered') AS to_close
            FROM incidents");

        return view('incidents.index', ['incidents' => $q->paginate(30)->withQueryString(), 'tab' => $tab, 'stats' => $stats, 'sites' => Site::orderBy('code')->get()]);
    }

    public function show(Incident $incident)
    {
        $incident->load(['site', 'owner', 'notes.user', 'problems' => fn ($q) => $q->with('device.site')->orderByRaw('resolved_at IS NULL DESC')->orderBy('started_at')]);

        return view('incidents.show', [
            'i' => $incident,
            'engineers' => User::where('active', true)->whereIn('role', ['admin', 'engineer'])->orderBy('name')->get(['id', 'name']),
            'devices' => $incident->problems->pluck('device_id')->unique()->count(),
        ]);
    }

    /** Buat insiden manual dari problem terpilih. */
    public function store(Request $request)
    {
        $d = $request->validate([
            'problems' => ['required', 'array', 'min:1', 'max:500'], 'problems.*' => ['integer'],
            'title' => ['nullable', 'string', 'max:255'],
        ], ['problems.required' => 'Centang minimal satu problem.']);
        $problems = Problem::with('device')->whereIn('id', $d['problems'])->whereDoesntHave('incidents')->get();
        if ($problems->isEmpty()) {
            return back()->withErrors(['problems' => 'Problem terpilih sudah masuk insiden lain.']);
        }
        $sev = $problems->sortByDesc(fn ($p) => Problem::SEVERITIES[$p->severity][2] ?? 0)->first()->severity;
        $sites = $problems->pluck('device.site_id')->unique()->filter();
        $incident = DB::transaction(function () use ($problems, $d, $sev, $sites, $request) {
            $i = Incident::create([
                'title' => trim($d['title'] ?? '') ?: ($problems->count() === 1 ? $problems->first()->title : "Gangguan {$problems->pluck('device_id')->unique()->count()} perangkat"),
                'severity' => $sev, 'status' => 'open', 'site_id' => $sites->count() === 1 ? $sites->first() : null,
                'opened_at' => $problems->min('started_at'), 'created_by' => $request->user()->id,
            ]);
            $i->problems()->attach($problems->pluck('id'));
            $i->note('system', "Insiden dibuat manual oleh {$request->user()->name} dari {$problems->count()} problem.", $request->user()->id);

            return $i;
        });
        Audit::log('created', "Insiden #{$incident->id} dibuat: {$incident->title}");

        return redirect()->route('incidents.show', $incident)->with('ok', 'Insiden dibuat.');
    }

    public function take(Request $request, Incident $incident)
    {
        abort_if($incident->status === 'closed', 422);
        $d = $request->validate(['owner_id' => ['nullable', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->whereIn('role', ['admin', 'engineer']))]]);
        $owner = User::find($d['owner_id'] ?? $request->user()->id);
        $incident->forceFill(['owner_id' => $owner->id, 'handled_at' => $incident->handled_at ?? now(),
            'status' => $incident->status === 'open' ? 'handling' : $incident->status])->save();
        $incident->note('owner', $owner->id === $request->user()->id ? "{$owner->name} menangani insiden." : "{$request->user()->name} menugaskan {$owner->name}.", $request->user()->id);
        Audit::log('updated', "Insiden #{$incident->id}: penanggung jawab {$owner->name}");

        return back()->with('ok', "Penanggung jawab: {$owner->name}.");
    }

    public function note(Request $request, Incident $incident)
    {
        $d = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'catatan']);
        $incident->note('note', $d['body'], $request->user()->id);
        $incident->touch();

        return back()->with('ok', 'Catatan ditambahkan.');
    }

    public function rca(Request $request, Incident $incident)
    {
        $d = $request->validate(['cause' => ['nullable', 'string', 'max:5000'], 'action_taken' => ['nullable', 'string', 'max:5000'], 'title' => ['required', 'string', 'max:255']],
            [], ['cause' => 'penyebab', 'action_taken' => 'tindakan', 'title' => 'judul']);
        $incident->fill($d)->save();
        $incident->note('note', 'Judul / penyebab / tindakan diperbarui.', $request->user()->id);

        return back()->with('ok', 'Analisis disimpan.');
    }

    public function close(Request $request, Incident $incident)
    {
        abort_if($incident->status === 'closed', 422);
        if (! filled($incident->cause) || ! filled($incident->action_taken)) {
            return back()->withErrors(['cause' => 'Isi penyebab dan tindakan dulu sebelum menutup insiden.']);
        }
        $open = $incident->problems()->whereNull('resolved_at')->count();
        if ($open && ! $request->boolean('force')) {
            return back()->withErrors(['cause' => "Masih ada {$open} problem aktif. Centang \"tutup walau masih ada problem aktif\" bila memang perlu ditutup."]);
        }
        $incident->forceFill(['status' => 'closed', 'closed_at' => now(), 'recovered_at' => $incident->recovered_at ?? ($open ? null : now())])->save();
        $incident->note('status', "Insiden ditutup oleh {$request->user()->name}." . ($open ? " ({$open} problem masih aktif)" : ''), $request->user()->id);
        Audit::log('updated', "Insiden #{$incident->id} ditutup");

        return back()->with('ok', 'Insiden ditutup.');
    }

    public function reopen(Request $request, Incident $incident)
    {
        abort_unless($incident->status === 'closed', 422);
        $incident->forceFill(['status' => $incident->owner_id ? 'handling' : 'open', 'closed_at' => null])->save();
        $incident->note('status', "Insiden dibuka kembali oleh {$request->user()->name}.", $request->user()->id);
        Audit::log('updated', "Insiden #{$incident->id} dibuka kembali");

        return back()->with('ok', 'Insiden dibuka kembali.');
    }

    public function csv(Request $request)
    {
        $rows = Incident::with(['site', 'owner'])->withCount('problems')->where('opened_at', '>=', now()->subDays(min(365, max(1, $request->integer('hari', 90)))))->orderBy('opened_at')->get();
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Judul', 'Severity', 'Status', 'Site', 'Penanggung jawab', 'Otomatis', 'Problem', 'Mulai', 'Pulih', 'Ditutup', 'Lama gangguan', 'Penyebab', 'Tindakan'], ';');
        foreach ($rows as $i) {
            fputcsv($out, [$i->id, $i->title, $i->sevLabel(), $i->statusLabel(), $i->site?->code, $i->owner?->name, $i->auto ? 'ya' : 'tidak', $i->problems_count,
                $i->opened_at->format('Y-m-d H:i'), $i->recovered_at?->format('Y-m-d H:i'), $i->closed_at?->format('Y-m-d H:i'), Fmt::duration($i->downtimeSeconds()),
                $i->cause, $i->action_taken], ';');
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="antareja-insiden-' . now()->format('Ymd') . '.csv"']);
    }
}
