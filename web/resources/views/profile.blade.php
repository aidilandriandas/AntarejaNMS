@extends('layouts.app')
@section('title', 'Profil')
@section('content')
<div class="page-head"><div><h1>Profil</h1><p>{{ auth()->user()->name }} · {{ auth()->user()->username }} · {{ auth()->user()->roleLabel() }}</p></div></div>
<section class="card" style="max-width:560px"><div class="hd"><h2>Ganti password</h2></div><div class="bd">
<form method="post" action="{{ route('profile.password') }}" class="form">
    @csrf @method('PUT')
    <div class="field"><label for="current_password">Password saat ini</label><input class="input @error('current_password') is-err @enderror" type="password" id="current_password" name="current_password" autocomplete="current-password" required><x-field-error name="current_password"/></div>
    <div class="field"><label for="password">Password baru</label><input class="input @error('password') is-err @enderror" type="password" id="password" name="password" autocomplete="new-password" required><div class="hint">Minimal 10 karakter, huruf dan angka.</div><x-field-error name="password"/></div>
    <div class="field"><label for="password_confirmation">Ulangi password baru</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>
    <div class="form-foot"><button class="btn primary"><x-icon name="key"/>Ganti password</button></div>
</form>
</div></section>
@endsection
