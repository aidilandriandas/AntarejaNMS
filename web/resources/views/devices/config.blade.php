@extends('layouts.app')
@section('title', 'Konfigurasi ' . $device->name)
@section('content')
<div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / <a href="{{ route('devices.show', $device) }}">{{ $device->name }}</a> / Konfigurasi</div>
<div class="page-head">
    <div><h1>Konfigurasi, {{ \Illuminate\Support\Carbon::parse($v->taken_at)->format('d/m/Y H:i') }}</h1>
        <p>{{ $v->lines }} baris, {{ number_format($v->size_bytes / 1024, 1, ',', '.') }} KB @if($diff), <span style="color:var(--ok)">+{{ $v->added }}</span> / <span style="color:var(--crit)">−{{ $v->removed }}</span> baris dibanding versi sebelumnya @endif</p></div>
    <div class="actions"><a class="btn" href="{{ route('devices.config.download', [$device, $v->id]) }}"><x-icon name="download"/>Unduh</a></div>
</div>
<div class="grid g-main">
    <div class="stack">
        @if($diff)
        <section class="card"><div class="hd"><h2>Perubahan</h2></div><div class="bd flush"><pre class="code diff">@foreach(explode("\n", rtrim($diff)) as $l)<span class="{{ str_starts_with($l, '+') ? 'add' : (str_starts_with($l, '-') ? 'del' : (str_starts_with($l, '@@') ? 'hunk' : '')) }}">{{ $l === '' ? ' ' : $l }}</span>@endforeach</pre></div></section>
        @endif
        <section class="card"><div class="hd"><h2>Isi lengkap</h2><span class="sub">password/kunci disembunyikan oleh perintah ekspor perangkat</span></div>
            <div class="bd flush"><pre class="code">{{ $content }}</pre></div></section>
    </div>
    <section class="card"><div class="hd"><h2>Versi</h2><span class="sub">{{ $versions->count() }} (maks. 50)</span></div><div class="bd flush">
        <table class="t"><tbody>
        @foreach($versions as $x)
            <tr class="{{ $x->id === $v->id ? 'port-row sel' : '' }}"><td><a href="{{ route('devices.config', [$device, $x->id]) }}">{{ \Illuminate\Support\Carbon::parse($x->taken_at)->format('d/m/Y H:i') }}</a></td>
                <td class="num">@if($x->added || $x->removed)<span style="color:var(--ok)">+{{ $x->added }}</span> <span style="color:var(--crit)">−{{ $x->removed }}</span>@else<span class="muted">pertama</span>@endif</td></tr>
        @endforeach
        </tbody></table>
    </div></section>
</div>
@endsection
