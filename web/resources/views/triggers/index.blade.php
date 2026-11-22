@extends('layouts.app')
@section('title', 'Trigger')
@php($S = \App\Models\Problem::SEVERITIES)
@section('content')
<div class="page-head">
    <div><h1>Trigger</h1>
        <p>Aturan problem buatan sendiri, di luar ambang bawaan. Collector menilai setiap putaran ping; problem muncul bila kondisinya bertahan selama durasi yang ditentukan, lalu selesai sendiri saat kondisinya hilang.</p></div>
    @can('manage-devices')<div class="actions"><a class="btn primary" href="{{ route('triggers.create') }}"><x-icon name="plus"/>Tambah trigger</a></div>@endcan
</div>

<section class="card"><div class="bd flush">
@if($triggers->isEmpty())
    <div class="empty">Belum ada trigger.
        <div class="muted" style="margin-top:8px;max-width:620px;margin-inline:auto">Contoh yang sering dipakai: trafik masuk port uplink kurang dari 0,01 Mbps selama 10 menit di jam kerja; suhu panel paling sedikit 60 °C selama 5 menit; sinyal radio kurang dari −75 dBm selama 15 menit.</div>
        @can('manage-devices')<div style="margin-top:14px"><a class="btn primary" href="{{ route('triggers.create') }}"><x-icon name="plus"/>Tambah trigger pertama</a></div>@endcan
    </div>
@else
<div class="table-wrap"><table class="t">
    <thead><tr><th>Trigger</th><th>Kondisi</th><th>Berlaku</th><th>Severity</th><th class="num">Problem terbuka</th>@can('manage-devices')<th></th>@endcan</tr></thead>
    <tbody>
    @foreach($triggers as $t)
        <tr class="{{ $t->enabled ? (($open[$t->id] ?? 0) ? 'is-down' : '') : 'is-off' }}">
            <td class="name">@can('manage-devices')<a href="{{ route('triggers.edit', $t) }}">{{ $t->name }}</a>@else{{ $t->name }}@endcan
                @unless($t->enabled)<small>dimatikan</small>@endunless @if($t->note && $t->enabled)<small>{{ \Illuminate\Support\Str::limit($t->note, 90) }}</small>@endif</td>
            <td>{{ $t->conditionText() }}</td>
            <td>{{ $t->scopeText() }}<small class="nil" style="display:block">{{ $t->scheduleText() }}</small></td>
            <td><span class="badge plain {{ $S[$t->severity][1] }}">{{ $S[$t->severity][0] }}</span></td>
            <td class="num">@if($open[$t->id] ?? 0)<a href="{{ route('problems.index') }}" class="inc-tag">{{ $open[$t->id] }}</a>@else 0 @endif</td>
            @can('manage-devices')
            <td class="right nowrap">
                <form method="post" action="{{ route('triggers.toggle', $t) }}" style="display:inline">@csrf<button class="btn sm">{{ $t->enabled ? 'Matikan' : 'Aktifkan' }}</button></form>
            </td>
            @endcan
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
</div></section>
@endsection
