@extends('layouts.auth')
@section('title', 'Verifikasi dua langkah')
@section('content')
    <h2>Verifikasi dua langkah</h2>
    <p class="muted" style="margin:0 0 20px">Buka aplikasi autentikator di HP, lalu masukkan 6 digit kode ANTAREJA NMS.</p>
    @include('partials.flash', ['hideErrorSummary' => true])
    <form method="post" action="{{ route('login.2fa.store') }}" class="form">
        @csrf
        <div class="field">
            <label for="code">Kode</label>
            <input class="input mono @error('code') is-err @enderror" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="32" required autofocus style="font-size:20px;letter-spacing:.2em;text-align:center">
            <x-field-error name="code"/>
            <div class="hint">HP hilang? Masukkan salah satu kode cadangan yang Anda simpan saat mengaktifkan 2FA.</div>
        </div>
        <button class="btn primary" style="width:100%;justify-content:center;height:40px">Verifikasi</button>
    </form>
    <p style="margin:16px 0 0;text-align:center"><a href="{{ route('login') }}">Kembali ke halaman masuk</a></p>
@endsection
