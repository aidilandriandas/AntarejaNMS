<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Problem;
use App\Models\Site;
use App\Models\SyslogRule;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Aturan pola syslog: N kemunculan dalam jendela waktu per perangkat menjadi problem. Engineer & admin. */
class SyslogRuleController extends Controller
{
    public const SEVERITIES = [0 => 'Emergency', 1 => 'Alert', 2 => 'Critical', 3 => 'Error', 4 => 'Warning', 5 => 'Notice', 6 => 'Info', 7 => 'Debug'];

    public function index(Request $request)
    {
        $rules = SyslogRule::with('site', 'device')->orderBy('name')->get();
        $edit = $request->integer('ubah') ? $rules->firstWhere('id', $request->integer('ubah')) : null;
        $active = DB::table('syslog_rule_hits as h')->join('syslog_rules as r', 'r.id', '=', 'h.rule_id')
            ->whereRaw('h.last_match_at > now() - make_interval(secs => r.clear_s)')->groupBy('h.rule_id')->selectRaw('h.rule_id, count(*) AS n')->pluck('n', 'rule_id');

        return view('logs.rules', ['rules' => $rules, 'edit' => $edit, 'active' => $active, 'sites' => Site::orderBy('code')->get(),
            'devices' => Device::orderBy('name')->get(['id', 'name']), 'test' => session('rule_test')]);
    }

    public function store(Request $request)
    {
        $r = SyslogRule::create($this->validated($request));
        Audit::log('created', "Aturan log \"{$r->name}\" dibuat", $r);

        return redirect()->route('logs.rules')->with('ok', "Aturan {$r->name} dibuat. Berlaku untuk log yang masuk mulai sekarang (paling lambat 1 menit).");
    }

    public function update(Request $request, SyslogRule $rule)
    {
        $rule->fill($this->validated($request));
        $changes = Audit::diff($rule);
        $rule->save();
        Audit::log('updated', "Aturan log \"{$rule->name}\" diubah", $rule, $changes);

        return redirect()->route('logs.rules')->with('ok', "Aturan {$rule->name} disimpan.");
    }

    public function destroy(SyslogRule $rule)
    {
        $rule->delete();
        Audit::log('deleted', "Aturan log \"{$rule->name}\" dihapus", $rule);

        return redirect()->route('logs.rules')->with('ok', "Aturan {$rule->name} dihapus. Problem-nya pulih pada penilaian berikutnya.");
    }

    /** Uji pola ke log 24 jam terakhir (maks. 5000 baris terbaru), tanpa menyimpan. */
    public function test(Request $request)
    {
        $d = $this->validated($request);
        $rule = new SyslogRule($d);
        $q = DB::table('syslog as l')->leftJoin('devices as d', 'd.id', '=', 'l.device_id')->where('l.received_at', '>=', now()->subDay())
            ->where('l.severity', '<=', $d['max_severity'])->orderByDesc('l.id')->limit(5000)
            ->when($d['app'] ?? null, fn ($q, $a) => $q->whereRaw('lower(l.app) = lower(?)', [$a]))
            ->when($d['device_id'] ?? null, fn ($q, $id) => $q->where('l.device_id', $id))
            ->when($d['site_id'] ?? null, fn ($q, $s) => $q->where('d.site_id', $s));
        $re = $rule->phpRegex();
        $rows = $q->get(['l.received_at', 'l.message', 'l.severity', 'd.name']);
        $hits = $rows->filter(fn ($l) => @preg_match($re, $l->message) === 1);

        return redirect()->route('logs.rules', $request->integer('id') ? ['ubah' => $request->integer('id')] : [])->withInput()
            ->with('rule_test', ['scanned' => $rows->count(), 'count' => $hits->count(), 'samples' => $hits->take(8)->values()->all()]);
    }

    private function validated(Request $r): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:80'],
            'pattern' => ['required', 'string', 'max:300'],
            'max_severity' => ['required', 'integer', 'between:0,7'],
            'app' => ['nullable', 'string', 'max:64'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'min_count' => ['required', 'integer', 'between:1,10000'],
            'window_m' => ['required', 'integer', 'between:1,1440'],
            'clear_m' => ['required', 'integer', 'between:1,10080'],
            'severity' => ['required', Rule::in(array_keys(Problem::SEVERITIES))],
        ]);
        $d['is_regex'] = $r->boolean('is_regex');
        $d['enabled'] = $r->boolean('enabled');
        $d['window_s'] = $d['window_m'] * 60;
        $d['clear_s'] = $d['clear_m'] * 60;
        unset($d['window_m'], $d['clear_m']);
        if ($d['is_regex']) {
            // RE2 (collector) tidak mengenal lookaround dan backreference; tolak supaya hasil uji di web sama dengan collector
            if (preg_match('/\(\?[=!<]|\\\\[1-9]|\(\?>|[*+?}]\+/', $d['pattern'])) {
                throw ValidationException::withMessages(['pattern' => 'Lookahead, lookbehind, backreference, dan quantifier posesif tidak didukung.']);
            }
            if (@preg_match('~' . str_replace('~', '\~', $d['pattern']) . '~iu', '') === false) {
                throw ValidationException::withMessages(['pattern' => 'Regex tidak sah.']);
            }
        }

        return $d;
    }
}
