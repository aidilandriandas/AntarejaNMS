@extends('layouts.app')
@section('title', 'Runbook')
@section('content')
<div class="page-head">
    <div><h1>Runbook</h1><p>Langkah penanganan yang tampil langsung di problem, halaman perangkat, dan pesan Telegram/email. Bila beberapa runbook cocok, yang paling spesifik dipakai: perangkat, lalu site, lalu kategori, lalu jenis problem.</p></div>
    @can('manage-devices')<div class="actions"><a class="btn primary" href="{{ route('runbooks.create') }}"><x-icon name="plus"/>Tambah runbook</a></div>@endcan
</div>
<section class="card"><div class="bd flush">
    @if($runbooks->isEmpty())
        <div class="empty">Belum ada runbook. Mulai dari gangguan yang paling sering, mis. <i>Radio PTP down</i>: cek PoE di menara, cek sinyal radio sisi lawan, hubungi vendor.</div>
    @else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Runbook</th><th>Berlaku untuk</th><th>Diubah</th></tr></thead>
        <tbody>
        @foreach($runbooks as $r)
            <tr class="{{ $r->enabled ? '' : 'is-off' }}">
                <td class="name"><a href="{{ route('runbooks.show', $r) }}">{{ $r->title }}</a><small>{{ \Illuminate\Support\Str::limit(strtok($r->body, "\n"), 90) }}</small></td>
                <td>{{ $r->scopeLabel() }}@unless($r->enabled) <span class="badge">nonaktif</span>@endunless</td>
                <td>{{ $r->updated_at->diffForHumans() }}@if($r->editor)<div class="muted" style="font-size:12px">{{ $r->editor->username }}</div>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div></section>
@endsection
