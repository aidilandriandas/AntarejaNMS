<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Backup database ANTAREJA. Proses dump dijalankan layanan "backup" (pg_dump); web hanya meminta & mengunduh. */
class BackupController extends Controller
{
    public function index()
    {
        $dir = (string) config('antareja.backup_dir');

        return view('backups.index', [
            'backups' => DB::table('backups as b')->leftJoin('users as u', 'u.id', '=', 'b.requested_by')
                ->orderByDesc('b.id')->limit(60)->get(['b.*', 'u.username']),
            'settings' => ['time' => Setting::get('backup.time', '01:30'), 'keep' => Setting::get('backup.keep', 14), 'enabled' => Setting::get('backup.enabled', true)],
            'pending' => DB::table('backups')->whereIn('status', ['requested', 'running'])->exists(),
            'dirOk' => $dir !== '' && is_dir($dir) && is_readable($dir),
            'serviceSeen' => DB::table('backups')->whereIn('status', ['ok', 'failed', 'running'])->where('requested_at', '>', now()->subDays(2))->exists(),
        ]);
    }

    public function store(Request $request)
    {
        abort_if(DB::table('backups')->whereIn('status', ['requested', 'running'])->exists(), 422, 'Masih ada backup yang berjalan.');
        $id = DB::table('backups')->insertGetId(['kind' => 'manual', 'requested_by' => $request->user()->id, 'requested_at' => now()]);
        Audit::log('created', "Backup manual #{$id} diminta");

        return back()->with('ok', 'Backup diminta. Biasanya selesai dalam kurang dari 1 menit, muat ulang halaman.');
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['time' => ['required', 'date_format:H:i'], 'keep' => ['required', 'integer', 'between:1,365']]);
        Setting::put('backup.time', $d['time']);
        Setting::put('backup.keep', (int) $d['keep']);
        Setting::put('backup.enabled', $request->boolean('enabled'));
        Audit::log('updated', "Pengaturan backup: jam {$d['time']}, simpan {$d['keep']}, " . ($request->boolean('enabled') ? 'aktif' : 'nonaktif'));

        return back()->with('ok', 'Pengaturan backup disimpan.');
    }

    public function download(int $backup): BinaryFileResponse
    {
        $row = DB::table('backups')->where('id', $backup)->where('status', 'ok')->first();
        abort_unless($row && preg_match('/^antareja-\d{8}-\d{6}\.dump$/', (string) $row->file), 404);
        $path = rtrim((string) config('antareja.backup_dir'), '/') . '/' . $row->file;
        abort_unless(is_file($path), 404, 'Berkas backup tidak ditemukan di server (mungkin sudah terhapus retensi).');
        Audit::log('download', "Backup {$row->file} diunduh");

        return response()->download($path, $row->file, ['Content-Type' => 'application/octet-stream']);
    }
}
