@extends('layouts.app')
@section('title', 'Kebijakan login')
@section('content')
<div class="crumbs"><a href="{{ route('users.index') }}">Pengguna</a> / Kebijakan login</div>
<div class="page-head"><div><h1>Kebijakan login</h1><p>Verifikasi dua langkah, login dengan akun kantor (LDAP / Active Directory), dan kebijakan sesi, akun, serta log audit</p></div></div>
<div class="grid g-2">
    <div class="stack">
    <section class="card" id="kebijakan-2fa"><div class="hd"><h2>Verifikasi dua langkah</h2></div><div class="bd">
        <form method="post" action="{{ route('auth.settings.policy') }}" class="form">@csrf @method('PUT')
            <div class="field"><label for="require_2fa">Wajib 2FA</label><select class="input" id="require_2fa" name="require_2fa">
                @foreach(\App\Http\Controllers\AuthSettingsController::POLICIES as $k => $v)<option value="{{ $k }}" @selected($policy === $k)>{{ $v }}</option>@endforeach</select>
                <div class="hint">Saat ini {{ $counts['without2fa'] }} akun aktif belum memakai 2FA, {{ $counts['adminsWithout2fa'] }} di antaranya admin. Akun yang wajib tetapi belum aktif hanya bisa membuka Profil sampai 2FA diaktifkan.</div></div>
            <div class="form-foot"><button class="btn primary">Simpan kebijakan</button></div>
        </form>
        <p class="hint muted" style="margin:var(--space-sm) 0 0">Bila seorang pengguna kehilangan HP dan kode cadangan, buka akunnya di halaman Pengguna lalu klik Reset 2FA.</p>
    </div></section>

    <section class="card" id="keamanan"><div class="hd"><h2>Sesi, akun, dan log audit</h2></div><div class="bd">
        <form method="post" action="{{ route('auth.settings.security') }}" class="form">@csrf @method('PUT')
            <div class="row">
                <div class="field"><label for="idle_minutes">Keluar otomatis bila diam (menit)</label><input class="input @error('idle_minutes') is-err @enderror" type="number" id="idle_minutes" name="idle_minutes" min="0" max="480" value="{{ old('idle_minutes', $security['idle_minutes']) }}">
                    <div class="hint">Untuk admin dan engineer. 0 = mati. Akun viewer untuk Layar NOC tidak terkena.</div><x-field-error name="idle_minutes"/></div>
                <div class="field"><label for="disable_inactive_days">Nonaktifkan akun tak dipakai (hari)</label><input class="input @error('disable_inactive_days') is-err @enderror" type="number" id="disable_inactive_days" name="disable_inactive_days" min="0" max="730" value="{{ old('disable_inactive_days', $security['disable_inactive_days']) }}">
                    <div class="hint">Akun yang tidak login selama itu dinonaktifkan tiap malam dan dicatat di log audit. Admin aktif terakhir tidak pernah dinonaktifkan. 0 = mati.</div><x-field-error name="disable_inactive_days"/></div>
            </div>
            <div class="field"><label for="audit_keep_days">Simpan log audit (hari)</label><input class="input @error('audit_keep_days') is-err @enderror" type="number" id="audit_keep_days" name="audit_keep_days" min="0" max="3650" value="{{ old('audit_keep_days', $security['audit_keep_days']) }}">
                <div class="hint">0 = selamanya. Minimal 365 hari bila diisi. Catatan yang lebih tua dihapus tiap malam; selain itu log audit tidak bisa dihapus.</div><x-field-error name="audit_keep_days"/></div>
            <div class="field"><label for="login_notice">Pemberitahuan di halaman login</label><textarea class="input @error('login_notice') is-err @enderror" id="login_notice" name="login_notice" rows="2" maxlength="300">{{ old('login_notice', $security['login_notice']) }}</textarea>
                <div class="hint">Tampil di bawah form login. Kosongkan untuk menyembunyikan.</div><x-field-error name="login_notice"/></div>
            <div class="form-foot"><button class="btn primary">Simpan kebijakan</button></div>
        </form>
    </div></section>
    </div>

    <section class="card" id="ldap"><div class="hd"><h2>Login LDAP / Active Directory</h2>@if($ldap['enabled'])<span class="badge ok">aktif</span>@else<span class="badge">mati</span>@endif</div><div class="bd">
        @unless($ldapAvailable)<p class="alert warn"><x-icon name="alert"/><span>Ekstensi PHP ldap tidak tersedia di server web ini, jadi login LDAP tidak bisa dipakai.</span></p>@endunless
        <form method="post" action="{{ route('auth.settings.ldap') }}" class="form">@csrf @method('PUT')
            <div class="field"><label for="ld-en">Status</label><select class="input" id="ld-en" name="enabled">
                <option value="0" @selected(! old('enabled', $ldap['enabled']))>Mati, hanya akun lokal</option>
                <option value="1" @selected(old('enabled', $ldap['enabled']))>Aktif, akun kantor bisa login</option></select></div>
            <div class="stack-sm" data-show-if="enabled=1">
                <div class="row r3">
                    <div class="field"><label for="ld-host">Server</label><input class="input mono @error('host') is-err @enderror" id="ld-host" name="host" value="{{ old('host', $ldap['host'] ?? '') }}" placeholder="dc01.kantor.local"><x-field-error name="host"/></div>
                    <div class="field"><label for="ld-enc">Enkripsi</label><select class="input" id="ld-enc" name="encryption">
                        @foreach(['ldaps' => 'LDAPS (636)', 'starttls' => 'StartTLS (389)', 'none' => 'Tanpa enkripsi'] as $k => $v)<option value="{{ $k }}" @selected(old('encryption', $ldap['encryption'] ?? 'ldaps') === $k)>{{ $v }}</option>@endforeach</select></div>
                    <div class="field"><label for="ld-port">Port</label><input class="input" type="number" id="ld-port" name="port" value="{{ old('port', $ldap['port'] ?? '') }}" placeholder="bawaan"><x-field-error name="port"/></div>
                </div>
                <label class="check"><input type="checkbox" name="verify_cert" value="1" @checked(old('verify_cert', $ldap['verify_cert'] ?? true))><span>Periksa sertifikat server<small>Matikan hanya bila sertifikat dari CA internal belum dipasang di server ANTAREJA.</small></span></label>
                <label class="check" data-show-if="encryption=none"><input type="checkbox" name="allow_plain" value="1"><span>Saya paham password lewat jaringan tanpa enkripsi</span></label>
                <div class="field"><label for="ld-base">Base DN</label><input class="input mono @error('base_dn') is-err @enderror" id="ld-base" name="base_dn" value="{{ old('base_dn', $ldap['base_dn'] ?? '') }}" placeholder="DC=kantor,DC=local"><x-field-error name="base_dn"/></div>
                <div class="row">
                    <div class="field"><label for="ld-bind">Akun layanan (bind DN)</label><input class="input mono" id="ld-bind" name="bind_dn" value="{{ old('bind_dn', $ldap['bind_dn'] ?? '') }}" autocomplete="off" placeholder="CN=svc-antareja,OU=Service,DC=kantor,DC=local"></div>
                    <div class="field"><label for="ld-pass">Password akun layanan</label><input class="input" type="password" id="ld-pass" name="bind_password" autocomplete="new-password" placeholder="{{ $hasBindPassword ? 'tersimpan' : '' }}"></div>
                </div>
                <div class="field"><label for="ld-filter">Filter pengguna</label><input class="input mono @error('user_filter') is-err @enderror" id="ld-filter" name="user_filter" value="{{ old('user_filter', $ldap['user_filter'] ?? '') }}" placeholder="(&(objectClass=user)(sAMAccountName={username}))">
                    <div class="hint">OpenLDAP biasanya <code>(uid={username})</code>.</div><x-field-error name="user_filter"/></div>
                <div class="row">
                    <div class="field"><label for="ld-name">Atribut nama</label><input class="input mono @error('name_attr') is-err @enderror" id="ld-name" name="name_attr" value="{{ old('name_attr', $ldap['name_attr'] ?? '') }}" placeholder="displayName"><x-field-error name="name_attr"/></div>
                    <div class="field"><label for="ld-mail">Atribut email</label><input class="input mono @error('mail_attr') is-err @enderror" id="ld-mail" name="mail_attr" value="{{ old('mail_attr', $ldap['mail_attr'] ?? '') }}" placeholder="mail"><x-field-error name="mail_attr"/></div>
                </div>
                <div class="field"><label for="ld-ga">Grup admin</label><input class="input mono" id="ld-ga" name="admin_group" value="{{ old('admin_group', $ldap['admin_group'] ?? '') }}" placeholder="CN=NMS-Admin,OU=Groups,DC=kantor,DC=local"></div>
                <div class="field"><label for="ld-ge">Grup engineer</label><input class="input mono" id="ld-ge" name="engineer_group" value="{{ old('engineer_group', $ldap['engineer_group'] ?? '') }}" placeholder="CN=NMS-Engineer,OU=Groups,DC=kantor,DC=local"></div>
                <div class="field"><label for="ld-gv">Grup viewer</label><input class="input mono" id="ld-gv" name="viewer_group" value="{{ old('viewer_group', $ldap['viewer_group'] ?? '') }}" placeholder="CN=NMS-Viewer,OU=Groups,DC=kantor,DC=local">
                    <div class="hint">Kosongkan grup viewer bila semua akun di Base DN boleh melihat. Peran diperbarui dari grup setiap kali login.</div></div>
                @if($hasBindPassword)<label class="check"><input type="checkbox" name="clear_bind_password" value="1"><span>Hapus password akun layanan tersimpan</span></label>@endif
            </div>
            <div class="form-foot"><button class="btn primary">Simpan</button></div>
        </form>
        @if(! empty($ldap['host']))
        <form method="post" action="{{ route('auth.settings.ldap.test') }}" class="form" style="margin-top:var(--space-md)">@csrf
            <div class="row">
                <div class="field"><label for="lt-user">Akun uji <span class="muted">(opsional)</span></label><input class="input mono" id="lt-user" name="username" autocomplete="off"></div>
                <div class="field"><label for="lt-pass">Password akun uji</label><input class="input" type="password" id="lt-pass" name="password" autocomplete="off"></div>
            </div>
            <div><button class="btn"><x-icon name="check"/>Uji koneksi</button></div>
        </form>
        @endif
        <p class="hint muted" style="margin:var(--space-sm) 0 0">Akun kantor yang login pertama kali dibuat otomatis dengan peran dari grupnya ({{ $counts['ldapUsers'] }} akun LDAP saat ini). Akun lokal, termasuk admin, tetap login dengan password ANTAREJA walau server LDAP mati. Password akun layanan terenkripsi di database. Akun layanan cukup punya hak baca.</p>
    </div></section>

</div>
@endsection
