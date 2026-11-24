@extends('layouts.app')
@section('title', $r->title)
@section('content')
<div class="crumbs"><a href="{{ route('runbooks.index') }}">Runbook</a> / {{ $r->title }}</div>
<div class="page-head">
    <div><h1>{{ $r->title }} @unless($r->enabled)<span class="badge">nonaktif</span>@endunless</h1><p>Berlaku untuk: {{ $r->scopeLabel() }}</p></div>
    @can('manage-devices')<div class="actions"><a class="btn" href="{{ route('runbooks.edit', $r) }}"><x-icon name="edit"/>Ubah</a></div>@endcan
</div>
<section class="card" style="max-width:860px"><div class="bd">
    <div class="runbook-body">{{ $r->body }}</div>
    <p class="muted" style="margin:var(--space-md) 0 0;font-size:var(--text-xs)">Diubah {{ $r->updated_at->format('d/m/Y H.i') }}@if($r->editor) oleh {{ $r->editor->name }}@endif</p>
</div></section>
@endsection
