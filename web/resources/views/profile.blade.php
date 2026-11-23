@extends('layouts.app')
@section('title', 'Profil')
@section('content')
<div class="page-head"><div><h1>Profil</h1><p>{{ auth()->user()->name }}, {{ auth()->user()->username }}, {{ auth()->user()->roleLabel() }}</p></div></div>
@if($mustEnroll)
<p class="alert warn" style="max-width:760px"><x-icon name="alert"/><span>Kebijakan login mewajibkan <b>verifikasi dua langkah</b> untuk akun Anda. Aktifkan di bawah untuk melanjutkan memakai ANTAREJA.</span></p>
@endif
<section class="card" id="dua-langkah" style="max-width:760px"><div class="hd"><h2>Verifikasi dua langkah</h2>@if(auth()->user()->totp_enabled_at)<span class="badge ok">aktif sejak {{ auth()->user()->totp_enabled_at->format('d/m/Y') }}</span>@endif</div><div class="bd">
    @if(session('recovery_codes'))
        <div class="alert warn"><x-icon name="key"/><div><b>Kode cadangan</b>: simpan di tempat aman (mis. dicetak dan disimpan di lemari NOC). Masing-masing hanya bisa dipakai sekali, untuk masuk bila HP hilang.
            <ul class="recovery mono">@foreach(session('recovery_codes') as $c)<li>{{ $c }}</li>@endforeach</ul></div></div>
    @endif
    @if(auth()->user()->totp_enabled_at)
        <p style="margin-top:0">Setiap login meminta kode 6 digit dari aplikasi autentikator. Sisa kode cadangan: <b>{{ $recoveryLeft }}</b>.</p>
        <div class="grid g-2">
            <form method="post" action="{{ route('profile.2fa.recovery') }}" class="form">@csrf
                <div class="field"><label for="rc-code">Buat ulang kode cadangan</label><input class="input mono" id="rc-code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="kode 6 digit saat ini" required></div>
                <button class="btn">Buat kode cadangan baru</button></form>
            @unless($mustEnroll)
            <form method="post" action="{{ route('profile.2fa.disable') }}" class="form" data-confirm="Matikan verifikasi dua langkah? Akun hanya dilindungi password.">@csrf @method('DELETE')
                <div class="field"><label for="off-code">Matikan 2FA</label><input class="input mono @error('code') is-err @enderror" id="off-code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="kode 6 digit saat ini" required><x-field-error name="code"/></div>
                <button class="btn danger">Matikan</button></form>
            @endunless
        </div>
    @elseif($setupSecret)
        <ol class="help-steps" style="margin-top:0">
            <li>Pasang aplikasi autentikator di HP (Google Authenticator, Microsoft Authenticator, Aegis, atau FreeOTP).</li>
            <li>Pindai kode QR ini, atau ketik kunci di bawahnya secara manual.</li>
            <li>Masukkan 6 digit kode yang muncul di aplikasi.</li>
        </ol>
        <div class="totp-setup">
            <div class="totp-qr" data-otpauth="{{ $setupUri }}" role="img" aria-label="Kode QR untuk aplikasi autentikator"></div>
            <div>
                <div class="field"><label>Kunci (bila tidak bisa memindai)</label><code class="totp-key">{{ trim(chunk_split($setupSecret, 4, ' ')) }}</code></div>
                <form method="post" action="{{ route('profile.2fa.enable') }}" class="form">@csrf
                    <div class="field"><label for="en-code">Kode dari aplikasi</label><input class="input mono @error('code') is-err @enderror" id="en-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required autofocus><x-field-error name="code"/></div>
                    <button class="btn primary"><x-icon name="check"/>Aktifkan</button></form>
            </div>
        </div>
    @else
        <p style="margin-top:0">Selain password, setiap login meminta kode 6 digit dari aplikasi di HP. Password yang bocor saja tidak cukup untuk masuk.</p>
        <form method="post" action="{{ route('profile.2fa.start') }}">@csrf<button class="btn primary"><x-icon name="shield"/>Aktifkan verifikasi dua langkah</button></form>
    @endif
</div></section>

@if(auth()->user()->auth_source === 'local')
<section class="card" style="max-width:560px;margin-top:var(--space-xl)"><div class="hd"><h2>Ganti password</h2></div><div class="bd">
<form method="post" action="{{ route('profile.password') }}" class="form">
    @csrf @method('PUT')
    <div class="field"><label for="current_password">Password saat ini</label><input class="input @error('current_password') is-err @enderror" type="password" id="current_password" name="current_password" autocomplete="current-password" required><x-field-error name="current_password"/></div>
    <div class="field"><label for="password">Password baru</label><input class="input @error('password') is-err @enderror" type="password" id="password" name="password" autocomplete="new-password" required><div class="hint">Minimal 10 karakter, huruf dan angka.</div><x-field-error name="password"/></div>
    <div class="field"><label for="password_confirmation">Ulangi password baru</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>
    <div class="form-foot"><button class="btn primary"><x-icon name="key"/>Ganti password</button></div>
</form>
</div></section>
@else
<p class="muted" style="margin-top:var(--space-xl)">Akun ini masuk lewat direktori kantor (LDAP). Password diganti di sana, bukan di ANTAREJA.</p>
@endif
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
