@extends('layouts.app')
@section('title', 'Templat perangkat')
@section('content')
<div class="page-head"><div><h1>Templat perangkat</h1>
    <p>Satu set ambang, makro, exporter, cek layanan, dan trigger yang dipasang ke banyak perangkat sekaligus, mis. "Server Linux FMS" atau "Radio PTP jarak jauh". Ubah templat sekali, semua perangkatnya ikut. Ambang yang diisi di perangkat sendiri tetap menang.</p></div>
    <a class="btn primary" href="{{ route('templates.create') }}"><x-icon name="plus"/>Templat baru</a></div>
<section class="card"><div class="bd flush">
    @if($templates->isEmpty())<div class="empty">Belum ada templat.</div>@else
    <table class="t"><thead><tr><th>Templat</th><th class="num">Perangkat</th><th class="num">Ambang</th><th class="num">Cek layanan</th><th>Exporter</th></tr></thead><tbody>
    @foreach($templates as $t)
        <tr><td class="name"><a href="{{ route('templates.edit', $t) }}">{{ $t->name }}</a>@if($t->description)<small>{{ \Illuminate\Support\Str::limit($t->description, 120) }}</small>@endif</td>
            <td class="num">{{ $t->devices_count }}</td><td class="num">{{ count($t->thresholds ?? []) }}</td><td class="num">{{ $t->checks_count }}</td>
            <td class="mono muted">{{ $t->exporter_url }}</td></tr>
    @endforeach
    </tbody></table>@endif
</div></section>
@endsection
