<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Exporter Prometheus per perangkat (node_exporter, windows_exporter): URL, layanan dipantau, uji scrape. Engineer & admin. */
class ExporterController extends Controller
{
    public function edit(Device $device)
    {
        return view('exporter.edit', [
            'device' => $device,
            'values' => DB::table('device_sensors')->where('device_id', $device->id)->where('key', 'like', 'px:%')->orderBy('key')->get(),
            'test' => session('exporter_test'),
        ]);
    }

    public function update(Request $request, Device $device)
    {
        $on = $request->boolean('enabled');
        $d = $request->validate([
            'exporter_url' => ['nullable', 'required_if:enabled,1', 'string', 'max:255'],
            'exporter_services' => ['nullable', 'string', 'max:500'],
        ]);
        $url = trim((string) ($d['exporter_url'] ?? ''));
        if ($on) {
            $u = parse_url($url);
            if (! $u || ! in_array($u['scheme'] ?? '', ['http', 'https'], true) || empty($u['host'])) {
                throw ValidationException::withMessages(['exporter_url' => 'Isi URL lengkap, mis. http://10.1.2.3:9100/metrics.']);
            }
            if (isset($u['user']) || isset($u['pass'])) {
                throw ValidationException::withMessages(['exporter_url' => 'Jangan taruh nama pengguna atau kata sandi di URL.']);
            }
        }
        $svc = collect(preg_split('/[\s,]+/', (string) ($d['exporter_services'] ?? '')))->map(fn ($s) => trim($s))->filter()->unique()->take(50);
        if ($svc->contains(fn ($s) => ! preg_match('/^[A-Za-z0-9_.@:\-]{1,100}$/', $s))) {
            throw ValidationException::withMessages(['exporter_services' => 'Nama layanan hanya huruf, angka, titik, garis bawah, @, titik dua, dan tanda hubung.']);
        }
        $old = [$device->exporter_url, $device->exporter_services];
        $device->forceFill([
            'exporter_url' => $on ? $url : null,
            'exporter_services' => $svc->isEmpty() ? null : $svc->implode(', '),
            'exporter_error' => null,
        ])->save();
        if (! $on) {
            DB::table('device_sensors')->where('device_id', $device->id)->where('key', 'like', 'px:%')->delete();
        }
        Audit::log('updated', "Exporter {$device->name} " . ($on ? 'aktif' : 'dimatikan'), $device,
            ['exporter' => [$old, [$device->exporter_url, $device->exporter_services]]]);

        return back()->with('ok', $on ? 'Exporter aktif. Di-scrape tiap menit; CPU muncul setelah dua kali scrape.' : 'Exporter dimatikan, sensornya dihapus.');
    }

    public function test(Device $device, Collector $collector)
    {
        abort_unless($device->exporter_url, 422, 'Isi URL exporter dulu.');
        try {
            $r = $collector->exporterTest($device->id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Collector tidak dapat dihubungi: ' . mb_substr($e->getMessage(), 0, 200));
        }
        if (! ($r['ok'] ?? false)) {
            return back()->with('error', 'Uji scrape gagal: ' . mb_substr((string) ($r['error'] ?? 'tidak diketahui'), 0, 200));
        }

        return back()->with('exporter_test', ['kind' => $r['kind'] ?? '', 'readings' => $r['readings'] ?? []])
            ->with('ok', 'Uji scrape berhasil, hasilnya di bawah. Belum disimpan.');
    }
}
