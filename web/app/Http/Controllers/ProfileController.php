<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function edit()
    {
        return view('profile');
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
