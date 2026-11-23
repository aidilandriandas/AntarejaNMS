<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $secret = $request->session()->get('2fa_setup');

        return view('profile', [
            'setupSecret' => $secret,
            'setupUri' => $secret ? Totp::uri($secret, $request->user()->username) : null,
            'recoveryLeft' => count((array) ($request->user()->totp_recovery ?? [])),
            'mustEnroll' => $request->user()->mustEnroll2fa(),
        ]);
    }

    /** Mulai aktivasi 2FA: rahasia baru disimpan di sesi sampai dikonfirmasi dengan kode pertama. */
    public function twoFactorStart(Request $request)
    {
        abort_if($request->user()->totp_enabled_at !== null, 422, '2FA sudah aktif.');
        $request->session()->put('2fa_setup', Totp::secret());

        return redirect()->to(route('profile') . '#dua-langkah');
    }

    public function twoFactorEnable(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $secret = $request->session()->get('2fa_setup');
        abort_unless($secret, 422, 'Mulai ulang aktivasi.');
        $step = Totp::verify($secret, (string) $request->input('code'));
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'Kode salah. Pastikan jam di HP tepat, lalu masukkan kode yang sedang tampil.']);
        }
        $codes = self::newRecoveryCodes();
        $u = $request->user();
        $u->forceFill(['totp_secret_enc' => Crypt::encryptString($secret), 'totp_enabled_at' => now(), 'totp_last_step' => $step,
            'totp_recovery' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes)])->save();
        $request->session()->forget('2fa_setup');
        Audit::log('updated', "{$u->username} mengaktifkan verifikasi dua langkah", $u);

        return redirect()->to(route('profile') . '#dua-langkah')->with('recovery_codes', $codes)
            ->with('ok', 'Verifikasi dua langkah aktif. Simpan kode cadangan di bawah sekarang; kode itu tidak bisa ditampilkan lagi.');
    }

    /** Nonaktifkan: butuh kode saat ini (atau password untuk akun lokal), dan tidak boleh bila kebijakan mewajibkan. */
    public function twoFactorDisable(Request $request)
    {
        $u = $request->user();
        abort_unless($u->totp_enabled_at, 422);
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $old = $u->totp_enabled_at;
        $u->totp_enabled_at = null;
        if ($u->mustEnroll2fa()) {
            $u->totp_enabled_at = $old;

            return back()->with('error', 'Kebijakan login mewajibkan verifikasi dua langkah untuk akun Anda, jadi tidak bisa dimatikan.');
        }
        $u->totp_enabled_at = $old;
        if (Totp::verify(Crypt::decryptString($u->totp_secret_enc), (string) $request->input('code'), $u->totp_last_step) === null) {
            throw ValidationException::withMessages(['code' => 'Kode salah.']);
        }
        $u->forceFill(['totp_secret_enc' => null, 'totp_enabled_at' => null, 'totp_last_step' => null, 'totp_recovery' => null])->save();
        Audit::log('updated', "{$u->username} mematikan verifikasi dua langkah", $u);

        return back()->with('ok', 'Verifikasi dua langkah dimatikan.');
    }

    public function twoFactorRecovery(Request $request)
    {
        $u = $request->user();
        abort_unless($u->totp_enabled_at, 422);
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $step = Totp::verify(Crypt::decryptString($u->totp_secret_enc), (string) $request->input('code'), $u->totp_last_step);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'Kode salah.']);
        }
        $codes = self::newRecoveryCodes();
        $u->forceFill(['totp_last_step' => $step, 'totp_recovery' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes)])->save();
        Audit::log('updated', "{$u->username} membuat ulang kode cadangan 2FA", $u);

        return redirect()->to(route('profile') . '#dua-langkah')->with('recovery_codes', $codes)->with('ok', 'Kode cadangan baru dibuat; yang lama tidak berlaku lagi.');
    }

    /** 10 kode cadangan sekali pakai, mis. "K7Q2M-9XWAD". */
    private static function newRecoveryCodes(): array
    {
        return array_map(fn () => implode('-', str_split(strtoupper(Str::random(10)), 5)), range(1, 10));
    }

    /** Kontak untuk notifikasi piket: email, chat ID Telegram, nomor HP. */
    public function updateContact(Request $request)
    {
        $d = $request->validate(UserController::contactRules(), UserController::CONTACT_MESSAGES);
        $u = $request->user();
        $u->fill(['email' => $d['email'] ?? null, 'telegram_chat_id' => $d['telegram_chat_id'] ?? null, 'phone' => $d['phone'] ?? null]);
        $changes = Audit::diff($u);
        $u->save();
        Audit::log('updated', $u->username . ' mengubah kontak', $u, $changes);

        return back()->with('ok', 'Kontak disimpan.');
    }

    public function updatePassword(Request $request)
    {
        abort_if($request->user()->auth_source === 'ldap', 422, 'Password akun LDAP diganti di direktori kantor.');
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['current_password.current_password' => 'Password saat ini salah.']);
        $request->user()->update(['password' => $request->input('password')]);
        // Sesi lain (perangkat lain, "Ingat saya") otomatis keluar lewat AuthenticateSession; sesi ini tetap masuk
        $request->session()->put('password_hash_web', $request->user()->getAuthPassword());
        Audit::log('updated', $request->user()->username . ' mengganti password', $request->user());

        return back()->with('ok', 'Password diganti.');
    }
}
