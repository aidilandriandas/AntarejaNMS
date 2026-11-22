<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Versi konfigurasi perangkat (diambil collector lewat SSH, hanya baca). Isi terenkripsi; hanya Admin/Engineer. */
class ConfigController extends Controller
{
    public function show(Device $device, int $version)
    {
        $v = DB::table('device_configs')->where('device_id', $device->id)->where('id', $version)->first();
        abort_unless($v, 404);
        $versions = DB::table('device_configs')->where('device_id', $device->id)->orderByDesc('taken_at')->get(['id', 'taken_at', 'lines', 'added', 'removed']);
        Audit::log('view', "Konfigurasi {$device->name} versi #{$v->id} dilihat", $device);

        return view('devices.config', [
            'device' => $device, 'v' => $v, 'versions' => $versions,
            'content' => Crypt::decryptString($v->content_enc),
            'diff' => $v->diff_enc ? Crypt::decryptString($v->diff_enc) : null,
        ]);
    }

    public function download(Device $device, int $version)
    {
        $v = DB::table('device_configs')->where('device_id', $device->id)->where('id', $version)->first();
        abort_unless($v, 404);
        Audit::log('download', "Konfigurasi {$device->name} versi #{$v->id} diunduh", $device);
        $ext = $device->cfg_type === 'mikrotik' ? 'rsc' : 'txt';
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $device->name) . '-' . \Illuminate\Support\Carbon::parse($v->taken_at)->format('Ymd-His') . '.' . $ext;

        return response(Crypt::decryptString($v->content_enc), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }

    public function backupNow(Device $device)
    {
        abort_unless($device->cfg_type, 422, 'Backup konfigurasi belum diaktifkan untuk perangkat ini.');
        $device->forceFill(['cfg_requested' => true])->save();
        Audit::log('requested', "Backup konfigurasi {$device->name} diminta", $device);

        return back()->with('ok', 'Backup konfigurasi diminta, biasanya selesai dalam 1 menit.');
    }

    public function resetHostKey(Device $device)
    {
        $old = $device->ssh_hostkey;
        $device->forceFill(['ssh_hostkey' => null, 'cfg_requested' => true])->save();
        Audit::log('updated', "Host key SSH {$device->name} direset (sebelumnya {$old})", $device);

        return back()->with('ok', 'Host key direset. Key baru dicatat pada backup berikutnya, pastikan perangkat memang diganti/di-reset.');
    }
}
