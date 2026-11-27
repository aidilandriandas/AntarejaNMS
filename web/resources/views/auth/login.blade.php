@extends('layouts.auth')
@section('title', 'Masuk')
@section('content')
    <h2>Masuk</h2>
    <p class="muted" style="margin:0 0 20px">Gunakan akun ANTAREJA Anda.</p>
    @include('partials.flash', ['hideErrorSummary' => true])
    <form method="post" action="{{ route('login.store') }}" class="form">
        @csrf
        <div class="field">
            <label for="username">Username</label>
            <input class="input @error('username') is-err @enderror" id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" required autofocus>
            <x-field-error name="username"/>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <label class="check"><input type="checkbox" name="remember" value="1"><span>Ingat saya<small>Untuk PC ruang kontrol / layar NOC</small></span></label>
        <button class="btn primary" style="width:100%;justify-content:center;height:40px">Masuk</button>
    </form>
    @php($notice = \App\Http\Controllers\AuthSettingsController::security()['login_notice'])
    @if($notice !== '')<p class="hint muted" style="margin:16px 0 0">{{ $notice }}</p>@endif
@endsection
