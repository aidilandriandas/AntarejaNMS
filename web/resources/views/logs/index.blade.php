@extends('layouts.app')
@section('title', 'Log')
@php($nf = fn ($n) => number_format($n, 0, ',', '.'))
@php($keep = array_filter($f, fn ($v, $k) => $k !== 'before' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH))
@section('content')
<div class="page-head">
    <div><h1>Log{{ $device ? ': ' . $device->name : '' }}</h1><p>Syslog dari perangkat (UDP/TCP port {{ $port }}), dicocokkan ke perangkat lewat IP pengirim. Disimpan {{ $settings['keep'] }} hari.</p></div>
    <div class="row-actions">@can('manage-devices')<a class="btn" href="{{ route('logs.rules') }}"><x-icon name="bell"/>Aturan log</a>@endcan
    <label class="check" title="Muat ulang tiap 10 detik"><input type="checkbox" data-log-live> Live</label></div>
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">Log 1 jam</div><div class="val">{{ $nf($stats->total) }}</div></div>
    <a class="kpi {{ $stats->errors ? 'warn' : '' }}" href="{{ route('logs.index', ['sev' => 3, 'period' => '1h']) }}"><div class="lbl">Error ke atas, 1 jam</div><div class="val">{{ $nf($stats->errors) }}</div><div class="note">Emergency, Alert, Critical, Error</div></a>
    <div class="kpi"><div class="lbl">Pengirim, 1 jam</div><div class="val">{{ $nf($stats->sources) }}</div></div>
    <a class="kpi" href="{{ route('logs.index', ['unknown' => 1]) }}"><div class="lbl">Belum terdaftar</div><div class="val">{{ $nf($stats->unknown) }}</div><div class="note">IP pengirim tidak cocok dengan perangkat</div></a>
</div>

<div class="grid g-main">
<section class="card">
    <form class="filters" method="get">
        @if($device)<input type="hidden" name="device" value="{{ $device->id }}">@endif
        <input class="input" type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Cari teks log…" style="min-width:200px">
        <select class="input" name="sev" aria-label="Tingkat">
            <option value="">Semua tingkat</option>
            @foreach(\App\Models\SyslogEntry::SEVERITIES as $k => [$label])<option value="{{ $k }}" @selected(isset($f['sev']) && (int) $f['sev'] === $k)>≤ {{ $label }}</option>@endforeach
        </select>
        <select class="input" name="site" aria-label="Site">
            <option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(($f['site'] ?? null) == $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach
        </select>
        <input class="input" name="app" value="{{ $f['app'] ?? '' }}" placeholder="Program / topik" style="min-width:120px">
        <input class="input mono" name="src" value="{{ $f['src'] ?? '' }}" placeholder="IP pengirim" style="min-width:120px">
        <select class="input" name="period" aria-label="Rentang">@foreach(\App\Http\Controllers\LogController::PERIODS as $k => $v)<option value="{{ $k }}" @selected($f['period'] === $k)>{{ $v }}</option>@endforeach</select>
        @if(! empty($f['unknown']))<input type="hidden" name="unknown" value="1"><span class="tag">belum terdaftar</span>@endif
        <button class="btn sm">Terapkan</button>
        @if(count($keep) > 1 || $f['period'] !== '24h')<a href="{{ route('logs.index') }}" class="btn sm">Reset</a>@endif
    </form>
    <div class="bd flush">
    @if($rows->isEmpty())
        <div class="empty">Belum ada log untuk filter ini.@if($stats->total == 0 && count($keep) <= 1) Arahkan syslog perangkat ke IP server ini port {{ $port }}, lihat petunjuk di samping.@endif</div>
    @else
    <div class="table-wrap"><table class="t logs">
        <thead><tr><th>Waktu</th><th>Tingkat</th><th>Perangkat</th><th>Program</th><th>Pesan</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
            <tr>
                <td class="mono nowrap" title="{{ $r->received_at->format('d/m/Y H:i:s') }}">{{ $r->received_at->isToday() ? $r->received_at->format('H:i:s') : $r->received_at->format('d/m H:i:s') }}</td>
                <td><span class="badge plain {{ $r->sevTone() }}">{{ $r->sevLabel() }}</span></td>
                <td class="nowrap">@if($r->device)<a href="{{ route('logs.index', ['device' => $r->device_id] + array_diff_key($keep, ['device' => 1])) }}">{{ $r->device->name }}</a>@else<a class="mono muted" href="{{ route('logs.index', ['src' => $r->source]) }}" title="IP belum terdaftar sebagai perangkat">{{ $r->source }}</a>@endif</td>
                <td class="mono" style="font-size:12px">{{ $r->app ?? '' }}</td>
                <td class="msg">{{ $r->message }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
    @if($more)
    <nav class="pager"><span>100 log terbaru{{ ! empty($f['before']) ? ' sebelum #' . $f['before'] : '' }}</span>
        <span style="display:flex;gap:6px">@if(! empty($f['before']))<a class="btn sm" href="{{ route('logs.index', array_diff_key($keep, ['before' => 1])) }}">« Terbaru</a>@endif
        <a class="btn sm" href="{{ route('logs.index', $keep + ['before' => $rows->last()->id]) }}">Lebih lama ›</a></span></nav>
    @endif
</section>

<div class="stack">
    <section class="card"><div class="hd"><h2>Paling banyak error, 24 jam</h2></div>
        <div class="bd flush">
        @if(empty($top))<div class="empty">Belum ada log dari perangkat terdaftar.</div>@else
        <table class="t"><tbody>
            @foreach($top as $t)
            <tr><td class="name"><a href="{{ route('logs.index', ['device' => $t->device_id, 'sev' => $t->err ? 3 : null]) }}">{{ $t->name }}</a></td>
                <td class="num">@if($t->err)<span style="color:var(--crit);font-weight:600">{{ $nf($t->err) }} error</span>@endif</td>
                <td class="num muted">{{ $nf($t->n) }}</td></tr>
            @endforeach
        </tbody></table>
        @endif
        </div></section>

    <section class="card"><div class="hd"><h2>Cara mengirim log</h2></div><div class="bd">
        <p class="muted" style="margin-top:0">Ganti <code>IP-SERVER</code> dengan IP VM ANTAREJA. Perangkat harus terdaftar dengan IP yang sama dengan IP pengirim (IP manajemen atau salah satu IP interface dari IPAM).</p>
        <b>MikroTik</b>
        <pre class="code">/system logging action add name=antareja target=remote remote=IP-SERVER remote-port={{ $port }}
/system logging add action=antareja topics=info
/system logging add action=antareja topics=warning
/system logging add action=antareja topics=error
/system logging add action=antareja topics=critical</pre>
        <b>Cisco IOS</b>
        <pre class="code">logging host IP-SERVER
logging trap informational
service timestamps log datetime localtime</pre>
        <p class="muted" style="font-size:12px;margin-bottom:0">Perintah di atas dijalankan oleh engineer di perangkat. ANTAREJA tidak pernah mengubah konfigurasi perangkat.</p>
    </div></section>

    @can('admin')
    <section class="card"><div class="hd"><h2>Pengaturan</h2></div><div class="bd">
        <form method="post" action="{{ route('logs.settings') }}" class="stack">@csrf
            <div class="field"><label for="keep">Lama simpan (hari)</label><input class="input @error('keep') is-err @enderror" type="number" id="keep" name="keep" min="1" max="365" value="{{ old('keep', $settings['keep']) }}" required><x-field-error name="keep"/></div>
            <div class="field"><label for="event">Catat sebagai kejadian perangkat</label>
                <select class="input" id="event" name="event">
                    <option value="-1" @selected($settings['event'] === -1)>Tidak</option>
                    @foreach([0 => 'Emergency', 1 => 'Alert ke atas', 2 => 'Critical ke atas', 3 => 'Error ke atas'] as $k => $v)<option value="{{ $k }}" @selected($settings['event'] === $k)>{{ $v }}</option>@endforeach
                </select><div class="hint">Maks. 1 kejadian per perangkat per 10 menit</div></div>
            <div><button class="btn primary sm">Simpan</button></div>
        </form>
    </div></section>
    @endcan
</div>
</div>
@endsection
