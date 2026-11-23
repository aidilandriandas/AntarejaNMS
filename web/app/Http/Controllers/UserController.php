<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', ['users' => User::orderBy('username')->get()]);
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['role' => 'viewer', 'active' => true])]);
    }

    public function store(Request $request)
    {
        $user = User::create($this->validated($request));
        Audit::log('created', "Akun {$user->username} ({$user->roleLabel()}) dibuat", $user);

        return redirect()->route('users.index')->with('ok', "Akun {$user->username} dibuat.");
    }

    public function edit(User $user)
    {
        return view('users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if (($data['password'] ?? '') === '' || $user->auth_source === 'ldap') {
            unset($data['password']);
        }
        DB::transaction(function () use ($user, $data) {
            $user->fill($data);
            // Jangan sampai tidak ada admin aktif sama sekali
            if ($user->getOriginal('role') === 'admin' && $user->getOriginal('active') && (! $user->active || $user->role !== 'admin')
                && User::where('role', 'admin')->where('active', true)->whereKeyNot($user->id)->lockForUpdate()->doesntExist()) {
                abort(422, 'Harus tersisa minimal satu admin aktif.');
            }
            $changes = Audit::diff($user);
            $user->save();
            Audit::log('updated', "Akun {$user->username} diubah", $user, $changes);
        });

        return redirect()->route('users.index')->with('ok', "Akun {$user->username} disimpan.");
    }

    public const CONTACT_MESSAGES = [
        'telegram_chat_id.regex' => 'Chat ID Telegram berupa angka. Kirim /start ke bot, lalu buka @userinfobot untuk melihat angkanya.',
        'phone.regex' => 'Nomor HP hanya angka, spasi, tanda plus, atau tanda kurung.',
    ];

    public static function contactRules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:32', 'regex:/^-?\d{3,20}$/'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()]{6,30}$/'],
        ];
    }

    private function validated(Request $r, ?User $user = null): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user)],
            ...self::contactRules(),
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['username.regex' => 'Username hanya huruf kecil, angka, titik, garis bawah, atau strip.'] + self::CONTACT_MESSAGES);
        $d['active'] = $r->boolean('active');

        return $d;
    }
}
