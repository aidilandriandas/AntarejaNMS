@extends('layouts.app')
@php($editing = $t->exists)
@php($c = $editCheck ?? new \App\Models\TemplateCheck(['type' => 'http', 'interval_s' => 60, 'timeout_s' => 10, 'severity' => 'high', 'verify_tls' => true, 'enabled' => true, 'expect_status' => '200-399']))
@section('title', $editing ? 'Templat ' . $t->name : 'Templat baru')
@section('content')
<div class="crumbs"><a href="{{ route('templates.index') }}">Templat</a> / {{ $editing ? $t->name : 'Baru' }}</div>
<div class="page-head"><div><h1>{{ $editing ? $t->name : 'Templat baru' }}</h1>
    <p>Makro dipakai di cek layanan dan URL exporter: <code>{HOST}</code>, <code>{NAME}</code>, <code>{SITE}</code> dari perangkat, dan <code>{$NAMA}</code> yang nilainya diisi di sini atau ditimpa per perangkat.</p></div>
    @if($editing)<form method="post" action="{{ route('templates.destroy', $t) }}" data-confirm="Hapus templat {{ $t->name }}? Cek layanan dan exporter dari templat dilepas dari {{ $devices->count() }} perangkat.">@csrf @method('DELETE')<button class="btn"><x-icon name="trash"/>Hapus templat</button></form>@endif</div>

<div class="grid g-main">
<div class="stack">
<section class="card"><div class="hd"><h2>Pengaturan</h2></div><div class="bd">
<form method="post" action="{{ $editing ? route('templates.update', $t) : route('templates.store') }}" class="form">@csrf @if($editing) @method('PUT') @endif
    <div class="row">
        <div class="field"><label for="name">Nama</label><input class="input @error('name') is-err @enderror" id="name" name="name" maxlength="80" required value="{{ old('name', $t->name) }}" placeholder="Server Linux FMS"><x-field-error name="name"/></div>
        <div class="field"><label for="description">Keterangan <span class="muted">(opsional)</span></label><input class="input" id="description" name="description" maxlength="500" value="{{ old('description', $t->description) }}"></div>
    </div>
    <div class="field"><label for="macros">Makro</label><textarea class="input mono @error('macros') is-err @enderror" id="macros" name="macros" rows="4" placeholder="PORT_WEB=8080&#10;PATH_LOGIN=/dispatch/login">{{ old('macros', \App\Services\TemplateSync::macroText($t->macros)) }}</textarea>
        <div class="hint">Satu per baris, NAMA=nilai. Dipakai sebagai <code>{$NAMA}</code>. Perangkat bisa menimpa nilainya.</div><x-field-error name="macros"/></div>
    <div class="row">
        <div class="field"><label for="exporter_url">URL exporter <span class="muted">(opsional)</span></label><input class="input mono @error('exporter_url') is-err @enderror" id="exporter_url" name="exporter_url" maxlength="255" value="{{ old('exporter_url', $t->exporter_url) }}" placeholder="http://{HOST}:9100/metrics">
            <div class="hint">Dipasang ke perangkat yang belum punya URL exporter sendiri.</div><x-field-error name="exporter_url"/></div>
        <div class="field"><label for="exporter_services">Layanan dipantau</label><input class="input mono @error('exporter_services') is-err @enderror" id="exporter_services" name="exporter_services" maxlength="500" value="{{ old('exporter_services', $t->exporter_services) }}" placeholder="nginx, postgresql"><x-field-error name="exporter_services"/></div>
    </div>
    <details class="thr" open><summary class="section-title" style="cursor:pointer;list-style:none">Ambang</summary>
        <p class="hint muted" style="margin:10px 0">Kosongkan untuk memakai ambang bawaan. Ambang yang diisi langsung di perangkat menang atas ambang templat.</p>
        @include('partials.thresholds', ['values' => $t->thresholds ?? [], 'inherited' => []])
    </details>
    <div class="form-foot"><button class="btn primary">{{ $editing ? 'Simpan dan terapkan' : 'Buat templat' }}</button></div>
</form>
</div></section>

@if($editing)
<section class="card"><div class="hd"><h2>Cek layanan templat</h2><span class="sub">dibuat di setiap perangkat bertemplat ini</span></div><div class="bd flush">
    @if($t->checks->isEmpty())<div class="empty">Belum ada cek.</div>@else
    <table class="t"><tbody>
    @foreach($t->checks as $k)
        <tr class="{{ $k->enabled ? '' : 'is-off' }}"><td class="name">{{ $k->name }}<small>{{ \App\Models\ServiceCheck::TYPES[$k->type] }}, {{ \App\Models\Problem::SEVERITIES[$k->severity][0] }}</small></td>
            <td class="mono">{{ $k->url ?? $k->query }}{{ $k->port ? ($k->type === 'tcp' ? 'port ' : ':') . $k->port : '' }}</td>
            <td class="right"><div class="row-actions nowrap"><a class="btn sm" href="{{ route('templates.edit', [$t, 'cek' => $k->id]) }}#cek"><x-icon name="edit"/>Ubah</a>
                <form method="post" action="{{ route('templates.checks.destroy', [$t, $k]) }}" data-confirm="Hapus cek {{ $k->name }} dari templat dan semua perangkatnya?">@csrf @method('DELETE')<button class="btn sm" aria-label="Hapus {{ $k->name }}"><x-icon name="trash"/></button></form></div></td></tr>
    @endforeach
    </tbody></table>@endif
</div></section>

<section class="card" id="cek"><div class="hd"><h2>{{ $editCheck ? 'Ubah cek ' . $editCheck->name : 'Tambah cek' }}</h2>@if($editCheck)<a class="sub" href="{{ route('templates.edit', $t) }}">Batal ubah</a>@endif</div><div class="bd">
<form method="post" action="{{ $editCheck ? route('templates.checks.update', [$t, $editCheck]) : route('templates.checks.store', $t) }}" class="form">@csrf @if($editCheck) @method('PUT') @endif
    <div class="row">
        <div class="field"><label for="c-name">Nama</label><input class="input @error('name') is-err @enderror" id="c-name" name="name" maxlength="128" required value="{{ old('name', $c->name) }}" placeholder="Login FMS {NAME}"><x-field-error name="name"/></div>
        <div class="field"><label for="type">Jenis</label><select class="input" id="type" name="type">@foreach(\App\Models\ServiceCheck::TYPES as $k => $v)<option value="{{ $k }}" @selected(old('type', $c->type) === $k)>{{ $v }}</option>@endforeach</select></div>
    </div>
    <div class="stack-sm" data-show-if="type=http">
        <div class="field"><label for="url">Alamat</label><input class="input mono @error('url') is-err @enderror" id="url" name="url" maxlength="500" value="{{ old('url', $c->url) }}" placeholder="https://{HOST}:{$PORT_WEB}/login"><x-field-error name="url"/></div>
        <div class="row">
            <div class="field"><label for="expect_status">Kode HTTP</label><input class="input mono @error('expect_status') is-err @enderror" id="expect_status" name="expect_status" value="{{ old('expect_status', $c->expect_status) }}"><x-field-error name="expect_status"/></div>
            <div class="field"><label for="expect_text">Teks wajib ada</label><input class="input" id="expect_text" name="expect_text" maxlength="200" value="{{ old('expect_text', $c->expect_text) }}"></div>
        </div>
        <label class="check"><input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $c->verify_tls))><span>Periksa sertifikat HTTPS</span></label>
    </div>
    <div class="field" data-show-if="type=tcp"><label for="port">Port</label><input class="input mono @error('port') is-err @enderror" id="port" name="port" maxlength="64" value="{{ old('port', $c->port) }}" placeholder="5432 atau {$PORT_DB}"><x-field-error name="port"/></div>
    <div class="field" data-show-if="type=dns"><label for="query">Nama yang ditanyakan</label><input class="input mono @error('query') is-err @enderror" id="query" name="query" maxlength="255" value="{{ old('query', $c->query) }}"><x-field-error name="query"/></div>
    <div class="row r3">
        <div class="field"><label for="interval_s">Tiap</label><select class="input" id="interval_s" name="interval_s">@foreach(\App\Models\ServiceCheck::INTERVALS as $k => $v)<option value="{{ $k }}" @selected((int) old('interval_s', $c->interval_s) === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="field"><label for="timeout_s">Batas waktu (detik)</label><input class="input" type="number" id="timeout_s" name="timeout_s" min="1" max="60" value="{{ old('timeout_s', $c->timeout_s) }}"></div>
        <div class="field"><label for="severity">Severity</label><select class="input" id="severity" name="severity">@foreach(\App\Models\Problem::SEVERITIES as $k => [$lbl])<option value="{{ $k }}" @selected(old('severity', $c->severity) === $k)>{{ $lbl }}</option>@endforeach</select></div>
    </div>
    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $c->enabled))><span>Aktif</span></label>
    <div class="form-foot"><button class="btn primary">{{ $editCheck ? 'Simpan cek' : 'Tambah cek' }}</button></div>
</form>
</div></section>
@endif
</div>

@if($editing)
<div class="stack">
<section class="card"><div class="hd"><h2>Perangkat</h2><span class="sub">{{ $devices->count() }}</span></div><div class="bd">
    @if($devices->isEmpty())<p class="muted" style="margin:0">Belum ada. Pasang lewat <a href="{{ route('devices.index') }}">Perangkat</a>, centang beberapa, lalu Ubah massal, Pasang templat; atau di form ubah perangkat.</p>@else
    <ul class="impact-list">@foreach($devices as $d)<li><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a> <span class="muted">{{ $d->site?->code }}</span></li>@endforeach</ul>@endif
</div></section>
<section class="card"><div class="hd"><h2>Trigger</h2></div><div class="bd">
    @if($triggers->isEmpty())<p class="muted" style="margin:0">Belum ada trigger untuk templat ini. Buat di <a href="{{ route('triggers.create') }}">Trigger</a> dengan cakupan Templat.</p>@else
    <ul class="impact-list">@foreach($triggers as $tr)<li><a href="{{ route('triggers.edit', $tr) }}">{{ $tr->name }}</a></li>@endforeach</ul>@endif
</div></section>
</div>
@endif
</div>
@endsection
