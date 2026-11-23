<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Token API baca-saja (admin). Token asli hanya ditampilkan sekali; yang disimpan hash SHA-256. */
class ApiTokenController extends Controller
{
    public function index()
    {
        $tokens = DB::table('api_tokens as t')->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->select('t.*', 'u.name as user_name')->orderByRaw('t.revoked_at IS NOT NULL')->orderByDesc('t.id')->get();

        return view('apitokens.index', ['tokens' => $tokens, 'newToken' => session('api_token'), 'base' => url('/api/v1')]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'days' => ['required', 'integer', 'in:0,30,90,180,365'],
            'allowed_ips' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'nama', 'days' => 'masa berlaku', 'allowed_ips' => 'IP yang diizinkan']);
        $ips = collect(preg_split('/[\s,;]+/', (string) ($d['allowed_ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
        foreach ($ips as $ip) {
            [$addr, $bits] = array_pad(explode('/', $ip, 2), 2, null);
            $max = str_contains((string) $addr, ':') ? 128 : 32;
            if (! filter_var($addr, FILTER_VALIDATE_IP) || ($bits !== null && (! ctype_digit($bits) || (int) $bits > $max))) {
                throw ValidationException::withMessages(['allowed_ips' => "Bukan IP atau CIDR: {$ip}"]);
            }
        }
        $token = 'ant_' . Str::random(44);
        DB::table('api_tokens')->insert([
            'name' => $d['name'], 'token_hash' => hash('sha256', $token), 'prefix' => substr($token, 0, 10),
            'allowed_ips' => $ips->isEmpty() ? null : $ips->implode(','), 'expires_at' => $d['days'] ? now()->addDays((int) $d['days']) : null,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('created', "Token API \"{$d['name']}\" dibuat" . ($d['days'] ? " (berlaku {$d['days']} hari)" : ' (tanpa batas waktu)')
            . ($ips->isEmpty() ? '' : ', hanya dari ' . $ips->implode(', ')));

        return redirect()->route('apitokens.index')->with('api_token', $token)->with('ok', 'Token dibuat. Salin sekarang, token tidak bisa ditampilkan lagi.');
    }

    public function revoke(int $token)
    {
        $name = DB::table('api_tokens')->where('id', $token)->value('name');
        abort_unless($name !== null, 404);
        DB::table('api_tokens')->where('id', $token)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Audit::log('updated', "Token API \"{$name}\" dicabut");

        return back()->with('ok', 'Token dicabut, langsung ditolak pada permintaan berikutnya.');
    }
}
