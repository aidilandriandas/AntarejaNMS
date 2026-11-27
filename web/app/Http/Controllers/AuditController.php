<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Log audit (ISO/IEC 27001:2022 A.8.15, A.5.28): saring, ekspor CSV sebagai bukti, dan cek keutuhan rantai hash.
 * Baris tidak bisa diubah atau dihapus dari aplikasi; database menolaknya (migrasi 0050).
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);

        return view('audit.index', [
            'logs' => $this->query($f)->with('user')->orderByDesc('id')->paginate(50)->withQueryString(),
            'f' => $f,
            'users' => User::orderBy('username')->get(['id', 'username']),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'keepDays' => (int) Setting::get('audit.keep_days', 0),
        ]);
    }

    /** Ekspor CSV hasil saringan (maks. 50.000 baris) beserta hash tiap baris, untuk diserahkan ke auditor. */
    public function export(Request $request)
    {
        $f = $this->filters($request);
        $n = (clone $this->query($f))->count();
        Audit::log('exported', "Log audit diekspor ({$n} baris" . ($f['from'] || $f['to'] ? ", {$f['from']} sampai {$f['to']}" : '') . ')');
        $q = $this->query($f)->with('user:id,username')->orderBy('id')->limit(50000);

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'waktu', 'pengguna', 'aksi', 'objek', 'keterangan', 'perubahan', 'ip', 'hash_sebelumnya', 'hash']);
            foreach ($q->cursor() as $l) {
                fputcsv($out, [$l->id, $l->at->format('Y-m-d H:i:s P'), $l->user?->username ?? ($l->user_ref ? "#{$l->user_ref} (dihapus)" : 'sistem'),
                    $l->action, trim(($l->subject_type ?? '') . ' ' . ($l->subject_id ?? '')), $l->description,
                    $l->changes ? json_encode($l->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '', $l->ip_address, $l->prev_hash, $l->hash]);
            }
            fclose($out);
        }, 'log-audit-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Hitung ulang hash semua baris dan cocokkan rantainya. */
    public function verify()
    {
        $r = self::verifyChain();
        Audit::log('verified', $r['ok'] ? "Keutuhan log audit diperiksa: utuh ({$r['rows']} baris)" : "Keutuhan log audit diperiksa: RUSAK mulai baris #{$r['broken']}");

        return back()->with($r['ok'] ? 'ok' : 'error', $r['ok']
            ? "Log audit utuh: {$r['rows']} baris diperiksa, semua hash dan rantainya cocok."
            : "Log audit RUSAK mulai baris #{$r['broken']}: isinya diubah atau ada baris yang dihapus di luar aplikasi. Simpan bukti ini dan laporkan sebagai insiden keamanan.");
    }

    /** @return array{ok: bool, rows: int, broken: ?int} */
    public static function verifyChain(): array
    {
        $bad = DB::selectOne('SELECT id FROM (
                SELECT id, hash, prev_hash, lag(hash) OVER (ORDER BY id) AS before, row_number() OVER (ORDER BY id) AS n, audit_row_hash(a) AS calc FROM audit_logs a
            ) x WHERE hash IS DISTINCT FROM calc OR (n > 1 AND prev_hash IS DISTINCT FROM before) ORDER BY id LIMIT 1');

        return ['ok' => $bad === null, 'rows' => (int) DB::table('audit_logs')->count(), 'broken' => $bad?->id];
    }

    private function filters(Request $r): array
    {
        $d = $r->validate([
            'user' => ['nullable', 'integer'], 'aksi' => ['nullable', 'string', 'max:32'], 'q' => ['nullable', 'string', 'max:100'],
            'dari' => ['nullable', 'date_format:Y-m-d'], 'sampai' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ['user' => $d['user'] ?? null, 'action' => $d['aksi'] ?? null, 'q' => $d['q'] ?? null, 'from' => $d['dari'] ?? null, 'to' => $d['sampai'] ?? null];
    }

    private function query(array $f): Builder
    {
        return AuditLog::query()
            ->when($f['user'], fn ($q, $u) => $q->where(fn ($q) => $q->where('user_id', $u)->orWhere('user_ref', $u)))
            ->when($f['action'], fn ($q, $a) => $q->where('action', $a))
            ->when($f['q'], fn ($q, $t) => $q->where(fn ($q) => $q->where('description', 'ilike', '%' . addcslashes($t, '%_\\') . '%')->orWhere('ip_address', $t)))
            ->when($f['from'], fn ($q, $d) => $q->where('at', '>=', $d . ' 00:00:00'))
            ->when($f['to'], fn ($q, $d) => $q->where('at', '<', \Illuminate\Support\Carbon::parse($d)->addDay()->toDateString()));
    }
}
