<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\CameraProfile;
use App\Models\Device;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Go2rtc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CctvController extends Controller
{
    public function index(Request $request, Go2rtc $go2rtc)
    {
        $cams = Camera::with('site', 'device')->where('enabled', true)
            ->when($request->integer('site'), fn ($q, $s) => $q->where('site_id', $s))
            ->orderBy('sort')->orderBy('name')->get();
        foreach ($cams as $c) {
            $go2rtc->ensure($c, 'sub'); // thumbnail dari sub-stream (ringan)
        }

        return view('cctv.index', ['cams' => $cams, 'sites' => Site::orderBy('code')->get(), 'q' => mb_substr((string) $request->query('q', ''), 0, 100),
            'openCam' => $cams->firstWhere('id', $request->integer('kamera'))?->id,
            'disabled' => Camera::where('enabled', false)->count()]);
    }

    /** Daftarkan aliran lalu kembalikan nama stream untuk player (tanpa kredensial). */
    public function live(Camera $camera, Request $request, Go2rtc $go2rtc): JsonResponse
    {
        $q = $request->query('q') === 'main' ? 'main' : 'sub';
        abort_unless($camera->enabled, 404);
        if (! $go2rtc->ensure($camera, $q)) {
            return response()->json(['error' => 'Server video (go2rtc) tidak dapat dihubungi.'], 502);
        }

        return response()->json(['src' => $camera->streamName($q), 'name' => $camera->name]);
    }

    /** Dipanggil nginx (auth_request) untuk setiap koneksi video: wajib login & nama stream valid. */
    public function auth(Request $request): Response
    {
        if (! Auth::check()) {
            return response('', 401);
        }
        parse_str((string) parse_url((string) $request->header('X-Original-URI'), PHP_URL_QUERY), $orig);
        $src = (string) ($orig['src'] ?? $request->query('src', ''));
        if (! preg_match('/^cam(\d+)_(sub|main)$/', $src, $m) || ! Camera::whereKey($m[1])->where('enabled', true)->exists()) {
            return response('', 403);
        }

        return response('', 204);
    }

    public function manage(Request $request)
    {
        $cams = Camera::with('site', 'device', 'profile')->orderBy('sort')->orderBy('name')->get();
        $profiles = CameraProfile::withCount('cameras')->orderBy('name')->get();

        return view('cctv.manage', [
            'cams' => $cams,
            'profiles' => $profiles,
            'editProfile' => $request->integer('profil') ? $profiles->firstWhere('id', $request->integer('profil')) : null,
            // kamera IP yang belum punya kamera di ANTAREJA (mis. hasil impor Zabbix), untuk tambah massal
            'candidates' => Device::with('site:id,code')->where('category', 'cctv')->whereNotIn('host', $cams->pluck('host'))->orderBy('name')->get(['id', 'name', 'host', 'site_id']),
            'edit' => $request->integer('ubah') ? $cams->firstWhere('id', $request->integer('ubah')) : null,
            'devices' => Device::orderBy('name')->get(['id', 'name', 'host', 'category', 'site_id']),
            'sites' => Site::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request, true);
        $created = [];
        $from = (int) ($d['channel_from'] ?? $d['channel']);
        $to = (int) ($d['channel_to'] ?? $d['channel']);
        abort_if($to - $from > 63, 422, 'Maksimal 64 kanal sekaligus.');
        for ($ch = $from; $ch <= $to; $ch++) {
            $c = new Camera(collect($d)->except(['password', 'channel_from', 'channel_to'])->all());
            $c->channel = $ch;
            if ($c->profile_id) {
                $c->username = null;
                $d['password'] = null;
            }
            if ($to > $from) {
                $c->name = trim($d['name']) . ' ' . str_pad((string) $ch, 2, '0', STR_PAD_LEFT);
            }
            $c->setPassword($d['password'] ?? null);
            $c->save();
            $created[] = $c->name;
        }
        Audit::log('created', 'Kamera ditambahkan: ' . implode(', ', $created));

        return redirect()->route('cctv.manage')->with('ok', count($created) . ' kamera ditambahkan.');
    }

    public function update(Request $request, Camera $camera, Go2rtc $go2rtc)
    {
        $d = $this->validated($request, false);
        $camera->fill(collect($d)->except(['password', 'channel_from', 'channel_to'])->all());
        if ($camera->profile_id) {
            $camera->username = null;
            $camera->password_enc = null;
        } else {
            $camera->setPassword($d['password'] ?? null);
        }
        $camera->enabled = $request->boolean('enabled');
        $changes = Audit::diff($camera);
        $camera->save();
        $go2rtc->forget($camera); // didaftarkan ulang dengan URL baru saat dibuka
        Audit::log('updated', "Kamera {$camera->name} diubah", $camera, $changes);

        return redirect()->route('cctv.manage')->with('ok', "Kamera {$camera->name} disimpan.");
    }

    /** Tambah banyak kamera sekaligus dari perangkat CCTV terdaftar, memakai satu profil login. */
    public function storeBulk(Request $request)
    {
        $d = $request->validate([
            'devices' => ['required', 'array', 'min:1', 'max:500'], 'devices.*' => ['integer', 'distinct', 'exists:devices,id'],
            'profile_id' => ['required', 'integer', 'exists:camera_profiles,id'],
            'channel' => ['required', 'integer', 'between:1,256'],
        ], ['devices.required' => 'Centang dulu kamera yang mau ditambahkan.', 'profile_id.required' => 'Pilih profil login.']);
        $p = CameraProfile::findOrFail($d['profile_id']);
        $existing = Camera::pluck('host')->flip();
        $sort = (int) Camera::max('sort');
        $made = [];
        $skipped = 0;
        foreach (Device::whereIn('id', $d['devices'])->orderBy('name')->get() as $dev) {
            if (isset($existing[$dev->host])) {
                $skipped++;

                continue;
            }
            $c = new Camera(['name' => $dev->name, 'device_id' => $dev->id, 'site_id' => $dev->site_id, 'host' => $dev->host, 'rtsp_port' => $p->rtsp_port,
                'channel' => $d['channel'], 'path_main' => $p->path_main, 'path_sub' => $p->path_sub, 'profile_id' => $p->id, 'enabled' => true, 'sort' => ++$sort]);
            $c->save();
            $existing[$dev->host] = true;
            $made[] = $c->name;
        }
        Audit::log('created', count($made) . " kamera ditambahkan massal dengan profil {$p->name}");

        return redirect()->route('cctv.manage')->with('ok', count($made) . " kamera ditambahkan dengan profil {$p->name}." . ($skipped ? " {$skipped} dilewati karena sudah ada." : '')
            . ' Gambar dicek otomatis dalam 15 menit; buka CCTV untuk melihat langsung.');
    }

    public function storeProfile(Request $request)
    {
        $p = new CameraProfile($this->profileData($request));
        $p->setPassword($request->input('password'));
        $p->save();
        Audit::log('created', "Profil login kamera \"{$p->name}\" dibuat");

        return redirect()->route('cctv.manage')->with('ok', "Profil {$p->name} dibuat. Pilih profil ini saat menambah kamera.");
    }

    public function updateProfile(Request $request, CameraProfile $profile, Go2rtc $go2rtc)
    {
        $profile->fill($this->profileData($request, $profile));
        $profile->setPassword($request->input('password'));
        $changed = $profile->isDirty(['username', 'password_enc']);
        $profile->save();
        if ($changed) {
            $profile->cameras()->get()->each(fn ($c) => $go2rtc->forget($c)); // dibuka ulang dengan login baru
        }
        Audit::log('updated', "Profil login kamera \"{$profile->name}\" diubah" . ($changed ? ' (login berubah)' : ''));

        return redirect()->route('cctv.manage')->with('ok', "Profil {$profile->name} disimpan" . ($changed ? ' dan dipakai ' . $profile->cameras()->count() . ' kamera.' : '.'));
    }

    public function destroyProfile(CameraProfile $profile)
    {
        $n = $profile->cameras()->count();
        if ($n) {
            return back()->with('error', "Profil {$profile->name} masih dipakai {$n} kamera. Pindahkan kameranya ke profil lain dulu.");
        }
        $profile->delete();
        Audit::log('deleted', "Profil login kamera \"{$profile->name}\" dihapus");

        return redirect()->route('cctv.manage')->with('ok', "Profil {$profile->name} dihapus.");
    }

    private function profileData(Request $r, ?CameraProfile $p = null): array
    {
        return $r->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('camera_profiles', 'name')->ignore($p)],
            'username' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:128'],
            'rtsp_port' => ['required', 'integer', 'between:1,65535'],
            'path_main' => ['required', 'string', 'max:255', 'starts_with:/'],
            'path_sub' => ['required', 'string', 'max:255', 'starts_with:/'],
        ], ['path_main.starts_with' => 'Path diawali "/".', 'path_sub.starts_with' => 'Path diawali "/".']);
    }

    public function destroy(Camera $camera, Go2rtc $go2rtc)
    {
        $go2rtc->forget($camera);
        $camera->delete();
        Audit::log('deleted', "Kamera {$camera->name} dihapus", $camera);

        return back()->with('ok', "Kamera {$camera->name} dihapus.");
    }

    private function validated(Request $r, bool $creating): array
    {
        return $r->validate([
            'name' => ['required', 'string', 'max:128'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'rtsp_port' => ['required', 'integer', 'between:1,65535'],
            'channel' => ['required_without:channel_from', 'nullable', 'integer', 'between:1,256'],
            'channel_from' => [$creating ? 'nullable' : 'prohibited', 'integer', 'between:1,256'],
            'channel_to' => [$creating ? 'nullable' : 'prohibited', 'integer', 'between:1,256', 'gte:channel_from'],
            'path_main' => ['required', 'string', 'max:255', 'starts_with:/'],
            'path_sub' => ['required', 'string', 'max:255', 'starts_with:/'],
            'username' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:128'],
            'sort' => ['nullable', 'integer'],
            'profile_id' => ['nullable', 'integer', 'exists:camera_profiles,id'],
        ], ['host.regex' => 'Isi alamat IP / nama DNS saja.', 'path_main.starts_with' => 'Path diawali "/".', 'path_sub.starts_with' => 'Path diawali "/".']) + ['sort' => 0];
    }
}
