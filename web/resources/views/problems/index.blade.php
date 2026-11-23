@extends('layouts.app')
@section('title', 'Problem')
@section('content')
<div class="page-head">
    <div><h1>Problem</h1><p>Dinilai otomatis tiap menit. Problem selesai sendiri saat kondisinya pulih. ACK menghentikan eskalasi.</p></div>
    <div class="seg" role="tablist">
        <a class="btn sm {{ $history ? '' : 'primary' }}" href="{{ route('problems.index') }}">Aktif ({{ $openCount }})</a>
        <a class="btn sm {{ $history ? 'primary' : '' }}" href="{{ route('problems.index', ['tab' => 'riwayat']) }}">Riwayat</a>
    </div>
</div>
<section class="card">
    <form class="filters" method="get">
        @if($history)<input type="hidden" name="tab" value="riwayat">@endif
        <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="Nama / IP perangkat">
        <select class="input" name="severity" aria-label="Severity"><option value="">Semua severity</option>
            @foreach(\App\Models\Problem::SEVERITIES as $k => [$label])<option value="{{ $k }}" @selected(request('severity') === $k)>{{ $label }}</option>@endforeach</select>
        <select class="input" name="site" aria-label="Site"><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }}</option>@endforeach</select>
        @unless($history)<label class="check" style="align-items:center"><input type="checkbox" name="ack" value="belum" @checked(request('ack') === 'belum')><span>Hanya belum ACK</span></label>@endunless
        <button class="btn sm">Terapkan</button>
    </form>
    @php($select = ! $history && auth()->user()->can('manage-devices') && $problems->isNotEmpty())
    @if($select)
    <form id="buat-insiden" method="post" action="{{ route('incidents.store') }}" class="bulkbar">@csrf
        <span class="muted">Centang beberapa problem yang berasal dari gangguan yang sama, lalu</span>
        <input class="input" name="title" maxlength="255" placeholder="Judul insiden (opsional)" aria-label="Judul insiden">
        <button class="btn sm primary">Buat insiden</button><x-field-error name="problems"/>
    </form>
    @endif
    <div class="bd flush">@include('problems.table', ['select' => $select, 'impactLines' => $impactLines, 'problems' => $problems, 'empty' => $history ? 'Belum ada riwayat problem.' : 'Tidak ada problem aktif. Semua normal.'])</div>
    @include('partials.pager', ['p' => $problems])
</section>
@endsection
