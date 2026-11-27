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
        return view('users.index', ['users' => User::orderBy('username')->get(),
            'limits' => DB::table('user_sites as us')->join('sites as s', 's.id', '=', 'us.site_id')->orderBy('s.code')->get(['us.user_id', 's.code'])->groupBy('user_id')]);
    }

    /**
     * Tinjauan hak akses (ISO/IEC 27001:2022 A.5.18): semua akun dengan peran, batas site, 2FA, sumber login, dan
     * kapan terakhir dipakai. Diunduh berkala (mis. tiap triwulan), diperiksa pemilik sistem, lalu disimpan sebagai bukti.
     */
    public function review()
    {
        $limits = DB::table('user_sites as us')->join('sites as s', 's.id', '=', 'us.site_id')->orderBy('s.code')->get(['us.user_id', 's.code'])->groupBy('user_id');
        $users = User::orderBy('username')->get();
        Audit::log('exported', 'Tinjauan hak akses diunduh (' . $users->count() . ' akun)');

        return response()->streamDownload(function () use ($users, $limits) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['username', 'nama', 'email', 'peran', 'site yang boleh dilihat', 'status', 'alasan nonaktif', '2fa', 'sumber login', 'dibuat', 'login terakhir', 'terakhir aktif', 'hari tidak aktif', 'catatan peninjau']);
            foreach ($users as $u) {
                $seen = $u->lastActive();
                fputcsv($out, [$u->username, $u->name, $u->email, $u->roleLabel(), isset($limits[$u->id]) ? $limits[$u->id]->pluck('code')->implode(' ') : 'semua',
                    $u->active ? 'aktif' : 'nonaktif', $u->disabled_reason, $u->totp_enabled_at ? 'ya' : 'tidak', $u->auth_source === 'ldap' ? 'LDAP' : 'lokal',
                    $u->created_at?->format('Y-m-d'), $u->last_login_at?->format('Y-m-d H:i'), $seen?->format('Y-m-d H:i'), $seen ? (int) $seen->diffInDays(now()) : '', '']);
            }
            fclose($out);
        }, 'tinjauan-hak-akses-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['role' => 'viewer', 'active' => true]), 'sites' => \App\Models\Site::orderBy('code')->get(), 'userSites' => []]);
    }

    public function store(Request $request)
    {
        $sites = $this->sites($request);
        $user = DB::transaction(function () use ($request, $sites) {
            $user = User::create($this->validated($request));
            $this->syncSites($user, $sites);

            return $user;
        });
        Audit::log('created', "Akun {$user->username} ({$user->roleLabel()}) dibuat", $user);

        return redirect()->route('users.index')->with('ok', "Akun {$user->username} dibuat.");
    }

    public function edit(User $user)
    {
        return view('users.form', ['user' => $user, 'sites' => \App\Models\Site::orderBy('code')->get(),
            'userSites' => DB::table('user_sites')->where('user_id', $user->id)->pluck('site_id')->map(fn ($v) => (int) $v)->all()]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        $sites = $this->sites($request);
        if (($data['password'] ?? '') === '' || $user->auth_source === 'ldap') {
            unset($data['password']);
        }
        DB::transaction(function () use ($user, $data, $sites) {
            $user->fill($data);
            // Jangan sampai tidak ada admin aktif sama sekali
            if ($user->getOriginal('role') === 'admin' && $user->getOriginal('active') && (! $user->active || $user->role !== 'admin')
                && User::where('role', 'admin')->where('active', true)->whereKeyNot($user->id)->lockForUpdate()->doesntExist()) {
                abort(422, 'Harus tersisa minimal satu admin aktif.');
            }
            // Diaktifkan lagi oleh admin: hitung sejak sekarang supaya tidak langsung dinonaktifkan lagi malam ini
            if ($user->active && ! $user->getOriginal('active')) {
                $user->disabled_reason = null;
                $user->last_seen_at = now();
            }
            $changes = Audit::diff($user);
            $user->save();
            $old = DB::table('user_sites')->where('user_id', $user->id)->orderBy('site_id')->pluck('site_id')->map(fn ($v) => (int) $v)->all();
            $this->syncSites($user, $sites);
            if ($old !== $sites) {
                $changes['sites'] = [$old, $sites];
            }
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

    /** Site yang boleh dilihat (kosong = semua). Admin tidak bisa dibatasi. */
    private function sites(Request $r): array
    {
        $ids = $r->validate(['sites' => ['array', 'max:200'], 'sites.*' => ['integer', 'exists:sites,id']])['sites'] ?? [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids && $r->input('role') === 'admin') {
            throw \Illuminate\Validation\ValidationException::withMessages(['sites' => 'Admin selalu melihat semua site. Pilih peran engineer atau viewer untuk akun terbatas.']);
        }

        return $ids;
    }

    private function syncSites(User $user, array $ids): void
    {
        DB::table('user_sites')->where('user_id', $user->id)->delete();
        DB::table('user_sites')->insert(array_map(fn ($s) => ['user_id' => $user->id, 'site_id' => $s], $ids));
        \App\Support\SiteScope::forget($user->id);
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
