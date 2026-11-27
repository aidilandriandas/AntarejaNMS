<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\Audit;
use App\Services\Directory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/** Kebijakan login: wajib 2FA dan login lewat LDAP / Active Directory. Hanya admin. */
class AuthSettingsController extends Controller
{
    public const POLICIES = ['none' => 'Tidak wajib', 'admin' => 'Wajib untuk admin', 'all' => 'Wajib untuk semua akun'];

    /** Bawaan kebijakan keamanan (ISO/IEC 27001 A.8.5, A.5.18, A.8.15, A.5.10). 0 = tidak dipakai. */
    public const SECURITY_DEFAULTS = ['idle_minutes' => 30, 'disable_inactive_days' => 0, 'audit_keep_days' => 0,
        'login_notice' => 'Sistem ini hanya untuk pengguna berwenang. Aktivitas di sistem ini dicatat dan dapat diperiksa.'];

    public static function security(): array
    {
        return [
            'idle_minutes' => (int) Setting::get('auth.idle_minutes', self::SECURITY_DEFAULTS['idle_minutes']),
            'disable_inactive_days' => (int) Setting::get('auth.disable_inactive_days', 0),
            'audit_keep_days' => (int) Setting::get('audit.keep_days', 0),
            'login_notice' => (string) Setting::get('auth.login_notice', self::SECURITY_DEFAULTS['login_notice']),
        ];
    }

    public function securityUpdate(Request $request)
    {
        $d = $request->validate([
            'idle_minutes' => ['required', 'integer', function ($a, $v, $fail) { if ($v != 0 && ($v < 5 || $v > 480)) { $fail('Isi 0 (mati) atau 5 sampai 480 menit.'); } }],
            'disable_inactive_days' => ['required', 'integer', function ($a, $v, $fail) { if ($v != 0 && ($v < 30 || $v > 730)) { $fail('Isi 0 (mati) atau 30 sampai 730 hari.'); } }],
            'audit_keep_days' => ['required', 'integer', function ($a, $v, $fail) { if ($v != 0 && ($v < 365 || $v > 3650)) { $fail('Isi 0 (simpan selamanya) atau 365 sampai 3650 hari.'); } }],
            'login_notice' => ['nullable', 'string', 'max:300'],
        ]);
        $old = self::security();
        $d['login_notice'] = trim((string) ($d['login_notice'] ?? ''));
        Setting::put('auth.idle_minutes', (int) $d['idle_minutes']);
        Setting::put('auth.disable_inactive_days', (int) $d['disable_inactive_days']);
        Setting::put('audit.keep_days', (int) $d['audit_keep_days']);
        Setting::put('auth.login_notice', $d['login_notice']);
        $changes = [];
        foreach ($old as $k => $v) {
            if ((string) $v !== (string) $d[$k]) {
                $changes[$k] = [$v, $d[$k]];
            }
        }
        Audit::log('updated', 'Kebijakan keamanan diubah', null, $changes ?: null);

        return redirect()->to(route('auth.settings') . '#keamanan')->with('ok', 'Kebijakan keamanan disimpan.');
    }

    public function index()
    {
        return view('users.login-settings', [
            'security' => self::security(),
            'policy' => (string) Setting::get('auth.require_2fa', 'none'),
            'ldap' => Directory::config() + ['enabled' => false],
            'hasBindPassword' => (string) Setting::get('auth.ldap_bind_password', '') !== '',
            'ldapAvailable' => function_exists('ldap_connect'),
            'counts' => [
                'without2fa' => User::where('active', true)->whereNull('totp_enabled_at')->count(),
                'adminsWithout2fa' => User::where('active', true)->where('role', 'admin')->whereNull('totp_enabled_at')->count(),
                'ldapUsers' => User::where('auth_source', 'ldap')->count(),
            ],
        ]);
    }

    public function policy(Request $request)
    {
        $data = $request->validate(['require_2fa' => ['required', Rule::in(array_keys(self::POLICIES))]]);
        $old = (string) Setting::get('auth.require_2fa', 'none');
        // Admin yang mengubah kebijakan harus sudah memakai 2FA, supaya tidak mengunci dirinya sendiri di luar sesi ini
        if ($data['require_2fa'] !== 'none' && ! $request->user()->totp_enabled_at) {
            return back()->with('error', 'Aktifkan verifikasi dua langkah di akun Anda sendiri (Profil) sebelum mewajibkannya.');
        }
        Setting::put('auth.require_2fa', $data['require_2fa']);
        Audit::log('updated', 'Kebijakan verifikasi dua langkah: ' . self::POLICIES[$data['require_2fa']], null, ['require_2fa' => [$old, $data['require_2fa']]]);

        return back()->with('ok', 'Kebijakan disimpan. Akun yang belum memakai 2FA diminta mengaktifkannya saat membuka halaman berikutnya.');
    }

    public function ldap(Request $request)
    {
        $d = $request->validate([
            'host' => ['nullable', 'required_if:enabled,1', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['ldaps', 'starttls', 'none'])],
            'base_dn' => ['nullable', 'required_if:enabled,1', 'string', 'max:500'],
            'bind_dn' => ['nullable', 'string', 'max:500'],
            'bind_password' => ['nullable', 'string', 'max:500'],
            'user_filter' => ['nullable', 'string', 'max:500', 'regex:/\{username\}/'],
            'name_attr' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9-]*$/'],
            'mail_attr' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9-]*$/'],
            'admin_group' => ['nullable', 'string', 'max:500'],
            'engineer_group' => ['nullable', 'string', 'max:500'],
            'viewer_group' => ['nullable', 'string', 'max:500'],
        ], [
            'host.regex' => 'Isi nama host atau alamat IP saja, tanpa ldap:// di depan.',
            'user_filter.regex' => 'Filter harus memuat {username}, mis. (sAMAccountName={username}).',
        ]);
        $enabled = $request->boolean('enabled');
        if ($enabled && $d['encryption'] === 'none' && ! $request->boolean('allow_plain')) {
            return back()->withInput()->with('error', 'Tanpa enkripsi, password pengguna lewat jaringan dalam teks biasa. Centang "Saya paham" bila memang hanya untuk uji.');
        }
        $old = Directory::config();
        $new = ['enabled' => $enabled, 'verify_cert' => $request->boolean('verify_cert')] + collect($d)->except('bind_password')->map(fn ($v) => is_string($v) ? trim($v) : $v)->all();
        $new['port'] = ! empty($new['port']) ? (int) $new['port'] : null;
        Setting::put('auth.ldap', $new);
        if ($request->boolean('clear_bind_password')) {
            Setting::put('auth.ldap_bind_password', '');
        } elseif (($d['bind_password'] ?? '') !== '') {
            Setting::put('auth.ldap_bind_password', Crypt::encryptString($d['bind_password']));
        }
        $changes = [];
        foreach ($new as $k => $v) {
            if (($old[$k] ?? null) !== $v) {
                $changes[$k] = [$old[$k] ?? null, $v];
            }
        }
        if (($d['bind_password'] ?? '') !== '' || $request->boolean('clear_bind_password')) {
            $changes['bind_password'] = ['(rahasia)', '(diganti)'];
        }
        Audit::log('updated', 'Setelan login LDAP ' . ($enabled ? 'aktif' : 'nonaktif') . ' disimpan', null, $changes ?: null);

        return back()->with('ok', 'Setelan LDAP disimpan.' . ($enabled ? ' Klik "Uji koneksi" untuk memastikan.' : ''));
    }

    public function ldapTest(Request $request, Directory $directory)
    {
        try {
            $msg = $directory->test();
        } catch (\Throwable $e) {
            return back()->with('error', 'Uji LDAP gagal: ' . mb_substr($e->getMessage(), 0, 300));
        }
        if ($request->filled('username') && $request->filled('password')) {
            try {
                $r = $directory->authenticate(mb_strtolower(trim((string) $request->input('username'))), (string) $request->input('password'), true);
            } catch (\Throwable $e) {
                return back()->with('error', 'Akun layanan bisa bind, tetapi uji akun gagal: ' . mb_substr($e->getMessage(), 0, 300));
            }
            $msg .= $r === null ? '. Akun uji: username atau password salah.'
                : '. Akun uji ' . $r['name'] . ' cocok, peran ' . ($r['role'] ? User::ROLES[$r['role']] : 'tidak ada (bukan anggota grup mana pun, tidak bisa login)') . '.';
        }

        return back()->with('ok', 'Uji LDAP: ' . $msg);
    }

    public function resetTwoFactor(Request $request, User $user)
    {
        abort_unless($user->totp_enabled_at, 422, 'Akun ini tidak memakai 2FA.');
        $user->forceFill(['totp_secret_enc' => null, 'totp_enabled_at' => null, 'totp_last_step' => null, 'totp_recovery' => null])->save();
        Audit::log('updated', "Verifikasi dua langkah {$user->username} direset oleh admin", $user);

        return back()->with('ok', "2FA {$user->username} direset. Saat login berikutnya ia masuk dengan password saja" . ($user->mustEnroll2fa() ? ', lalu diminta mengaktifkan 2FA lagi.' : '.'));
    }
}
