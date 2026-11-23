@extends('layouts.app')
@php($editing = $c->exists)
@section('title', $editing ? 'Ubah cek' : 'Tambah cek')
@section('content')
<div class="crumbs"><a href="{{ route('checks.index') }}">Cek layanan</a> / {{ $editing ? $c->name : 'Tambah' }}</div>
<div class="page-head"><div><h1>{{ $editing ? 'Ubah cek layanan' : 'Tambah cek layanan' }}</h1>
    <p>Dijalankan dari server ANTAREJA. Hanya membaca: HTTP memakai GET tanpa login dan tanpa mengikuti pengalihan, TCP hanya membuka lalu menutup koneksi, DNS hanya bertanya.</p></div></div>

<form method="post" action="{{ $editing ? route('checks.update', $c) : route('checks.store') }}" class="form" style="max-width:760px">@csrf @if($editing) @method('PUT') @endif
    <div class="row">
        <div class="field"><label for="name">Nama</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name', $c->name) }}" maxlength="128" required placeholder="mis. Login FMS Dispatch"><x-field-error name="name"/></div>
        <div class="field"><label for="device_id">Perangkat atau server</label><select class="input @error('device_id') is-err @enderror" id="device_id" name="device_id" required>
            <option value="">Pilih perangkat</option>
            @foreach($devices as $d)<option value="{{ $d->id }}" @selected(old('device_id', $c->device_id) == $d->id)>{{ $d->name }} ({{ $d->host }})</option>@endforeach</select>
            <div class="hint">Yang menjalankan layanan ini. Problem cek ikut maintenance dan perangkat induknya.</div><x-field-error name="device_id"/></div>
    </div>
    <div class="field"><label for="type">Jenis</label><select class="input" id="type" name="type">
        @foreach(\App\Models\ServiceCheck::TYPES as $k => $v)<option value="{{ $k }}" @selected(old('type', $c->type) === $k)>{{ $v }}</option>@endforeach</select></div>

    <div class="stack-sm" data-show-if="type=http">
        <div class="field"><label for="url">Alamat</label><input class="input mono @error('url') is-err @enderror" id="url" name="url" value="{{ old('url', $c->url) }}" maxlength="500" placeholder="https://fms.tambang.local/login"><x-field-error name="url"/></div>
        <div class="row">
            <div class="field"><label for="expect_status">Kode HTTP yang diharapkan</label><input class="input mono @error('expect_status') is-err @enderror" id="expect_status" name="expect_status" value="{{ old('expect_status', $c->expect_status) }}" placeholder="200-399"><x-field-error name="expect_status"/></div>
            <div class="field"><label for="expect_text">Teks wajib ada <span class="muted">(opsional)</span></label><input class="input @error('expect_text') is-err @enderror" id="expect_text" name="expect_text" value="{{ old('expect_text', $c->expect_text) }}" maxlength="200" placeholder="mis. Masuk ke Dispatch">
                <div class="hint">Memastikan aplikasinya benar-benar jalan, bukan hanya web server-nya.</div><x-field-error name="expect_text"/></div>
        </div>
        <div class="row">
            <label class="check"><input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $c->verify_tls))><span>Periksa sertifikat HTTPS<small>Matikan untuk perangkat dengan sertifikat bawaan (self-signed).</small></span></label>
            <div class="field"><label for="cert_warn_days">Peringatkan sertifikat habis (hari sebelumnya)</label><input class="input" type="number" id="cert_warn_days" name="cert_warn_days" min="1" max="365" value="{{ old('cert_warn_days', $c->cert_warn_days) }}" placeholder="kosong = tidak"></div>
        </div>
    </div>
    <div class="field" data-show-if="type=tcp,dns"><label for="port">Port</label><input class="input @error('port') is-err @enderror" type="number" id="port" name="port" min="1" max="65535" value="{{ old('port', $c->port) }}" placeholder="TCP: mis. 3306, 1433, 22. DNS: kosong = 53"><x-field-error name="port"/></div>
    <div class="row" data-show-if="type=dns">
        <div class="field"><label for="query">Nama yang ditanyakan</label><input class="input mono @error('query') is-err @enderror" id="query" name="query" value="{{ old('query', $c->query) }}" maxlength="255" placeholder="fms.tambang.local"><x-field-error name="query"/></div>
        <div class="field"><label for="expect_answer">IP yang diharapkan <span class="muted">(opsional)</span></label><input class="input mono @error('expect_answer') is-err @enderror" id="expect_answer" name="expect_answer" value="{{ old('expect_answer', $c->expect_answer) }}" placeholder="10.1.0.20"><x-field-error name="expect_answer"/></div>
    </div>

    <div class="section-title">Jadwal dan ambang</div>
    <div class="row r3">
        <div class="field"><label for="interval_s">Diperiksa tiap</label><select class="input" id="interval_s" name="interval_s">
            @foreach(\App\Models\ServiceCheck::INTERVALS as $k => $v)<option value="{{ $k }}" @selected((int) old('interval_s', $c->interval_s) === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="field"><label for="timeout_s">Batas waktu (detik)</label><input class="input @error('timeout_s') is-err @enderror" type="number" id="timeout_s" name="timeout_s" min="1" max="60" value="{{ old('timeout_s', $c->timeout_s) }}" required><x-field-error name="timeout_s"/></div>
        <div class="field"><label for="slow_ms">Lambat bila di atas (ms)</label><input class="input @error('slow_ms') is-err @enderror" type="number" id="slow_ms" name="slow_ms" min="1" value="{{ old('slow_ms', $c->slow_ms) }}" placeholder="kosong = tidak dinilai"><x-field-error name="slow_ms"/></div>
    </div>
    <div class="row">
        <div class="field"><label for="severity">Severity bila gagal</label><select class="input" id="severity" name="severity">
            @foreach(\App\Models\Problem::SEVERITIES as $k => [$lbl])<option value="{{ $k }}" @selected(old('severity', $c->severity) === $k)>{{ $lbl }}</option>@endforeach</select>
            <div class="hint">Lambat dan sertifikat hampir habis selalu Warning.</div></div>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $c->enabled))><span>Aktif</span></label>
    </div>
    <div class="form-foot">
        <a class="btn" href="{{ route('checks.index') }}">Batal</a><button class="btn primary">Simpan</button>
    </div>
</form>
@if($editing)
<form method="post" action="{{ route('checks.destroy', $c) }}" data-confirm="Hapus cek {{ $c->name }}?" style="margin-top:var(--space-lg)">@csrf @method('DELETE')<button class="btn danger"><x-icon name="trash"/>Hapus cek</button></form>
@endif
@endsection
