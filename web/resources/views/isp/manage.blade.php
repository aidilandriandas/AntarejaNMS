@extends('layouts.app')
@section('title', 'Atur link ISP')
@php($l = $edit ?? new \App\Models\IspLink(['target_pct' => 98, 'warn_pct' => 97, 'loss_down_pct' => 10, 'latency_target_ms' => 100, 'jitter_target_ms' => 5, 'loss_target_pct' => 1, 'exclude_maintenance' => true]))
@php($n = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.'))
@section('content')
<div class="crumbs"><a href="{{ route('isp.index') }}">SLA link ISP</a> / Atur link</div>
<div class="page-head"><div><h1>Atur link ISP</h1>
    <p>Satu link = port di router yang mengarah ke ISP. Ketersediaan dihitung dari status port itu (dibaca lewat SNMP), trafiknya dibandingkan dengan bandwidth kontrak. Pilih juga perangkat di ujung ISP (mis. gateway ISP) bila ingin latensi, jitter, dan packet loss. Target diisi sesuai kontrak.</p></div></div>
<div class="grid g-main">
<section class="card"><div class="hd"><h2>Link</h2><span class="sub">{{ $links->count() }}</span></div><div class="bd flush">
    @if($links->isEmpty())<div class="empty">Belum ada link.</div>@else
    <table class="t"><tbody>
    @foreach($links as $x)
        <tr class="{{ $edit?->id === $x->id ? 'is-sel' : '' }}"><td class="name">{{ $x->name }}<small>{{ $x->provider }}{{ $x->provider ? ', ' : '' }}{{ $x->sourceLabel() }}{{ $x->if_index === null ? ' (ping, belum pilih port)' : '' }}</small></td>
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
    @php($curPorts = old('device_id', $l->device_id) ? \Illuminate\Support\Facades\DB::table('interfaces')->where('device_id', old('device_id', $l->device_id))->orderBy('if_index')->get(['if_index', 'name', 'alias', 'oper_up']) : collect())
    <div class="row">
        <div class="field"><label for="device_id">Router</label><select class="input @error('device_id') is-err @enderror" id="device_id" name="device_id" required data-port-src="if_index" data-ports-url="{{ url('topologi/port') }}"><option value="">Pilih router</option>
            @foreach($devices as $d)<option value="{{ $d->id }}" @selected((int) old('device_id', $l->device_id) === $d->id)>{{ $d->name }} ({{ $d->host }}){{ $d->snmp_enabled ? '' : ', tanpa SNMP' }}</option>@endforeach</select><x-field-error name="device_id"/></div>
        <div class="field"><label for="if_index">Port ke ISP</label><select class="input @error('if_index') is-err @enderror" id="if_index" name="if_index">
            <option value="">Tanpa port (hitung dari ping router saja)</option>
            @foreach($curPorts as $p)<option value="{{ $p->if_index }}" @selected(old('if_index', $l->if_index) !== null && (int) old('if_index', $l->if_index) === (int) $p->if_index)>{{ $p->name }}{{ $p->alias ? ' (' . $p->alias . ')' : '' }}{{ $p->oper_up ? '' : ', down' }}</option>@endforeach</select>
            <div class="hint">Daftar port muncul setelah router dipilih. Router harus ber-SNMP.</div><x-field-error name="if_index"/></div>
    </div>
    <div class="row">
        <div class="field"><label for="ping_device_id">Ping ke ujung ISP <span class="muted">(opsional)</span></label><select class="input" id="ping_device_id" name="ping_device_id"><option value="">Tidak ada</option>
            @foreach($devices->where('ping_enabled', true) as $d)<option value="{{ $d->id }}" @selected((int) old('ping_device_id', $l->ping_device_id) === $d->id)>{{ $d->name }} ({{ $d->host }})</option>@endforeach</select>
            <div class="hint">Mis. gateway ISP atau IP publik di sisi ISP, didaftarkan dulu di Perangkat. Sumber latensi, jitter, dan loss; ping gagal juga dihitung gangguan.</div></div>
        <div class="field"><label for="bandwidth_mbps">Bandwidth kontrak (Mbps) <span class="muted">(opsional)</span></label><input class="input @error('bandwidth_mbps') is-err @enderror" type="number" step="0.01" min="0" id="bandwidth_mbps" name="bandwidth_mbps" value="{{ old('bandwidth_mbps', $n($l->bandwidth_mbps)) }}" placeholder="mis. 100">
            <div class="hint">Untuk persentase pemakaian. Kosong = kecepatan port.</div><x-field-error name="bandwidth_mbps"/></div>
    </div>
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
