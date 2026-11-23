@extends('layouts.app')
@section('title', 'Profil')
@section('content')
<div class="page-head"><div><h1>Profil</h1><p>{{ auth()->user()->name }}, {{ auth()->user()->username }}, {{ auth()->user()->roleLabel() }}</p></div></div>
<section class="card" style="max-width:560px"><div class="hd"><h2>Ganti password</h2></div><div class="bd">
<form method="post" action="{{ route('profile.password') }}" class="form">
    @csrf @method('PUT')
    <div class="field"><label for="current_password">Password saat ini</label><input class="input @error('current_password') is-err @enderror" type="password" id="current_password" name="current_password" autocomplete="current-password" required><x-field-error name="current_password"/></div>
    <div class="field"><label for="password">Password baru</label><input class="input @error('password') is-err @enderror" type="password" id="password" name="password" autocomplete="new-password" required><div class="hint">Minimal 10 karakter, huruf dan angka.</div><x-field-error name="password"/></div>
    <div class="field"><label for="password_confirmation">Ulangi password baru</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>
    <div class="form-foot"><button class="btn primary"><x-icon name="key"/>Ganti password</button></div>
</form>
</div></section>
<section class="card" style="max-width:560px;margin-top:var(--space-xl)"><div class="hd"><h2>Kontak untuk piket</h2></div><div class="bd">
<p class="muted" style="margin-top:0">Saat Anda sedang piket, kanal notifikasi yang diset kirim ke yang sedang piket mengirim problem ke email dan Telegram di sini.</p>
<form method="post" action="{{ route('profile.contact') }}" class="form">
    @csrf @method('PUT')
    @php($me = auth()->user())
    <div class="field"><label for="email">Email</label><input class="input @error('email') is-err @enderror" type="email" id="email" name="email" value="{{ old('email', $me->email) }}" autocomplete="email"><x-field-error name="email"/></div>
    <div class="field"><label for="telegram_chat_id">Chat ID Telegram</label><input class="input @error('telegram_chat_id') is-err @enderror" id="telegram_chat_id" name="telegram_chat_id" inputmode="numeric" value="{{ old('telegram_chat_id', $me->telegram_chat_id) }}">
        <div class="hint">Kirim /start ke bot notifikasi kantor dulu (bot tidak bisa memulai obrolan), lalu buka @userinfobot untuk melihat angka ID Anda.</div><x-field-error name="telegram_chat_id"/></div>
    <div class="field"><label for="phone">Nomor HP</label><input class="input @error('phone') is-err @enderror" type="tel" id="phone" name="phone" value="{{ old('phone', $me->phone) }}" autocomplete="tel" placeholder="0812 3456 7890"><x-field-error name="phone"/></div>
    <div class="form-foot"><button class="btn primary">Simpan kontak</button></div>
</form>
</div></section>
@endsection
