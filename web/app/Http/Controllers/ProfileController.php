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

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['current_password.current_password' => 'Password saat ini salah.']);
        $request->user()->update(['password' => $request->input('password')]);
        Audit::log('updated', $request->user()->username . ' mengganti password', $request->user());

        return back()->with('ok', 'Password diganti.');
    }
}
