@extends('layouts.app')
@section('title', 'Atur link ISP')
@php($l = $edit ?? new \App\Models\IspLink(['target_pct' => 98, 'warn_pct' => 97, 'loss_down_pct' => 10, 'latency_target_ms' => 100, 'jitter_target_ms' => 5, 'loss_target_pct' => 1, 'exclude_maintenance' => true]))
@php($n = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.'))
@section('content')
<div class="crumbs"><a href="{{ route('isp.index') }}">SLA link ISP</a> / Atur link</div>
<div class="page-head"><div><h1>Atur link ISP</h1>
    <p>Satu link = satu perangkat yang di-ping ANTAREJA untuk link itu, mis. gateway ISP, router ujung Metro-E, atau IP publik di sisi ISP. Target diisi sesuai kontrak.</p></div></div>
<div class="grid g-main">
<section class="card"><div class="hd"><h2>Link</h2><span class="sub">{{ $links->count() }}</span></div><div class="bd flush">
    @if($links->isEmpty())<div class="empty">Belum ada link.</div>@else
    <table class="t"><tbody>
    @foreach($links as $x)
        <tr class="{{ $edit?->id === $x->id ? 'is-sel' : '' }}"><td class="name">{{ $x->name }}<small>{{ $x->provider }}{{ $x->provider ? ', ' : '' }}{{ $x->device?->name }} ({{ $x->device?->host }})</small></td>
            <td class="muted">target {{ $n($x->target_pct) }}%, loss gangguan {{ $n($x->loss_down_pct) }}%</td>
            <td class="right"><div class="row-actions nowrap"><a class="btn sm" href="{{ route('isp.manage', ['ubah' => $x->id]) }}#link"><x-icon name="edit"/>Ubah</a>
                <form method="post" action="{{ route('isp.destroy', $x) }}" data-confirm="Hapus link {{ $x->name }} dari laporan?">@csrf @method('DELETE')<button class="btn sm" aria-label="Hapus {{ $x->name }}"><x-icon name="trash"/></button></form></div></td></tr>
    @endforeach
    </tbody></table>@endif
</div></section>

<section class="card" id="link"><div class="hd"><h2>{{ $edit ? 'Ubah ' . $edit->name : 'Tambah link' }}</h2>@if($edit)<a class="sub" href="{{ route('isp.manage') }}">Batal ubah</a>@endif</div><div class="bd">
<form method="post" action="{{ $edit ? route('isp.update', $edit) : route('isp.store') }}" class="form">@csrf @if($edit) @method('PUT') @endif
    <div class="row">
        <div class="field"><label for="name">Nama link</label><input class="input @error('name') is-err @enderror" id="name" name="name" maxlength="80" required value="{{ old('name', $l->name) }}" placeholder="Metro E PLJ"><x-field-error name="name"/></div>
        <div class="field"><label for="provider">Penyedia <span class="muted">(opsional)</span></label><input class="input" id="provider" name="provider" maxlength="80" value="{{ old('provider', $l->provider) }}" placeholder="Lintasmaya"></div>
    </div>
    <div class="field"><label for="device_id">Perangkat yang di-ping</label><select class="input @error('device_id') is-err @enderror" id="device_id" name="device_id" required><option value="">Pilih perangkat</option>
        @foreach($devices as $d)<option value="{{ $d->id }}" @selected((int) old('device_id', $l->device_id) === $d->id)>{{ $d->name }} ({{ $d->host }})</option>@endforeach</select>
        <div class="hint">Hanya perangkat dengan ping aktif. Tambahkan dulu di Perangkat bila belum ada.</div><x-field-error name="device_id"/></div>
    <div class="row r3">
        <div class="field"><label for="target_pct">Target SLA (%)</label><input class="input @error('target_pct') is-err @enderror" type="number" step="0.001" id="target_pct" name="target_pct" value="{{ old('target_pct', $n($l->target_pct)) }}" required><x-field-error name="target_pct"/></div>
        <div class="field"><label for="warn_pct">Tidak memenuhi di bawah (%)</label><input class="input @error('warn_pct') is-err @enderror" type="number" step="0.001" id="warn_pct" name="warn_pct" value="{{ old('warn_pct', $n($l->warn_pct)) }}" required><x-field-error name="warn_pct"/></div>
        <div class="field"><label for="loss_down_pct">Loss dihitung gangguan (%)</label><input class="input @error('loss_down_pct') is-err @enderror" type="number" step="0.1" id="loss_down_pct" name="loss_down_pct" value="{{ old('loss_down_pct', $n($l->loss_down_pct)) }}" required><x-field-error name="loss_down_pct"/></div>
    </div>
    <div class="row r3">
        <div class="field"><label for="latency_target_ms">Target latensi (ms)</label><input class="input" type="number" step="0.1" id="latency_target_ms" name="latency_target_ms" value="{{ old('latency_target_ms', $n($l->latency_target_ms)) }}" required></div>
        <div class="field"><label for="jitter_target_ms">Target jitter (ms)</label><input class="input" type="number" step="0.1" id="jitter_target_ms" name="jitter_target_ms" value="{{ old('jitter_target_ms', $n($l->jitter_target_ms)) }}" required></div>
        <div class="field"><label for="loss_target_pct">Target packet loss (%)</label><input class="input" type="number" step="0.1" id="loss_target_pct" name="loss_target_pct" value="{{ old('loss_target_pct', $n($l->loss_target_pct)) }}" required></div>
    </div>
    <label class="check"><input type="checkbox" name="exclude_maintenance" value="1" @checked(old('exclude_maintenance', $l->exclude_maintenance))><span>Waktu maintenance tidak dihitung<small>Maintenance yang mencakup perangkat ini (semua, site-nya, atau perangkat ini).</small></span></label>
    <div class="field"><label for="notes">Catatan <span class="muted">(opsional)</span></label><input class="input" id="notes" name="notes" maxlength="500" value="{{ old('notes', $l->notes) }}" placeholder="No. kontrak, kontak NOC ISP"></div>
    <div class="form-foot">@if($edit)<a class="btn" href="{{ route('isp.manage') }}">Batal</a>@endif<button class="btn primary">{{ $edit ? 'Simpan' : 'Tambah link' }}</button></div>
</form>
</div></section>
</div>
@endsection
