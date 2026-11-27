<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
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
            'offsite' => (array) Setting::get('backup.offsite', ['type' => 'off', 'keep' => 30]) + ['type' => 'off', 'keep' => 30],
            'offsiteHasSecret' => self::offsiteSecret(),
            'offsiteHostKey' => (string) Setting::get('backup.offsite_hostkey', ''),
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

    /** Rahasia tujuan salinan (dekripsi di server saja; tidak pernah dikirim ke browser). Kembalian: kunci yang terisi. */
    private static function offsiteSecret(): array
    {
        $enc = Setting::get('backup.offsite_secret');
        if (! is_string($enc) || $enc === '') {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($enc), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Setelan salinan backup ke luar server (SFTP atau S3/MinIO). Rahasia kosong = pertahankan yang tersimpan. */
    public function offsite(Request $request)
    {
        $type = (string) $request->input('type');
        $d = $request->validate([
            'type' => ['required', Rule::in(['off', 'sftp', 's3'])],
            'keep' => ['required', 'integer', 'between:1,365'],
            'host' => [$type === 'sftp' ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'user' => [$type === 'sftp' ? 'required' : 'nullable', 'string', 'max:64'],
            'dir' => ['nullable', 'string', 'max:255', 'regex:/^[^\x00]*$/'],
            'password' => ['nullable', 'string', 'max:255'],
            'private_key' => ['nullable', 'string', 'max:16000'],
            'endpoint' => [$type === 's3' ? 'required' : 'nullable', 'url:http,https', 'max:255'],
            'region' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9-]*$/'],
            'bucket' => [$type === 's3' ? 'required' : 'nullable', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/'],
            'prefix' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._\-\/]*$/'],
            'access_key' => [$type === 's3' ? 'required' : 'nullable', 'string', 'max:128'],
            'secret_key' => ['nullable', 'string', 'max:255'],
            'passphrase' => ['nullable', 'string', 'min:12', 'max:200', 'confirmed'],
        ], ['passphrase.min' => 'Frasa sandi minimal 12 karakter.', 'passphrase.confirmed' => 'Ulangi frasa sandi yang sama.','bucket.regex' => 'Nama bucket huruf kecil, angka, titik, atau strip (3 sampai 63 karakter).', 'host.regex' => 'Isi nama host atau IP saja.']);
        $old = self::offsiteSecret();
        $sec = [
            'password' => filled($d['password'] ?? null) ? $d['password'] : ($old['password'] ?? ''),
            'private_key' => filled($d['private_key'] ?? null) ? trim($d['private_key']) . "\n" : ($old['private_key'] ?? ''),
            'secret_key' => filled($d['secret_key'] ?? null) ? $d['secret_key'] : ($old['secret_key'] ?? ''),
            // Frasa enkripsi salinan (ISO/IEC 27001 A.8.13, A.8.24): kosong = tetap; dibuang hanya bila dicentang
            'passphrase' => filled($d['passphrase'] ?? null) ? $d['passphrase'] : ($old['passphrase'] ?? ''),
        ];
        if ($request->boolean('clear_passphrase')) {
            $sec['passphrase'] = '';
        }
        if ($request->boolean('clear_secret')) {
            $sec = ['password' => '', 'private_key' => '', 'secret_key' => '', 'passphrase' => $sec['passphrase']];
        }
        if ($type === 'sftp' && $sec['password'] === '' && $sec['private_key'] === '') {
            return back()->withInput()->withErrors(['password' => 'Isi password atau private key untuk SFTP.']);
        }
        if ($type === 's3' && $sec['secret_key'] === '') {
            return back()->withInput()->withErrors(['secret_key' => 'Isi secret key S3.']);
        }
        $prev = (array) Setting::get('backup.offsite', []);
        $cfg = ['type' => $type, 'keep' => (int) $d['keep']];
        if ($type === 'sftp') {
            $cfg += ['host' => $d['host'], 'port' => (int) ($d['port'] ?? 22), 'user' => $d['user'], 'dir' => trim((string) ($d['dir'] ?? ''))];
            if (($prev['host'] ?? null) !== $d['host'] || (int) ($prev['port'] ?? 22) !== $cfg['port']) {
                Setting::put('backup.offsite_hostkey', ''); // server lain: host key dicatat ulang saat koneksi pertama
            }
        } elseif ($type === 's3') {
            $cfg += ['endpoint' => rtrim($d['endpoint'], '/'), 'region' => ($d['region'] ?? '') ?: 'us-east-1', 'bucket' => $d['bucket'], 'prefix' => trim((string) ($d['prefix'] ?? ''), '/'), 'access_key' => $d['access_key']];
        }
        Setting::put('backup.offsite', $cfg);
        Setting::put('backup.offsite_secret', Crypt::encryptString(json_encode($sec)));
        if ($type !== 'off' && $type !== ($prev['type'] ?? 'off')) {
            // tujuan baru: backup berhasil yang masih ada (maks. sebanyak retensi lokal) ikut disalin
            DB::table('backups')->where('status', 'ok')->update(['offsite_status' => null, 'offsite_tries' => 0, 'offsite_error' => null]);
        }
        $encNote = ($old['passphrase'] ?? '') !== $sec['passphrase'] ? ($sec['passphrase'] === '' ? ', enkripsi dimatikan' : ', frasa enkripsi diganti') : ($sec['passphrase'] !== '' ? ', terenkripsi' : ', TANPA enkripsi');
        Audit::log('updated', 'Salinan backup ke luar server: ' . ($type === 'off' ? 'dimatikan' : $type . ' ' . ($type === 'sftp' ? $cfg['user'] . '@' . $cfg['host'] . ':' . $cfg['dir'] : $cfg['endpoint'] . '/' . $cfg['bucket']) . $encNote));

        return back()->with('ok', $type === 'off' ? 'Salinan ke luar server dimatikan.' : 'Setelan disimpan. Tekan Uji koneksi untuk memastikan; backup berikutnya disalin otomatis.');
    }

    public function offsiteTest(Collector $collector)
    {
        try {
            $r = $collector->offsiteTest();
        } catch (\Throwable $e) {
            return back()->with('error', 'Collector tidak dapat dihubungi: ' . $e->getMessage());
        }
        Audit::log('viewed', 'Uji koneksi tujuan salinan backup: ' . ($r['ok'] ? 'berhasil' : 'gagal'));

        return back()->with($r['ok'] ? 'ok' : 'error', ($r['ok'] ? 'Uji berhasil: ' : 'Uji gagal: ') . $r['detail']
            . (! empty($r['hostkey']) ? ' Sidik jari host key server: ' . $r['hostkey'] . '.' : ''));
    }

    public function offsiteResetHostKey()
    {
        Setting::put('backup.offsite_hostkey', '');
        Audit::log('updated', 'Host key SFTP tujuan backup direset');

        return back()->with('ok', 'Host key direset. Sidik jari server dicatat ulang pada koneksi berikutnya; pastikan server memang diganti.');
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
