<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Problem;
use App\Models\Runbook;
use App\Models\Site;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Runbook (langkah penanganan). Baca: semua peran; tulis: engineer & admin. */
class RunbookController extends Controller
{
    public function index()
    {
        return view('runbooks.index', ['runbooks' => Runbook::with('site', 'device', 'editor')->orderBy('title')->get()]);
    }

    public function show(Runbook $runbook)
    {
        return view('runbooks.show', ['r' => $runbook->load('site', 'device', 'editor')]);
    }

    public function create(Request $request)
    {
        return $this->form(new Runbook(['enabled' => true, 'problem_kind' => $request->query('kind'), 'device_id' => $request->integer('device') ?: null]));
    }

    public function edit(Runbook $runbook)
    {
        return $this->form($runbook);
    }

    private function form(Runbook $r)
    {
        return view('runbooks.form', ['r' => $r, 'sites' => Site::orderBy('code')->get(), 'devices' => Device::orderBy('name')->get(['id', 'name', 'host'])]);
    }

    public function store(Request $request)
    {
        $r = new Runbook($this->validated($request));
        $r->updated_by = $request->user()->id;
        $r->save();
        Audit::log('created', "Runbook \"{$r->title}\" dibuat", $r);

        return redirect()->route('runbooks.show', $r)->with('ok', 'Runbook disimpan.');
    }

    public function update(Request $request, Runbook $runbook)
    {
        $runbook->fill($this->validated($request));
        $changes = Audit::diff($runbook);
        unset($changes['body']);
        $runbook->updated_by = $request->user()->id;
        $bodyChanged = $runbook->isDirty('body');
        $runbook->save();
        Audit::log('updated', "Runbook \"{$runbook->title}\" diubah" . ($bodyChanged ? ' (isi langkah)' : ''), $runbook, $changes ?: null);

        return redirect()->route('runbooks.show', $runbook)->with('ok', 'Runbook disimpan.');
    }

    public function destroy(Runbook $runbook)
    {
        $runbook->delete();
        Audit::log('deleted', "Runbook \"{$runbook->title}\" dihapus", $runbook);

        return redirect()->route('runbooks.index')->with('ok', 'Runbook dihapus.');
    }

    private function validated(Request $r): array
    {
        $d = $r->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:6000'],
            'problem_kind' => ['nullable', Rule::in(array_keys(Problem::KINDS))],
            'category' => ['nullable', Rule::in(array_keys(Device::CATEGORIES))],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
        ]);
        $d['enabled'] = $r->boolean('enabled');
        $d['body'] = str_replace("\r\n", "\n", trim($d['body']));

        return $d;
    }
}
