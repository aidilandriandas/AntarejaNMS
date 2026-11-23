@extends('layouts.app')
@php($editing = $t->exists)
@section('title', $editing ? 'Ubah trigger' : 'Tambah trigger')
@php($M = \App\Models\Trigger::METRICS)
@php($selDays = collect(old('days', collect(range(0, 6))->filter(fn ($i) => $t->days & (1 << $i))->values()->all()))->map(fn ($v) => (int) $v)->all())
@php($selDev = collect(old('devices', $t->devices?->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v)->all())
@php($hm = fn ($m) => $m === null ? '' : sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60))
@section('content')
<div class="crumbs"><a href="{{ route('triggers.index') }}">Trigger</a> / {{ $editing ? $t->name : 'Tambah' }}</div>
<div class="page-head"><div><h1>{{ $editing ? 'Ubah trigger' : 'Tambah trigger' }}</h1>
    <p>Hanya memakai data yang sudah dikumpulkan ANTAREJA: ping, sensor SNMP, dan trafik port. Tidak ada yang dikirim ke perangkat.</p></div></div>

<form method="post" action="{{ $editing ? route('triggers.update', $t) : route('triggers.store') }}" class="form" style="max-width:760px">@csrf @if($editing) @method('PUT') @endif
    <div class="row">
        <div class="field"><label for="name">Nama</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name', $t->name) }}" maxlength="120" required placeholder="mis. Uplink Pit A mati di jam kerja"><x-field-error name="name"/></div>
        <div class="field"><label for="severity">Severity</label><select class="input" id="severity" name="severity">
            @foreach(\App\Models\Problem::SEVERITIES as $k => [$lbl])<option value="{{ $k }}" @selected(old('severity', $t->severity) === $k)>{{ $lbl }}</option>@endforeach</select></div>
    </div>

    <div class="section-title">Kondisi</div>
    <div class="row">
        <div class="field"><label for="metric">Metrik</label><select class="input" id="metric" name="metric" data-unit-src>
            @foreach($M as $k => [$lbl, $unit])<option value="{{ $k }}" data-unit="{{ $unit }}" @selected(old('metric', $t->metric) === $k)>{{ $lbl }}</option>@endforeach</select></div>
        <div class="field" data-show-if="metric=sensor"><label for="sensor_class">Kelas sensor</label><select class="input @error('sensor_class') is-err @enderror" id="sensor_class" name="sensor_class">
            <option value="">Pilih kelas sensor</option>
            @foreach(\App\Models\Trigger::SENSOR_CLASSES as $k => $lbl)<option value="{{ $k }}" @selected(old('sensor_class', $t->sensor_class) === $k)>{{ $lbl }}</option>@endforeach</select><x-field-error name="sensor_class"/></div>
    </div>
    <div class="field" data-show-if="metric=sensor,port_in,port_out,port_util,port_errors,port_discards"><label for="match_text">Saring nama <span class="muted">(opsional)</span></label>
        <input class="input" id="match_text" name="match_text" value="{{ old('match_text', $t->match_text) }}" maxlength="120" placeholder="mis. sfp, uplink, PTP-HAULING">
        <div class="hint">Hanya port (nama atau keterangan) atau sensor (nama) yang berisi teks ini. Kosong berarti semua.</div></div>
    <div class="row r3">
        <div class="field"><label for="op">Bila nilainya</label><select class="input" id="op" name="op">
            @foreach(\App\Models\Trigger::OPS as $k => $lbl)<option value="{{ $k }}" @selected(old('op', $t->op) === $k)>{{ $lbl }}</option>@endforeach</select></div>
        <div class="field"><label for="threshold">Ambang <span class="muted" data-unit-out>{{ $M[old('metric', $t->metric)][1] ?? '' }}</span></label>
            <input class="input @error('threshold') is-err @enderror" id="threshold" name="threshold" type="number" step="any" value="{{ old('threshold', $t->threshold) }}" required><x-field-error name="threshold"/></div>
        <div class="field"><label for="duration_min">Bertahan selama (menit)</label>
            <input class="input @error('duration_min') is-err @enderror" id="duration_min" name="duration_min" type="number" min="0" max="1440" value="{{ old('duration_min', $t->duration_min) }}" required><x-field-error name="duration_min"/></div>
    </div>

    <div class="section-title">Berlaku untuk</div>
    <div class="row">
        <div class="field"><label for="scope">Perangkat</label><select class="input" id="scope" name="scope">
            @foreach(['all' => 'Semua perangkat', 'site' => 'Satu site', 'category' => 'Satu kategori', 'devices' => 'Perangkat terpilih'] as $k => $lbl)<option value="{{ $k }}" @selected(old('scope', $t->scope) === $k)>{{ $lbl }}</option>@endforeach</select></div>
        <div class="field" data-show-if="scope=site"><label for="site_id">Site</label><select class="input @error('site_id') is-err @enderror" id="site_id" name="site_id"><option value="">Pilih site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected((int) old('site_id', $t->site_id) === $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach</select><x-field-error name="site_id"/></div>
        <div class="field" data-show-if="scope=category"><label for="category">Kategori</label><select class="input @error('category') is-err @enderror" id="category" name="category"><option value="">Pilih kategori</option>
            @foreach(\App\Models\Device::CATEGORIES as $k => $lbl)<option value="{{ $k }}" @selected(old('category', $t->category) === $k)>{{ $lbl }}</option>@endforeach</select><x-field-error name="category"/></div>
    </div>
    <div class="field" data-show-if="scope=devices"><label for="devices">Perangkat terpilih <span class="muted">(Ctrl/⌘ + klik untuk memilih beberapa)</span></label>
        <input class="input" type="search" placeholder="Saring nama / IP" data-filter-select="devices" aria-label="Saring perangkat">
        <select class="input @error('devices') is-err @enderror" id="devices" name="devices[]" multiple size="8" style="height:auto">
            @foreach($devices as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, $selDev, true))>{{ $d->name }}, {{ $d->host }}{{ $d->site ? ', ' . $d->site->code : '' }}</option>@endforeach
        </select><x-field-error name="devices"/></div>

    <div class="section-title">Jadwal aktif</div>
    <fieldset class="field" style="border:0;padding:0;margin:0"><legend style="font-weight:600;margin-bottom:6px">Hari</legend>
        <div style="display:flex;flex-wrap:wrap;gap:var(--space-xs) var(--space-md)">
        @foreach(\App\Models\Trigger::DAYS as $i => $lbl)<label class="check"><input type="checkbox" name="days[]" value="{{ $i }}" @checked(in_array($i, $selDays, true))><span>{{ $lbl }}</span></label>@endforeach
        </div><x-field-error name="days"/></fieldset>
    <div class="row">
        <div class="field"><label for="start">Jam mulai <span class="muted">(kosong = sepanjang hari)</span></label><input class="input @error('start') is-err @enderror" id="start" name="start" type="time" value="{{ old('start', $hm($t->start_min)) }}"><x-field-error name="start"/></div>
        <div class="field"><label for="end">Jam selesai</label><input class="input @error('end') is-err @enderror" id="end" name="end" type="time" value="{{ old('end', $t->end_min === 1440 ? '00:00' : $hm($t->end_min)) }}"><x-field-error name="end"/>
            <div class="hint">Jam selesai lebih awal dari jam mulai berarti melewati tengah malam (mis. 22:00 sampai 06:00).</div></div>
    </div>
    <div class="field"><label for="note">Catatan <span class="muted">(opsional)</span></label><textarea class="input" id="note" name="note" rows="2" maxlength="500" placeholder="Kenapa trigger ini ada, siapa yang dihubungi">{{ old('note', $t->note) }}</textarea></div>

    <div class="form-foot">
        <a class="btn" href="{{ route('triggers.index') }}">Batal</a>
        <button class="btn primary">{{ $editing ? 'Simpan' : 'Buat trigger' }}</button>
    </div>
</form>
@if($editing)
<form method="post" action="{{ route('triggers.destroy', $t) }}" data-confirm="Hapus trigger {{ $t->name }}? Problem yang dibuatnya ditutup." style="max-width:760px;margin-top:var(--space-md);text-align:right">@csrf @method('DELETE')
    <button class="btn danger"><x-icon name="trash"/>Hapus trigger</button></form>
@endif
@endsection
