@extends('layouts.app')
@section('title', 'Modbus ' . $device->name)
@php($p = $edit ?? new \App\Models\ModbusPoint(['class' => 'voltage', 'fn' => 4, 'dtype' => 'u16', 'scale' => 1, 'offset_value' => 0, 'enabled' => true]))
@php($n = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.'))
@php($show = fn ($v) => str_replace('.', ',', $n(round((float) $v, 3))))
@section('content')
<div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / <a href="{{ route('devices.show', $device) }}">{{ $device->name }}</a> / Modbus</div>
<div class="page-head"><div><h1>Modbus {{ $device->name }}</h1>
    <p>Membaca register solar controller, baterai, genset, atau sensor daya lewat Modbus TCP tiap menit. <b>Hanya membaca</b> (fungsi 03 dan 04); ANTAREJA tidak bisa menulis ke perangkat. Hasilnya menjadi sensor perangkat: tampil di halaman Sensor, punya grafik, dan bisa menjadi problem.</p></div></div>

<div class="grid g-main">
<div class="stack">
<section class="card"><div class="hd"><h2>Titik baca</h2><span class="sub">{{ $points->count() }} titik</span></div><div class="bd flush">
    @if($points->isEmpty())<div class="empty">Belum ada titik. Isi alamat register dari manual perangkat (tabel Modbus / register map), atau salin dari perangkat lain.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Titik</th><th>Register</th><th class="num">Nilai terakhir</th><th>Ambang</th><th></th></tr></thead>
        <tbody>
        @foreach($points as $pt)
            @php($s = $values[$pt->sensorKey()] ?? null)
            <tr class="{{ $pt->enabled ? '' : 'is-off' }}">
                <td class="name">{{ $pt->label }}<small>{{ \App\Models\ModbusPoint::CLASSES[$pt->class] }}{{ $pt->unit ? ', ' . $pt->unit : '' }}</small></td>
                <td class="mono">{{ $pt->fn === 4 ? 'input' : 'holding' }} {{ $pt->address }} <span class="muted">{{ $pt->dtype }}{{ $pt->word_swap ? ' swap' : '' }} x{{ $n($pt->scale) }}{{ $pt->offset_value ? ' +' . $n($pt->offset_value) : '' }}</span></td>
                <td class="num">@if($s && $s->value !== null)<span class="{{ ['warn' => 'txt-warn', 'crit' => 'txt-crit'][$s->status] ?? '' }}">{{ $show($s->value) }} {{ $pt->unit }}</span><div class="muted" style="font-size:12px">{{ \Illuminate\Support\Carbon::parse($s->updated_at)->diffForHumans() }}</div>@else<span class="muted">belum ada</span>@endif</td>
                <td class="muted" style="font-size:var(--text-xs)">{{ $pt->thresholdLabel() }}</td>
                <td class="right"><div class="row-actions nowrap"><a class="btn sm" href="{{ route('modbus.edit', [$device, 'ubah' => $pt->id]) }}#titik"><x-icon name="edit"/>Ubah</a>
                    <form method="post" action="{{ route('modbus.points.destroy', [$device, $pt]) }}" data-confirm="Hapus titik {{ $pt->label }}? Sensornya ikut dihapus.">@csrf @method('DELETE')<button class="btn sm" aria-label="Hapus {{ $pt->label }}"><x-icon name="trash"/></button></form></div></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>@endif
</div></section>

@if($test)
<section class="card"><div class="hd"><h2>Hasil uji baca</h2></div><div class="bd flush">
    <table class="t"><thead><tr><th>Titik</th><th class="num">Nilai</th><th>Register mentah</th></tr></thead><tbody>
    @foreach($test as $r)
        <tr><td>{{ $r['label'] }}</td>
            <td class="num">@if($r['value'] !== null)<span class="{{ ['warn' => 'txt-warn', 'crit' => 'txt-crit'][$r['status']] ?? '' }}">{{ $show($r['value']) }} {{ $r['unit'] }}</span>@else<span class="txt-crit">{{ $r['error'] }}</span>@endif</td>
            <td class="mono muted">{{ isset($r['raw']) ? implode(' ', array_map(fn ($x) => $x . ' (0x' . strtoupper(dechex($x)) . ')', $r['raw'])) : '' }}</td></tr>
    @endforeach
    </tbody></table>
    <p class="hint muted" style="margin:0;padding:var(--space-sm) var(--space-md)">Nilai janggal? Coba ubah tipe data, skala, atau centang tukar urutan word untuk 32-bit. Alamat di manual kadang dimulai dari 1 (mis. 40001 atau 30001): kurangi 1 dan buang awalan 3xxxx/4xxxx.</p>
</div></section>
@endif

<section class="card" id="titik"><div class="hd"><h2>{{ $edit ? 'Ubah titik ' . $edit->label : 'Tambah titik' }}</h2>@if($edit)<a class="sub" href="{{ route('modbus.edit', $device) }}">Batal ubah</a>@endif</div><div class="bd">
    <form method="post" action="{{ $edit ? route('modbus.points.update', [$device, $edit]) : route('modbus.points.store', $device) }}" class="form">@csrf @if($edit) @method('PUT') @endif
        <div class="row r3">
            <div class="field"><label for="label">Nama</label><input class="input @error('label') is-err @enderror" id="label" name="label" value="{{ old('label', $p->label) }}" maxlength="80" required placeholder="Tegangan baterai"><x-field-error name="label"/></div>
            <div class="field"><label for="class">Jenis</label><select class="input" id="class" name="class">@foreach(\App\Models\ModbusPoint::CLASSES as $k => $v)<option value="{{ $k }}" @selected(old('class', $p->class) === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="field"><label for="unit">Satuan</label><input class="input" id="unit" name="unit" value="{{ old('unit', $p->unit) }}" maxlength="12" placeholder="V, A, W, %, °C"></div>
        </div>
        <div class="row r3">
            <div class="field"><label for="fn">Jenis register</label><select class="input" id="fn" name="fn"><option value="4" @selected((int) old('fn', $p->fn) === 4)>Input register (fungsi 04, 3xxxx)</option><option value="3" @selected((int) old('fn', $p->fn) === 3)>Holding register (fungsi 03, 4xxxx)</option></select></div>
            <div class="field"><label for="address">Alamat (mulai 0)</label><input class="input mono @error('address') is-err @enderror" type="number" id="address" name="address" min="0" max="65535" value="{{ old('address', $p->address) }}" required><x-field-error name="address"/></div>
            <div class="field"><label for="dtype">Tipe data</label><select class="input" id="dtype" name="dtype">@foreach(\App\Models\ModbusPoint::DTYPES as $k => $v)<option value="{{ $k }}" @selected(old('dtype', $p->dtype) === $k)>{{ $v }}</option>@endforeach</select></div>
        </div>
        <div class="row r3">
            <div class="field"><label for="scale">Skala (dikali)</label><input class="input mono @error('scale') is-err @enderror" id="scale" name="scale" inputmode="decimal" value="{{ old('scale', $n($p->scale)) }}" required placeholder="0.01"><div class="hint">Mis. 0.01 bila manual menulis nilai x100.</div><x-field-error name="scale"/></div>
            <div class="field"><label for="offset_value">Ditambah</label><input class="input mono" id="offset_value" name="offset_value" inputmode="decimal" value="{{ old('offset_value', $n($p->offset_value)) }}" placeholder="0"></div>
            <label class="check"><input type="checkbox" name="word_swap" value="1" @checked(old('word_swap', $p->word_swap))><span>Tukar urutan word<small>32-bit: word rendah di register pertama.</small></span></label>
        </div>
        <div class="row">
            <div class="field"><label>Warning bila di bawah / di atas</label><div class="row">
                <input class="input mono @error('warn_low') is-err @enderror" name="warn_low" inputmode="decimal" value="{{ old('warn_low', $n($p->warn_low)) }}" placeholder="kosong" aria-label="Warning bawah">
                <input class="input mono @error('warn_high') is-err @enderror" name="warn_high" inputmode="decimal" value="{{ old('warn_high', $n($p->warn_high)) }}" placeholder="kosong" aria-label="Warning atas"></div><x-field-error name="warn_low"/><x-field-error name="warn_high"/></div>
            <div class="field"><label>Kritis bila di bawah / di atas</label><div class="row">
                <input class="input mono @error('crit_low') is-err @enderror" name="crit_low" inputmode="decimal" value="{{ old('crit_low', $n($p->crit_low)) }}" placeholder="kosong" aria-label="Kritis bawah">
                <input class="input mono @error('crit_high') is-err @enderror" name="crit_high" inputmode="decimal" value="{{ old('crit_high', $n($p->crit_high)) }}" placeholder="kosong" aria-label="Kritis atas"></div><x-field-error name="crit_low"/><x-field-error name="crit_high"/></div>
        </div>
        <p class="hint muted" style="margin:0">Contoh baterai 12 V: warning di bawah 12.2, kritis di bawah 11.8. Warning menjadi problem Warning, kritis menjadi problem High.</p>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $p->enabled))><span>Aktif</span></label>
        <div class="form-foot"><button class="btn primary">{{ $edit ? 'Simpan titik' : 'Tambah titik' }}</button></div>
    </form>
</div></section>
</div>

<div class="stack">
<section class="card"><div class="hd"><h2>Koneksi</h2>@if($device->modbus_port)<span class="badge {{ $device->modbus_error ? 'warn' : 'ok' }}">{{ $device->modbus_error ? 'ada galat' : 'aktif' }}</span>@else<span class="badge">mati</span>@endif</div><div class="bd">
    <form method="post" action="{{ route('modbus.settings', $device) }}" class="form">@csrf @method('PUT')
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', (bool) $device->modbus_port))><span>Baca Modbus TCP dari {{ $device->host }}</span></label>
        <div class="row">
            <div class="field"><label for="mb-port">Port</label><input class="input @error('modbus_port') is-err @enderror" type="number" id="mb-port" name="modbus_port" min="1" max="65535" value="{{ old('modbus_port', $device->modbus_port ?? 502) }}"><x-field-error name="modbus_port"/></div>
            <div class="field"><label for="mb-unit">Unit ID</label><input class="input" type="number" id="mb-unit" name="modbus_unit" min="0" max="255" value="{{ old('modbus_unit', $device->modbus_unit ?? 1) }}"><div class="hint">Lewat gateway RS485 ke TCP: alamat slave perangkat.</div></div>
        </div>
        <div class="form-foot"><button class="btn primary">Simpan</button></div>
    </form>
    @if($device->modbus_at)<p class="hint {{ $device->modbus_error ? 'txt-warn' : 'muted' }}" style="margin:var(--space-sm) 0 0">Dibaca {{ $device->modbus_at->diffForHumans() }}{{ $device->modbus_error ? ': ' . $device->modbus_error : ', semua titik terbaca' }}</p>@endif
    @if($device->modbus_port && $points->isNotEmpty())
    <form method="post" action="{{ route('modbus.test', $device) }}" style="margin-top:var(--space-sm)">@csrf<button class="btn"><x-icon name="check"/>Uji baca sekarang</button></form>
    @endif
</div></section>
@if($sources->isNotEmpty())
<section class="card"><div class="hd"><h2>Salin titik</h2></div><div class="bd">
    <form method="post" action="{{ route('modbus.copy', $device) }}" class="form" data-confirm="Salin semua titik dari perangkat terpilih ke {{ $device->name }}?">@csrf
        <div class="field"><label for="source">Dari perangkat</label><select class="input" id="source" name="source">@foreach($sources as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
            <div class="hint">Untuk menara dengan solar controller yang sama.</div></div>
        <div class="form-foot"><button class="btn">Salin</button></div>
    </form>
</div></section>
@endif
</div>
</div>
@endsection
