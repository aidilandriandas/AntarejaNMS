@extends('layouts.app')
@section('title', 'Jejak audit')
@php($qs = array_filter(['user' => $f['user'], 'aksi' => $f['action'], 'q' => $f['q'], 'dari' => $f['from'], 'sampai' => $f['to']], fn ($v) => $v !== null && $v !== ''))
@section('content')
<div class="page-head">
    <div><h1>Jejak audit</h1><p>Siapa mengubah apa dan kapan. Kredensial tidak pernah dicatat. Catatan tidak bisa diubah atau dihapus dari aplikasi; tiap baris dikunci dengan hash berantai sehingga perubahan di luar aplikasi terdeteksi.
        {{ $keepDays ? 'Disimpan ' . $keepDays . ' hari.' : 'Disimpan tanpa batas waktu.' }}</p></div>
    <div class="row-actions">
        <form method="post" action="{{ route('audit.verify') }}">@csrf<button class="btn"><x-icon name="shield"/>Periksa keutuhan</button></form>
        <a class="btn" href="{{ route('audit.export', $qs) }}"><x-icon name="download"/>Ekspor CSV</a>
    </div>
</div>
<section class="card">
    <form class="filters" method="get">
        <input class="input" type="search" name="q" value="{{ $f['q'] }}" placeholder="Cari keterangan atau IP" aria-label="Cari" style="min-width:200px">
        <select class="input" name="user" aria-label="Pengguna"><option value="">Semua pengguna</option>
            @foreach($users as $u)<option value="{{ $u->id }}" @selected((int) $f['user'] === $u->id)>{{ $u->username }}</option>@endforeach</select>
        <select class="input" name="aksi" aria-label="Aksi"><option value="">Semua aksi</option>
            @foreach($actions as $a)<option value="{{ $a }}" @selected($f['action'] === $a)>{{ $a }}</option>@endforeach</select>
        <label class="muted" for="dari">Dari</label><input class="input" type="date" id="dari" name="dari" value="{{ $f['from'] }}">
        <label class="muted" for="sampai">sampai</label><input class="input" type="date" id="sampai" name="sampai" value="{{ $f['to'] }}">
        <button class="btn">Saring</button>@if($qs)<a class="btn" href="{{ route('audit.index') }}">Hapus saringan</a>@endif
    </form>
    <div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Keterangan</th><th>IP</th></tr></thead>
    <tbody>
    @forelse($logs as $l)
        <tr>
            <td class="nowrap mono" title="Baris #{{ $l->id }}, hash {{ substr((string) $l->hash, 0, 16) }}">{{ $l->at->format('d/m/Y H:i:s') }}</td>
            <td>{{ $l->user?->username ?? ($l->user_ref ? 'akun #' . $l->user_ref . ' (dihapus)' : 'sistem') }}</td>
            <td><span class="tag">{{ $l->action }}</span></td>
            <td>{{ $l->description }}
                @if($l->changes)<details><summary class="muted" style="cursor:pointer;font-size:12.5px">perubahan</summary>
                    <div class="mono" style="font-size:12px">@foreach($l->changes as $k => $v){{ $k }}: {{ is_array($v) ? json_encode($v[0]) . ' jadi ' . json_encode($v[1]) : $v }}<br>@endforeach</div></details>@endif</td>
            <td class="mono muted">{{ $l->ip_address }}</td>
        </tr>
    @empty
        <tr><td colspan="5"><div class="empty">{{ $qs ? 'Tidak ada catatan yang cocok dengan saringan.' : 'Belum ada catatan.' }}</div></td></tr>
    @endforelse
    </tbody>
</table></div></div>@include('partials.pager', ['p' => $logs])</section>
@endsection
