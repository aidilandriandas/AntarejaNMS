<?php

namespace App\Http\Controllers;

use App\Models\NotifyChannel;
use App\Models\OncallSlot;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OncallController extends Controller
{
    public const SHIFTS = [8 => '8 jam (3 giliran sehari)', 12 => '12 jam (2 giliran sehari)', 24 => '24 jam (1 giliran sehari)'];

    public function index(Request $request)
    {
        $tz = config('app.timezone');
        try {
            $week = $request->filled('minggu') ? Carbon::createFromFormat('Y-m-d', (string) $request->query('minggu'), $tz) : now($tz);
        } catch (\Throwable) {
            $week = now($tz);
        }
        $week = $week->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $week->copy()->addDays(7);
        $slots = OncallSlot::with('user')->overlapping($week, $end)->orderBy('starts_at')->get();
        $days = collect(range(0, 6))->map(function ($i) use ($week, $slots) {
            $d = $week->copy()->addDays($i);
            $de = $d->copy()->addDay();

            return ['date' => $d, 'slots' => $slots->filter(fn ($s) => $s->starts_at < $de && $s->ends_at > $d)->values()];
        });
        $upcoming = OncallSlot::with('user')->where('ends_at', '>', now())->where('starts_at', '<', now()->addDays(7))->orderBy('starts_at')->limit(100)->get();
        $users = User::where('active', true)->orderBy('name')->get(['id', 'name', 'username', 'email', 'telegram_chat_id', 'phone', 'role']);
        // Orang yang punya giliran mendatang tetapi tidak punya email maupun Telegram: notifikasi piket tidak sampai ke mereka
        $noContact = $upcoming->pluck('user')->unique('id')->filter(fn ($u) => $u && ! $u->email && ! $u->telegram_chat_id)->values();

        return view('oncall.index', [
            'week' => $week, 'days' => $days, 'upcoming' => $upcoming, 'users' => $users, 'noContact' => $noContact,
            'current' => OncallSlot::current(), 'next' => OncallSlot::upcoming(),
            'channels' => NotifyChannel::where('to_oncall', true)->orderBy('name')->get(['id', 'name', 'type', 'enabled']),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:160'],
        ], ['ends_at.after' => 'Jam selesai harus setelah jam mulai.', 'user_id.exists' => 'Pilih pengguna yang aktif.']);
        $tz = config('app.timezone');
        $s = Carbon::parse($d['starts_at'], $tz);
        $e = Carbon::parse($d['ends_at'], $tz);
        if ($s->diffInHours($e) > 168) {
            throw ValidationException::withMessages(['ends_at' => 'Satu giliran paling lama 7 hari. Untuk jadwal panjang pakai Buat rotasi.']);
        }
        $slot = OncallSlot::create(['user_id' => $d['user_id'], 'starts_at' => $s, 'ends_at' => $e, 'note' => $d['note'] ?? null, 'created_by' => $request->user()->id]);
        $slot->load('user');
        Audit::log('created', "Piket {$slot->user->name} {$s->format('d/m H.i')} sampai {$e->format('d/m H.i')}", $slot);

        return redirect()->route('oncall.index', ['minggu' => $s->format('Y-m-d')])->with('ok', "Giliran piket {$slot->user->name} ditambahkan.");
    }

    /** Buat giliran berurutan untuk beberapa orang secara bergantian. */
    public function rotate(Request $request)
    {
        $d = $request->validate([
            'people' => ['required', 'array', 'min:1', 'max:8'],
            'people.*' => ['nullable', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'start_hour' => ['required', 'integer', 'between:0,23'],
            'shift' => ['required', Rule::in(array_keys(self::SHIFTS))],
            'days' => ['required', 'integer', 'between:1,31'],
        ]);
        $people = array_values(array_filter($d['people'] ?? [], fn ($v) => $v !== null && $v !== ''));
        if (! $people) {
            throw ValidationException::withMessages(['people' => 'Pilih minimal satu orang.']);
        }
        $tz = config('app.timezone');
        $from = Carbon::createFromFormat('Y-m-d', $d['start_date'], $tz)->startOfDay()->setHour((int) $d['start_hour']);
        $to = $from->copy()->addDays((int) $d['days']);
        $h = (int) $d['shift'];
        $replace = $request->boolean('replace');
        $n = 0;
        DB::transaction(function () use ($from, $to, $h, $people, $replace, $request, &$n) {
            if ($replace) {
                OncallSlot::overlapping($from, $to)->delete();
            }
            $rows = [];
            for ($t = $from->copy(), $i = 0; $t < $to; $t->addHours($h), $i++) {
                $rows[] = ['user_id' => $people[$i % count($people)], 'starts_at' => $t->copy(), 'ends_at' => $t->copy()->addHours($h)->min($to),
                    'note' => 'rotasi', 'created_by' => $request->user()->id, 'created_at' => now()];
            }
            OncallSlot::insert($rows);
            $n = count($rows);
        });
        $names = User::whereIn('id', $people)->pluck('name', 'id');
        Audit::log('created', "Rotasi piket {$n} giliran ({$h} jam) {$from->format('d/m H.i')} sampai {$to->format('d/m H.i')}: "
            . collect($people)->map(fn ($id) => $names[$id] ?? $id)->implode(', ') . ($replace ? ', menggantikan jadwal lama' : ''));

        return redirect()->route('oncall.index', ['minggu' => $from->format('Y-m-d')])->with('ok', "{$n} giliran piket dibuat.");
    }

    public function destroy(OncallSlot $slot)
    {
        $slot->load('user');
        $slot->delete();
        Audit::log('deleted', "Piket {$slot->user?->name} {$slot->starts_at->format('d/m H.i')} sampai {$slot->ends_at->format('d/m H.i')} dihapus");

        return back()->with('ok', 'Giliran piket dihapus.');
    }

    /** Label rentang waktu tanpa tanda pisah: "07.00 sampai 19.00", lintas hari memakai tanggal. */
    public static function span(Carbon $s, Carbon $e): string
    {
        $tz = config('app.timezone');
        $s = $s->copy()->setTimezone($tz);
        $e = $e->copy()->setTimezone($tz);
        $sameDay = $s->isSameDay($e) || ($e->format('H:i') === '00:00' && $s->isSameDay($e->copy()->subMinute()));

        return $sameDay ? $s->format('H.i') . ' sampai ' . ($e->format('H:i') === '00:00' ? '24.00' : $e->format('H.i'))
            : $s->translatedFormat('D d/m H.i') . ' sampai ' . $e->translatedFormat('D d/m H.i');
    }
}
