@extends('layouts.app')
@php($editing = $user->exists)
@section('title', $editing ? 'Ubah ' . $user->username : 'Tambah pengguna')
@section('content')
<div class="crumbs"><a href="{{ route('users.index') }}">Pengguna</a> / {{ $editing ? $user->username : 'Tambah' }}</div>
<div class="page-head"><div><h1>{{ $editing ? 'Ubah pengguna' : 'Tambah pengguna' }}</h1></div></div>
<section class="card" style="max-width:720px"><div class="bd">
<form method="post" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="form">
    @csrf @if($editing) @method('PUT') @endif
    <div class="row">
        <div class="field"><label for="name">Nama lengkap</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required><x-field-error name="name"/></div>
        <div class="field"><label for="username">Username</label><input class="input @error('username') is-err @enderror" id="username" name="username" value="{{ old('username', $user->username) }}" autocapitalize="none" required><x-field-error name="username"/></div>
    </div>
    <div class="row">
        <div class="field"><label for="email">Email <span class="muted">(opsional)</span></label><input class="input @error('email') is-err @enderror" type="email" id="email" name="email" value="{{ old('email', $user->email) }}"><x-field-error name="email"/></div>
        <div class="field"><label for="role">Peran</label><select class="input" id="role" name="role">
            @foreach(\App\Models\User::ROLES as $k => $v)<option value="{{ $k }}" @selected(old('role', $user->role) === $k)>{{ $v }}</option>@endforeach</select></div>
    </div>
    <div class="row">
        <div class="field"><label for="telegram_chat_id">Chat ID Telegram <span class="muted">(opsional)</span></label><input class="input @error('telegram_chat_id') is-err @enderror" id="telegram_chat_id" name="telegram_chat_id" inputmode="numeric" value="{{ old('telegram_chat_id', $user->telegram_chat_id) }}">
            <div class="hint">Dipakai kanal Telegram yang dikirim ke yang sedang piket.</div><x-field-error name="telegram_chat_id"/></div>
        <div class="field"><label for="phone">Nomor HP <span class="muted">(opsional)</span></label><input class="input @error('phone') is-err @enderror" type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="0812 3456 7890">
            <div class="hint">Tampil di jadwal piket supaya bisa ditelepon.</div><x-field-error name="phone"/></div>
    </div>
    <div class="row">
        <div class="field"><label for="password">{{ $editing ? 'Password baru' : 'Password' }}</label>
            <input class="input @error('password') is-err @enderror" type="password" id="password" name="password" autocomplete="new-password" {{ $editing ? '' : 'required' }}>
            <div class="hint">{{ $editing ? 'Kosongkan bila tidak diganti. ' : '' }}Minimal 10 karakter, huruf dan angka.</div><x-field-error name="password"/></div>
        <div class="field"><label for="password_confirmation">Ulangi password</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password"></div>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $user->active ?? true))><span>Akun aktif<small>Akun nonaktif tidak bisa login.</small></span></label>
    <div class="form-foot"><a class="btn" href="{{ route('users.index') }}">Batal</a><button class="btn primary">Simpan</button></div>
</form>
</div></section>
@endsection
