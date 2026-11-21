<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    public function index()
    {
        return view('sites.index', ['sites' => Site::withCount(['devices', 'devices as down' => fn ($q) => $q->where('status', 'down')->where('enabled', true)])->orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        $site = Site::create($this->validated($request));
        Audit::log('created', "Site {$site->code} dibuat", $site);

        return back()->with('ok', "Site {$site->code} ditambahkan.");
    }

    public function update(Request $request, Site $site)
    {
        $site->fill($this->validated($request, $site));
        $changes = Audit::diff($site);
        $site->save();
        Audit::log('updated', "Site {$site->code} diubah", $site, $changes);

        return back()->with('ok', "Site {$site->code} disimpan.");
    }

    public function destroy(Site $site)
    {
        $n = $site->devices()->count();
        $site->delete();
        Audit::log('deleted', "Site {$site->code} dihapus ({$n} perangkat menjadi tanpa site)", $site);

        return back()->with('ok', "Site {$site->code} dihapus.");
    }

    private function validated(Request $r, ?Site $site = null): array
    {
        $r->merge(['code' => strtoupper(trim((string) $r->input('code')))]); // cek unik setelah dinormalkan
        $d = $r->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('sites', 'code')->ignore($site)],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], ['code.regex' => 'Kode hanya huruf, angka, titik, garis bawah, atau strip (mis. PIT-A).', 'code.unique' => 'Kode site ini sudah dipakai.']);

        return $d;
    }
}
