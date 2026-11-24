@extends('layouts.app')
@section('title', 'Aturan log')
@php($r = $edit ?? new \App\Models\SyslogRule(['max_severity' => 7, 'min_count' => 1, 'window_s' => 300, 'clear_s' => 900, 'severity' => 'warning', 'enabled' => true]))
@php($SEV = \App\Http\Controllers\SyslogRuleController::SEVERITIES)
@php($P = \App\Models\Problem::SEVERITIES)
@section('content')
<div class="crumbs"><a href="{{ route('logs.index') }}">Log</a> / Aturan</div>
<div class="page-head"><div><h1>Aturan log</h1>
    <p>Pola teks atau regex pada syslog yang menjadi problem bila muncul sejumlah kali dalam jendela waktu, per perangkat. Mis. "login failure" 5 kali dalam 1 menit, atau "link down" sekali. Problem pulih sendiri bila pola tidak muncul lagi selama waktu pulih.</p></div></div>

<div class="grid g-main">
<div class="stack">
<section class="card"><div class="hd"><h2>Aturan</h2><span class="sub">{{ $rules->count() }} aturan</span></div><div class="bd flush">
    @if($rules->isEmpty())<div class="empty">Belum ada aturan. Buat di samping.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Aturan</th><th>Pola</th><th>Syarat</th><th>Sedang terpicu</th><th></th></tr></thead>
        <tbody>
        @foreach($rules as $x)
            <tr class="{{ $x->enabled ? '' : 'is-off' }}">
                <td class="name">{{ $x->name }}<small><span class="badge {{ $P[$x->severity][1] ?? '' }} plain">{{ $P[$x->severity][0] ?? $x->severity }}</span> @if($x->device){{ $x->device->name }}@elseif($x->site)site {{ $x->site->code }}@else semua perangkat @endif</small></td>
                <td class="mono">{{ $x->is_regex ? '/' . $x->pattern . '/' : '"' . $x->pattern . '"' }}<div class="muted" style="font-size:12px">{{ $SEV[$x->max_severity] }} ke atas{{ $x->app ? ', program ' . $x->app : '' }}</div></td>
                <td class="nowrap">{{ $x->min_count }} kali dalam {{ intdiv($x->window_s, 60) }} menit<div class="muted" style="font-size:12px">pulih setelah {{ intdiv($x->clear_s, 60) }} menit sepi</div></td>
                <td class="num">@if($active[$x->id] ?? 0)<span class="txt-crit">{{ $active[$x->id] }} perangkat</span>@else<span class="muted">tidak</span>@endif</td>
                <td class="right"><div class="row-actions nowrap"><a class="btn sm" href="{{ route('logs.rules', ['ubah' => $x->id]) }}#aturan"><x-icon name="edit"/>Ubah</a>
                    <form method="post" action="{{ route('logs.rules.destroy', $x) }}" data-confirm="Hapus aturan {{ $x->name }}?">@csrf @method('DELETE')<button class="btn sm" aria-label="Hapus {{ $x->name }}"><x-icon name="trash"/></button></form></div></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>@endif
</div></section>

@if($test)
<section class="card"><div class="hd"><h2>Hasil uji pola</h2><span class="sub">{{ $test['count'] }} cocok dari {{ $test['scanned'] }} log 24 jam terakhir</span></div><div class="bd flush">
    @if(empty($test['samples']))<div class="empty">Tidak ada log yang cocok.</div>@else
    <table class="t"><tbody>
    @foreach($test['samples'] as $l)
        <tr><td class="nowrap muted">{{ \Illuminate\Support\Carbon::parse($l->received_at)->format('d/m H:i:s') }}</td><td>{{ $l->name ?? 'tidak terdaftar' }}</td><td class="mono" style="word-break:break-word">{{ \Illuminate\Support\Str::limit($l->message, 200) }}</td></tr>
    @endforeach
    </tbody></table>@endif
</div></section>
@endif
</div>

<div class="stack">
<section class="card" id="aturan"><div class="hd"><h2>{{ $edit ? 'Ubah ' . $edit->name : 'Aturan baru' }}</h2>@if($edit)<a class="sub" href="{{ route('logs.rules') }}">Batal ubah</a>@endif</div><div class="bd">
    <form method="post" action="{{ $edit ? route('logs.rules.update', $edit) : route('logs.rules.store') }}" class="form">@csrf @if($edit) @method('PUT') <input type="hidden" name="id" value="{{ $edit->id }}"> @endif
        <div class="field"><label for="name">Nama</label><input class="input @error('name') is-err @enderror" id="name" name="name" maxlength="80" required value="{{ old('name', $r->name) }}" placeholder="Gagal login berulang"><x-field-error name="name"/></div>
        <div class="field"><label for="pattern">Pola</label><input class="input mono @error('pattern') is-err @enderror" id="pattern" name="pattern" maxlength="300" required value="{{ old('pattern', $r->pattern) }}" placeholder="login failure"><x-field-error name="pattern"/>
            <label class="check"><input type="checkbox" name="is_regex" value="1" @checked(old('is_regex', $r->is_regex))><span>Regex<small>Tanpa beda huruf besar kecil. Lookahead dan backreference tidak didukung.</small></span></label></div>
        <div class="row">
            <div class="field"><label for="max_severity">Tingkat log</label><select class="input" id="max_severity" name="max_severity">@foreach($SEV as $k => $v)<option value="{{ $k }}" @selected((int) old('max_severity', $r->max_severity) === $k)>{{ $v }} ke atas</option>@endforeach</select></div>
            <div class="field"><label for="app">Program <span class="muted">(opsional)</span></label><input class="input mono" id="app" name="app" maxlength="64" value="{{ old('app', $r->app) }}" placeholder="sshd"></div>
        </div>
        <div class="row">
            <div class="field"><label for="site_id">Site</label><select class="input" id="site_id" name="site_id"><option value="">Semua</option>@foreach($sites as $s)<option value="{{ $s->id }}" @selected((int) old('site_id', $r->site_id) === $s->id)>{{ $s->code }}</option>@endforeach</select></div>
            <div class="field"><label for="device_id">Perangkat</label><select class="input" id="device_id" name="device_id"><option value="">Semua</option>@foreach($devices as $d)<option value="{{ $d->id }}" @selected((int) old('device_id', $r->device_id) === $d->id)>{{ $d->name }}</option>@endforeach</select></div>
        </div>
        <div class="row r3">
            <div class="field"><label for="min_count">Muncul</label><input class="input" type="number" id="min_count" name="min_count" min="1" max="10000" value="{{ old('min_count', $r->min_count) }}"><div class="hint">kali</div></div>
            <div class="field"><label for="window_m">Dalam</label><input class="input" type="number" id="window_m" name="window_m" min="1" max="1440" value="{{ old('window_m', intdiv($r->window_s, 60)) }}"><div class="hint">menit</div></div>
            <div class="field"><label for="clear_m">Pulih setelah sepi</label><input class="input" type="number" id="clear_m" name="clear_m" min="1" max="10080" value="{{ old('clear_m', intdiv($r->clear_s, 60)) }}"><div class="hint">menit</div></div>
        </div>
        <div class="field"><label for="severity">Severity problem</label><select class="input" id="severity" name="severity">@foreach($P as $k => [$lbl])<option value="{{ $k }}" @selected(old('severity', $r->severity) === $k)>{{ $lbl }}</option>@endforeach</select></div>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $r->enabled))><span>Aktif</span></label>
        <div class="form-foot"><button class="btn primary">{{ $edit ? 'Simpan' : 'Buat aturan' }}</button>
            <button class="btn" formaction="{{ route('logs.rules.test') }}" formmethod="post" name="_method" value="POST">Uji ke log 24 jam</button></div>
    </form>
</div></section>
</div>
</div>
@endsection
