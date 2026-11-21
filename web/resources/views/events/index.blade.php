@extends('layouts.app')
@section('title', 'Kejadian')
@section('content')
<div class="page-head"><div><h1>Kejadian</h1><p>Down, pulih, reboot, flapping, latensi tinggi, dan SNMP — dicatat otomatis oleh collector (disimpan 90 hari)</p></div></div>
<section class="card">
    <form class="filters" method="get">
        <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="Nama / IP perangkat">
        <select class="input" name="kind" aria-label="Jenis">
            <option value="">Semua jenis</option>
            @foreach(\App\Models\DeviceEvent::KINDS as $k => [$label])<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $label }}</option>@endforeach
        </select>
        <select class="input" name="site" aria-label="Site">
            <option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach
        </select>
        <button class="btn sm">Terapkan</button>
        @if(request()->hasAny(['q', 'kind', 'site']))<a href="{{ route('events.index') }}" class="btn sm">Reset</a>@endif
    </form>
    <div class="bd flush">@include('events.feed', ['events' => $events, 'showDevice' => true])</div>
    @include('partials.pager', ['p' => $events])
</section>
@endsection
