<?php

namespace App\Http\Controllers;

use App\Models\Camera;
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

        return view('cctv.index', ['cams' => $cams, 'sites' => Site::orderBy('code')->get(),
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
        $cams = Camera::with('site', 'device')->orderBy('sort')->orderBy('name')->get();

        return view('cctv.manage', [
            'cams' => $cams,
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
        $camera->setPassword($d['password'] ?? null);
        $camera->enabled = $request->boolean('enabled');
        $changes = Audit::diff($camera);
        $camera->save();
        $go2rtc->forget($camera); // didaftarkan ulang dengan URL baru saat dibuka
        Audit::log('updated', "Kamera {$camera->name} diubah", $camera, $changes);

        return redirect()->route('cctv.manage')->with('ok', "Kamera {$camera->name} disimpan.");
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
        ], ['host.regex' => 'Isi alamat IP / nama DNS saja.', 'path_main.starts_with' => 'Path diawali "/".', 'path_sub.starts_with' => 'Path diawali "/".']) + ['sort' => 0];
    }
}
