@extends('layouts.app')
@section('title', 'Collector site')
@section('content')
<div class="page-head">
    <div><h1>Collector site</h1><p>Collector kecil di site tambang memantau perangkat lokal dan mengirim hasilnya ke server ini. Saat jalur ke pusat putus, hasil diantrekan di site dan dikirim begitu jalur pulih, jadi perangkat site tidak ikut dinyatakan down.</p></div>
</div>
<div class="grid g-main">
<section class="card"><div class="hd"><h2>Collector</h2></div><div class="bd flush">
    @if($collectors->isEmpty())<div class="empty">Belum ada collector site. Semua perangkat dipantau dari server pusat.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Nama</th><th>Status</th><th class="num">Perangkat</th><th>Antrean</th><th>Versi</th></tr></thead>
        <tbody>
        @foreach($collectors as $c)
            @php($st = $c->state())
            <tr class="{{ $st === 'off' ? 'is-off' : ($st === 'late' ? 'is-down' : '') }}">
                <td class="name"><a href="{{ route('collectors.show', $c) }}">{{ $c->name }}</a><small>{{ $c->site ? $c->site->code . ' (' . $c->site->name . ')' : 'tanpa site' }}</small></td>
                <td><span class="badge {{ ['online' => 'ok', 'late' => 'crit', 'never' => 'warn', 'off' => ''][$st] }}">{{ $c->stateLabel() }}</span>
                    @if($c->last_seen_at)<small class="muted" style="display:block">{{ $c->last_seen_at->diffForHumans() }}</small>@endif</td>
                <td class="num">{{ $c->devices_count }} @if($c->down_count)<small class="txt-warn" style="display:block">{{ $c->down_count }} down</small>@endif</td>
                <td>{{ $c->queued === null ? 'tidak ada' : ($c->queued ? $c->queued . ' kiriman menunggu' : 'kosong') }}</td>
                <td class="mono">{{ $c->version ?? 'tidak ada' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div></section>
<section class="card"><div class="hd"><h2>Tambah collector</h2></div><div class="bd">
    <form method="post" action="{{ route('collectors.store') }}" class="form">@csrf
        <div class="field"><label for="c-name">Nama</label><input class="input @error('name') is-err @enderror" id="c-name" name="name" value="{{ old('name') }}" maxlength="64" placeholder="collector-pit-a" required><x-field-error name="name"/></div>
        <div class="field"><label for="c-site">Site</label><select class="input" id="c-site" name="site_id"><option value="">Tanpa site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(old('site_id') == $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach</select>
            <div class="hint">Perangkat di site ini bisa dipindah ke collector sekaligus setelah dibuat.</div></div>
        <div class="field"><label for="c-notes">Catatan <span class="muted">(opsional)</span></label><input class="input" id="c-notes" name="notes" value="{{ old('notes') }}" maxlength="1000" placeholder="VM di ruang server Pit A, IP 10.20.0.5"></div>
        <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Buat dan tampilkan token</button></div>
    </form>
</div></section>
</div>
@endsection
