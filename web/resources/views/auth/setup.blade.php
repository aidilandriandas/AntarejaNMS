@extends('layouts.auth')
@section('title', 'Setup Awal')
@section('content')
    <h2>Setup Awal</h2>
    <p class="muted" style="margin:0 0 20px">Buat akun admin pertama. Halaman ini hanya muncul sekali.</p>
    @include('partials.flash')
    <form method="post" action="{{ route('setup.store') }}" class="form">
        @csrf
        <div class="field"><label for="name">Nama lengkap</label>
            <input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name') }}" required autofocus><x-field-error name="name"/></div>
        <div class="field"><label for="username">Username</label>
            <input class="input @error('username') is-err @enderror" id="username" name="username" value="{{ old('username') }}" autocapitalize="none" required>
            <div class="hint">Huruf kecil, angka, titik, garis bawah, atau strip.</div><x-field-error name="username"/></div>
        <div class="field"><label for="email">Email <span class="muted">(opsional)</span></label>
            <input class="input @error('email') is-err @enderror" type="email" id="email" name="email" value="{{ old('email') }}"><x-field-error name="email"/></div>
        <div class="field"><label for="password">Password</label>
            <input class="input @error('password') is-err @enderror" type="password" id="password" name="password" autocomplete="new-password" required>
            <div class="hint">Minimal 10 karakter, berisi huruf dan angka.</div><x-field-error name="password"/></div>
        <div class="field"><label for="password_confirmation">Ulangi password</label>
            <input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>
        <button class="btn primary" style="width:100%;justify-content:center;height:40px">Buat akun admin</button>
    </form>
@endsection
