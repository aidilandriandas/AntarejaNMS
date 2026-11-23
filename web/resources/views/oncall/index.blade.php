@extends('layouts.app')
@section('title', 'Jadwal piket')
@php
    $span = fn ($s) => \App\Http\Controllers\OncallController::span($s->starts_at, $s->ends_at);
    $contact = fn ($u) => collect([$u->phone, $u->email, $u->telegram_chat_id ? 'Telegram' : null])->filter()->implode(', ');
    $today = now()->startOfDay();
    $canEdit = auth()->user()->can('manage-devices');
@endphp
@section('content')
<div class="page-head">
    <div><h1>Jadwal piket</h1>
        <p>Siapa yang dihubungi saat ada problem di luar jam kerja. Kanal notifikasi yang diset <b>kirim ke yang sedang piket</b> memakai email dan Telegram orang yang sedang bertugas.</p></div>
    <div class="actions"><a class="btn" href="{{ route('profile') }}"><x-icon name="users"/>Kontak saya</a></div>
</div>

<div class="kpis">
    <div class="kpi {{ $current->isEmpty() ? 'warn' : 'ok' }}"><div class="lbl">Piket sekarang</div>
        <div class="val val-text">{{ $current->isEmpty() ? 'tidak ada' : $current->pluck('user.name')->unique()->implode(', ') }}</div>
        <div class="note">@if($current->isEmpty()) notifikasi piket ke tujuan cadangan kanal @else sampai {{ $current->min('ends_at')->format('H.i') }}{{ $current->min('ends_at')->isToday() ? '' : ', ' . $current->min('ends_at')->translatedFormat('D d/m') }} @endif</div></div>
    <div class="kpi"><div class="lbl">Berikutnya</div>
        <div class="val val-text">{{ $next->isEmpty() ? 'belum dijadwalkan' : $next->pluck('user.name')->unique()->implode(', ') }}</div>
        <div class="note">@if($next->isNotEmpty()) mulai {{ $next->first()->starts_at->isToday() ? 'hari ini' : $next->first()->starts_at->translatedFormat('D d/m') }} jam {{ $next->first()->starts_at->format('H.i') }} @else tambahkan giliran di bawah @endif</div></div>
    <div class="kpi {{ $channels->isEmpty() ? 'warn' : '' }}"><div class="lbl">Kanal ke piket</div><div class="val">{{ $channels->count() }}</div>
        <div class="note">@if($channels->isEmpty()) belum ada kanal yang memakai jadwal ini @else {{ $channels->pluck('name')->implode(', ') }} @endif</div></div>
    <div class="kpi {{ $noContact->isNotEmpty() ? 'crit' : '' }}"><div class="lbl">Tanpa kontak</div><div class="val">{{ $noContact->count() }}</div>
        <div class="note">{{ $noContact->isEmpty() ? 'semua yang dijadwalkan punya email atau Telegram' : $noContact->pluck('name')->implode(', ') }}</div></div>
</div>

@if($noContact->isNotEmpty())
    <p class="alert warn"><x-icon name="alert"/><span><b>{{ $noContact->pluck('name')->implode(', ') }}</b> punya giliran piket tetapi belum mengisi email maupun Telegram. Saat mereka piket, notifikasi jatuh ke tujuan cadangan kanal. Isi lewat Profil masing-masing atau menu Pengguna.</span></p>
@endif

<section class="card">
    <div class="hd"><h2>Minggu {{ $week->translatedFormat('d M') }} sampai {{ $week->copy()->addDays(6)->translatedFormat('d M Y') }}</h2>
        <div class="actions">
            <a class="btn sm" href="{{ route('oncall.index', ['minggu' => $week->copy()->subWeek()->format('Y-m-d')]) }}">Minggu lalu</a>
            <a class="btn sm" href="{{ route('oncall.index') }}">Minggu ini</a>
            <a class="btn sm" href="{{ route('oncall.index', ['minggu' => $week->copy()->addWeek()->format('Y-m-d')]) }}">Minggu depan</a>
        </div></div>
    <div class="bd">
        <ol class="oc-week">
            @foreach($days as $d)
            <li class="{{ $d['date']->isSameDay($today) ? 'is-today' : '' }}">
                <div class="oc-day">{{ $d['date']->translatedFormat('l') }}<small>{{ $d['date']->translatedFormat('d M') }}{{ $d['date']->isSameDay($today) ? ', hari ini' : '' }}</small></div>
                @forelse($d['slots'] as $s)
                    @php($live = $s->starts_at->isPast() && $s->ends_at->isFuture())
                    <div class="oc-slot {{ $live ? 'is-live' : '' }}">
                        <b>{{ $s->user->name }}</b>
                        <span>{{ $s->starts_at->isSameDay($d['date']) ? $s->starts_at->format('H.i') : 'dari kemarin' }} sampai {{ $s->ends_at->copy()->subSecond()->isSameDay($d['date']) ? ($s->ends_at->format('H:i') === '00:00' ? '24.00' : $s->ends_at->format('H.i')) : 'besok' }}</span>
                        @if($live)<span class="badge plain ok">bertugas</span>@endif
                    </div>
                @empty
                    <div class="oc-none">tidak ada piket</div>
                @endforelse
            </li>
            @endforeach
        </ol>
    </div>
</section>

@if($canEdit)
<div class="grid g-2" style="margin-top:var(--space-xl)">
    <section class="card"><div class="hd"><h2>Buat rotasi</h2></div><div class="bd">
        <form method="post" action="{{ route('oncall.rotate') }}" class="form">
            @csrf
            <fieldset class="field"><legend>Urutan orang</legend>
                <div class="row r3">
                    @for($i = 0; $i < 6; $i++)
                    <select class="input" name="people[]" aria-label="Orang ke-{{ $i + 1 }}">
                        <option value="">{{ $i ? 'Orang ke-' . ($i + 1) . ' (opsional)' : 'Orang pertama' }}</option>
                        @foreach($users as $u)<option value="{{ $u->id }}" @selected((old('people')[$i] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                    </select>
                    @endfor
                </div>
                <div class="hint">Giliran dibagi bergantian sesuai urutan ini.</div><x-field-error name="people"/></fieldset>
            <div class="row">
                <div class="field"><label for="start_date">Mulai tanggal</label><input class="input" type="date" id="start_date" name="start_date" value="{{ old('start_date', $week->isPast() ? now()->format('Y-m-d') : $week->format('Y-m-d')) }}" required><x-field-error name="start_date"/></div>
                <div class="field"><label for="start_hour">Jam ganti giliran pertama</label><select class="input" id="start_hour" name="start_hour">
                    @for($h = 0; $h < 24; $h++)<option value="{{ $h }}" @selected((int) old('start_hour', 7) === $h)>{{ sprintf('%02d.00', $h) }}</option>@endfor</select></div>
            </div>
            <div class="row">
                <div class="field"><label for="shift">Panjang giliran</label><select class="input" id="shift" name="shift">
                    @foreach(\App\Http\Controllers\OncallController::SHIFTS as $k => $v)<option value="{{ $k }}" @selected((int) old('shift', 12) === $k)>{{ $v }}</option>@endforeach</select></div>
                <div class="field"><label for="days">Untuk berapa hari</label><input class="input" type="number" min="1" max="31" id="days" name="days" value="{{ old('days', 7) }}" required><x-field-error name="days"/></div>
            </div>
            <label class="check"><input type="checkbox" name="replace" value="1" @checked(old('replace', true))><span>Ganti giliran yang sudah ada di rentang ini<small>Tanpa centang, giliran baru ditambahkan di samping yang lama.</small></span></label>
            <div class="form-foot"><button class="btn primary"><x-icon name="refresh"/>Buat rotasi</button></div>
        </form>
    </div></section>

    <section class="card"><div class="hd"><h2>Tambah satu giliran</h2></div><div class="bd">
        <form method="post" action="{{ route('oncall.store') }}" class="form">
            @csrf
            <div class="field"><label for="user_id">Orang</label><select class="input @error('user_id') is-err @enderror" id="user_id" name="user_id" required>
                <option value="">Pilih orang</option>
                @foreach($users as $u)<option value="{{ $u->id }}" @selected(old('user_id') == $u->id)>{{ $u->name }}{{ $u->email || $u->telegram_chat_id ? '' : ' (belum ada kontak)' }}</option>@endforeach</select><x-field-error name="user_id"/></div>
            <div class="row">
                <div class="field"><label for="starts_at">Mulai</label><input class="input @error('starts_at') is-err @enderror" type="datetime-local" id="starts_at" name="starts_at" value="{{ old('starts_at') }}" required><x-field-error name="starts_at"/></div>
                <div class="field"><label for="ends_at">Selesai</label><input class="input @error('ends_at') is-err @enderror" type="datetime-local" id="ends_at" name="ends_at" value="{{ old('ends_at') }}" required><x-field-error name="ends_at"/></div>
            </div>
            <div class="field"><label for="note">Keterangan <span class="muted">(opsional)</span></label><input class="input" id="note" name="note" maxlength="160" value="{{ old('note') }}" placeholder="mis. tukar giliran dengan Budi"></div>
            <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Tambah</button></div>
        </form>
        <p class="hint muted" style="margin:12px 0 0">Untuk tukar giliran atau cuti: tambah giliran pengganti, lalu hapus giliran lama di daftar bawah. Dua orang boleh piket di jam yang sama; email dikirim ke keduanya.</p>
    </div></section>
</div>
@endif

<section class="card" style="margin-top:var(--space-xl)">
    <div class="hd"><h2>Giliran berjalan dan mendatang</h2></div>
    <div class="bd flush">
    @if($upcoming->isEmpty())
        <div class="empty">Belum ada giliran piket. {{ $canEdit ? 'Buat rotasi di atas untuk mengisi seminggu sekaligus.' : 'Minta engineer atau admin mengisi jadwal.' }}</div>
    @else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Waktu</th><th>Orang</th><th>Kontak</th><th>Keterangan</th>@if($canEdit)<th><span class="sr-only">Aksi</span></th>@endif</tr></thead>
        <tbody>
        @foreach($upcoming as $s)
            @php($live = $s->starts_at->isPast())
            <tr>
                <td class="nowrap">{{ $s->starts_at->translatedFormat('D d/m') }}, {{ $span($s) }}@if($live) <span class="badge plain ok">bertugas</span>@endif</td>
                <td class="name">{{ $s->user->name }}<small>{{ $s->user->roleLabel() }}</small></td>
                <td>@if($contact($s->user) !== ''){{ $contact($s->user) }} @else <span class="badge plain warn">belum ada kontak</span>@endif</td>
                <td class="muted">{{ $s->note }}</td>
                @if($canEdit)<td class="right"><form method="post" action="{{ route('oncall.destroy', $s) }}" data-confirm="Hapus giliran {{ $s->user->name }}?">@csrf @method('DELETE')<button class="btn sm danger" aria-label="Hapus giliran {{ $s->user->name }} {{ $s->starts_at->format('d/m H.i') }}"><x-icon name="trash"/></button></form></td>@endif
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>
@endsection
