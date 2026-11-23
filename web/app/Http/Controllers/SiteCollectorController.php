<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Site;
use App\Models\SiteCollector;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Collector site (admin): buat token, pilih perangkat yang dipantau dari site, pantau koneksinya. */
class SiteCollectorController extends Controller
{
    public function index()
    {
        return view('collectors.index', [
            'collectors' => SiteCollector::with('site')->withCount(['devices', 'devices as down_count' => fn ($q) => $q->where('enabled', true)->where('status', 'down')])->orderBy('name')->get(),
            'sites' => Site::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        [$token, $hash] = self::newToken();
        $c = new SiteCollector($d + ['enabled' => true]);
        $c->forceFill(['token_hash' => $hash, 'token_hint' => substr($token, 0, 10)])->save();
        Audit::log('created', "Collector site {$c->name} dibuat", $c);

        return redirect()->route('collectors.show', $c)->with('site_token', $token)
            ->with('ok', 'Collector dibuat. Salin token sekarang; token tidak bisa ditampilkan lagi.');
    }

    public function show(SiteCollector $collector)
    {
        $collector->load('site');

        return view('collectors.show', [
            'c' => $collector,
            'devices' => $collector->devices()->with('site')->orderBy('name')->get(),
            'sites' => Site::orderBy('code')->get(),
            'siteCandidates' => $collector->site_id ? Device::where('site_id', $collector->site_id)->whereNull('collector_id')->count() : 0,
            'token' => session('site_token'),
            'central' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function update(Request $request, SiteCollector $collector)
    {
        $collector->fill($this->validated($request, $collector) + ['enabled' => $request->boolean('enabled')]);
        $changes = Audit::diff($collector);
        $collector->save();
        Audit::log('updated', "Collector site {$collector->name} diubah", $collector, $changes);

        return back()->with('ok', 'Disimpan.' . (! $collector->enabled ? ' Collector nonaktif ditolak saat menghubungi pusat; perangkatnya tidak diperbarui sampai diaktifkan lagi atau dipindah ke pusat.' : ''));
    }

    public function rotate(SiteCollector $collector)
    {
        [$token, $hash] = self::newToken();
        $collector->forceFill(['token_hash' => $hash, 'token_hint' => substr($token, 0, 10)])->save();
        Audit::log('updated', "Token collector site {$collector->name} diganti", $collector);

        return back()->with('site_token', $token)->with('ok', 'Token baru dibuat dan token lama langsung ditolak. Pasang token baru di site.');
    }

    /** Pindahkan semua perangkat site collector ini yang masih dipantau pusat. */
    public function assignSite(SiteCollector $collector)
    {
        abort_unless($collector->site_id, 422);
        $ids = Device::where('site_id', $collector->site_id)->whereNull('collector_id')->pluck('name', 'id');
        DB::transaction(function () use ($ids, $collector) {
            Device::whereIn('id', $ids->keys())->update(['collector_id' => $collector->id]);
            foreach ($ids as $id => $name) {
                Audit::log('updated', "Perangkat {$name} dipantau dari collector site {$collector->name}", null, ['collector_id' => [null, $collector->id]]);
            }
        });

        return back()->with('ok', $ids->count() . ' perangkat sekarang dipantau dari ' . $collector->name . '. Collector site mengambil daftar baru dalam 5 menit.');
    }

    public function destroy(SiteCollector $collector)
    {
        $n = $collector->devices()->count();
        $collector->delete(); // perangkat kembali ke pusat (ON DELETE SET NULL)
        Audit::log('deleted', "Collector site {$collector->name} dihapus, {$n} perangkat kembali dipantau pusat", $collector);

        return redirect()->route('collectors.index')->with('ok', "{$collector->name} dihapus. {$n} perangkat kembali dipantau dari server pusat.");
    }

    private function validated(Request $request, ?SiteCollector $c = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('site_collectors', 'name')->ignore($c)],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array{0:string,1:string} token asli dan hash-nya */
    private static function newToken(): array
    {
        $t = 'acs_' . Str::random(48);

        return [$t, hash('sha256', $t)];
    }
}
