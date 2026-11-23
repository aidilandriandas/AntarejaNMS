<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\ServiceCheck;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Cek layanan HTTP(S), TCP, DNS. Lihat: semua peran; tambah/ubah/hapus/uji: engineer & admin. */
class CheckController extends Controller
{
    public function index(Request $request)
    {
        $q = ServiceCheck::with('device.site')->orderByRaw("CASE status WHEN 'down' THEN 0 WHEN 'slow' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END")->orderBy('name');
        if (in_array($s = $request->query('status'), ['up', 'slow', 'down', 'unknown'], true)) {
            $q->where('status', $s);
        }

        return view('checks.index', [
            'checks' => $q->get(),
            'counts' => ServiceCheck::where('enabled', true)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'certSoon' => ServiceCheck::where('enabled', true)->whereNotNull('cert_expires_at')->where('cert_expires_at', '<', now()->addDays(30))->count(),
        ]);
    }

    public function create(Request $request)
    {
        return $this->form(new ServiceCheck(['type' => 'http', 'interval_s' => 60, 'timeout_s' => 10, 'severity' => 'high', 'verify_tls' => true,
            'enabled' => true, 'cert_warn_days' => 14, 'device_id' => $request->integer('device') ?: null]));
    }

    public function edit(ServiceCheck $check)
    {
        return $this->form($check);
    }

    private function form(ServiceCheck $c)
    {
        return view('checks.form', ['c' => $c, 'devices' => Device::orderBy('name')->get(['id', 'name', 'host'])]);
    }

    public function store(Request $request)
    {
        $c = new ServiceCheck($this->validated($request));
        $c->save();
        Audit::log('created', "Cek layanan {$c->name} ({$c->typeLabel()}) dibuat untuk {$c->device->name}", $c);

        return redirect()->route('checks.index')->with('ok', "Cek {$c->name} dibuat. Hasil pertama muncul dalam 15 detik, atau klik Uji sekarang.");
    }

    public function update(Request $request, ServiceCheck $check)
    {
        $check->fill($this->validated($request));
        $changes = Audit::diff($check);
        if ($check->isDirty(['type', 'url', 'port', 'query', 'device_id'])) {
            $check->forceFill(['status' => 'unknown', 'fail_streak' => 0, 'cert_expires_at' => null, 'last_at' => null]);
        }
        $check->save();
        Audit::log('updated', "Cek layanan {$check->name} diubah", $check, $changes);

        return redirect()->route('checks.index')->with('ok', "Cek {$check->name} disimpan.");
    }

    public function destroy(ServiceCheck $check)
    {
        $check->delete();
        Audit::log('deleted', "Cek layanan {$check->name} dihapus", $check);

        return redirect()->route('checks.index')->with('ok', "Cek {$check->name} dihapus. Problem-nya ditutup pada penilaian berikutnya.");
    }

    public function run(ServiceCheck $check, Collector $collector)
    {
        try {
            $r = $collector->runCheck($check->id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Collector tidak dapat dihubungi: ' . mb_substr($e->getMessage(), 0, 200));
        }
        $ms = round((float) ($r['ms'] ?? 0));

        return back()->with($r['ok'] ? 'ok' : 'error', $r['ok'] ? "Cek {$check->name} berhasil, {$ms} ms." : "Cek {$check->name} gagal: {$r['error']}");
    }

    private function validated(Request $r): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:128'],
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'type' => ['required', Rule::in(array_keys(ServiceCheck::TYPES))],
            'url' => ['nullable', 'required_if:type,http', 'string', 'max:500', 'regex:#^https?://[^\s]+$#i'],
            'port' => ['nullable', 'required_if:type,tcp', 'integer', 'between:1,65535'],
            'query' => ['nullable', 'required_if:type,dns', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'expect_status' => ['nullable', 'string', 'max:32', 'regex:/^\s*\d{3}(\s*-\s*\d{3})?(\s*,\s*\d{3}(\s*-\s*\d{3})?)*\s*$/'],
            'expect_text' => ['nullable', 'string', 'max:200'],
            'expect_answer' => ['nullable', 'ip'],
            'interval_s' => ['required', 'integer', Rule::in(array_keys(ServiceCheck::INTERVALS))],
            'timeout_s' => ['required', 'integer', 'between:1,60'],
            'slow_ms' => ['nullable', 'integer', 'between:1,600000'],
            'cert_warn_days' => ['nullable', 'integer', 'between:1,365'],
            'severity' => ['required', Rule::in(array_keys(\App\Models\Problem::SEVERITIES))],
        ], [
            'url.regex' => 'Isi alamat lengkap yang diawali http:// atau https://.',
            'url.required_if' => 'Isi alamat yang dicek.',
            'port.required_if' => 'Isi port TCP.',
            'query.required_if' => 'Isi nama yang ditanyakan ke server DNS.',
            'query.regex' => 'Isi nama domain saja, mis. fms.tambang.local.',
            'expect_status.regex' => 'Contoh: 200, atau 200-299, atau 200,301,302.',
        ]);
        $d['verify_tls'] = $r->boolean('verify_tls');
        $d['enabled'] = $r->boolean('enabled');
        // hanya kolom milik jenis cek ini yang disimpan
        $keep = ['http' => ['url', 'expect_status', 'expect_text', 'cert_warn_days'], 'tcp' => ['port'], 'dns' => ['query', 'expect_answer', 'port']][$d['type']];
        foreach (['url', 'port', 'query', 'expect_status', 'expect_text', 'expect_answer', 'cert_warn_days'] as $k) {
            if (! in_array($k, $keep, true)) {
                $d[$k] = null;
            }
        }
        if ($d['type'] === 'dns' && empty($d['port'])) {
            $d['port'] = null;
        }
        if (($d['slow_ms'] ?? null) && $d['slow_ms'] >= $d['timeout_s'] * 1000) {
            throw ValidationException::withMessages(['slow_ms' => 'Ambang lambat harus di bawah batas waktu (' . $d['timeout_s'] * 1000 . ' ms).']);
        }

        return $d;
    }
}
