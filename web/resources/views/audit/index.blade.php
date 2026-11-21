@extends('layouts.app')
@section('title', 'Jejak audit')
@section('content')
<div class="page-head"><div><h1>Jejak audit</h1><p>Siapa mengubah apa dan kapan. Kredensial tidak pernah dicatat.</p></div></div>
<section class="card"><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Keterangan</th><th>IP</th></tr></thead>
    <tbody>
    @forelse($logs as $l)
        <tr>
            <td class="nowrap mono" title="{{ $l->at->format('d/m/Y H:i:s') }}">{{ $l->at->format('d/m/Y H:i') }}</td>
            <td>{{ $l->user?->username ?? '—' }}</td>
            <td><span class="tag">{{ $l->action }}</span></td>
            <td>{{ $l->description }}
                @if($l->changes)<details><summary class="muted" style="cursor:pointer;font-size:12.5px">perubahan</summary>
                    <div class="mono" style="font-size:12px">@foreach($l->changes as $k => $v){{ $k }}: {{ is_array($v) ? json_encode($v[0]) . ' → ' . json_encode($v[1]) : $v }}<br>@endforeach</div></details>@endif</td>
            <td class="mono muted">{{ $l->ip_address }}</td>
        </tr>
    @empty
        <tr><td colspan="5"><div class="empty">Belum ada catatan.</div></td></tr>
    @endforelse
    </tbody>
</table></div></div>@include('partials.pager', ['p' => $logs])</section>
@endsection
