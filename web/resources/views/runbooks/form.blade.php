@extends('layouts.app')
@php($editing = $r->exists)
@section('title', $editing ? 'Ubah runbook' : 'Tambah runbook')
@section('content')
<div class="crumbs"><a href="{{ route('runbooks.index') }}">Runbook</a> / {{ $editing ? $r->title : 'Tambah' }}</div>
<div class="page-head"><div><h1>{{ $editing ? 'Ubah runbook' : 'Tambah runbook' }}</h1></div></div>
<form method="post" action="{{ $editing ? route('runbooks.update', $r) : route('runbooks.store') }}" class="form" style="max-width:760px">@csrf @if($editing) @method('PUT') @endif
    <div class="field"><label for="title">Judul</label><input class="input @error('title') is-err @enderror" id="title" name="title" value="{{ old('title', $r->title) }}" maxlength="160" required placeholder="mis. Radio PTP down"><x-field-error name="title"/></div>
    <div class="field"><label for="body">Langkah penanganan</label>
        <textarea class="input @error('body') is-err @enderror" id="body" name="body" rows="12" maxlength="6000" required placeholder="1. Cek di Topologi apakah sisi lawan juga down.&#10;2. Telepon pengawas lapangan, minta cek lampu PoE di panel menara.&#10;3. Bila PoE mati: cek UPS menara di halaman Sensor.&#10;4. Masih down setelah 30 menit: hubungi vendor radio (PT X, 0812 ...).">{{ old('body', $r->body) }}</textarea>
        <div class="hint">Satu langkah per baris. Lima baris pertama ikut di pesan Telegram dan email, jadi taruh yang paling penting di atas. Jangan tulis password di sini.</div><x-field-error name="body"/></div>

    <div class="section-title">Berlaku untuk</div>
    <p class="hint muted" style="margin-top:0">Kosongkan yang tidak dibatasi. Yang diisi harus cocok semua.</p>
    <div class="row">
        <div class="field"><label for="problem_kind">Jenis problem</label><select class="input" id="problem_kind" name="problem_kind"><option value="">Semua jenis</option>
            @foreach(\App\Models\Problem::KINDS as $k => $v)<option value="{{ $k }}" @selected(old('problem_kind', $r->problem_kind) === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="field"><label for="category">Kategori perangkat</label><select class="input" id="category" name="category"><option value="">Semua kategori</option>
            @foreach(\App\Models\Device::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(old('category', $r->category) === $k)>{{ $v }}</option>@endforeach</select></div>
    </div>
    <div class="row">
        <div class="field"><label for="site_id">Site</label><select class="input" id="site_id" name="site_id"><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(old('site_id', $r->site_id) == $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach</select></div>
        <div class="field"><label for="device_id">Perangkat tertentu</label><select class="input" id="device_id" name="device_id"><option value="">Semua perangkat</option>
            @foreach($devices as $d)<option value="{{ $d->id }}" @selected(old('device_id', $r->device_id) == $d->id)>{{ $d->name }} ({{ $d->host }})</option>@endforeach</select></div>
    </div>
    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $r->enabled))><span>Aktif</span></label>
    <div class="form-foot"><a class="btn" href="{{ $editing ? route('runbooks.show', $r) : route('runbooks.index') }}">Batal</a><button class="btn primary">Simpan</button></div>
</form>
@if($editing)
<form method="post" action="{{ route('runbooks.destroy', $r) }}" data-confirm="Hapus runbook {{ $r->title }}?" style="margin-top:var(--space-lg)">@csrf @method('DELETE')<button class="btn danger"><x-icon name="trash"/>Hapus runbook</button></form>
@endif
@endsection
