@extends('layouts.app')
@section('title', 'Laporan terjadwal')
@php($f = $edit)
@section('content')
<div class="page-head">
    <div><h1>Laporan terjadwal</h1><p>Ringkasan ketersediaan, gangguan, perangkat terburuk, dan aktivitas, dikirim otomatis lewat kanal notifikasi (email: tampilan lengkap; Telegram: ringkas). Rumus ketersediaan sama dengan laporan SLA bulanan.</p></div>
</div>
<div class="grid g-main">
<section class="card"><div class="hd"><h2>Jadwal</h2></div>
    <div class="bd flush">
    @if($rows->isEmpty())<div class="empty">Belum ada jadwal. @if($channels->isEmpty())Buat dulu <a href="{{ route('channels.create') }}">kanal notifikasi</a> (email disarankan).@else Buat dari formulir di samping.@endif</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Nama</th><th>Jadwal</th><th>Kanal</th><th>Terakhir</th><th></th></tr></thead>
        <tbody>
        @foreach($rows as $r)
            <tr class="{{ $r->enabled ? '' : 'is-off' }}">
                <td class="name"><b>{{ $r->name }}</b><small>{{ $r->site_code ? 'site ' . $r->site_code : 'semua site' }}{{ $r->enabled ? '' : ', nonaktif' }}</small></td>
                <td>{{ ['daily' => 'Harian', 'weekly' => 'Senin', 'monthly' => 'Tgl 1'][$r->frequency] }}, {{ sprintf('%02d.00', $r->send_hour) }}</td>
                <td>{{ $r->channel_name }} <span class="tag">{{ $r->channel_type }}</span></td>
                <td>@if($r->requested)<span class="badge info">menunggu kirim</span>@elseif($r->last_error)<span class="badge crit" title="{{ $r->last_error }}">gagal</span><small class="muted" style="display:block;max-width:240px">{{ \Illuminate\Support\Str::limit($r->last_error, 90) }}</small>@elseif($r->last_sent_at)<span class="muted">{{ \Illuminate\Support\Carbon::parse($r->last_sent_at)->diffForHumans() }}</span>@else<span class="muted">belum pernah</span>@endif</td>
                <td class="nowrap" style="text-align:right">
                    <button type="button" class="btn sm" data-preview="{{ route('reports.schedules.preview', $r->id) }}" data-title="{{ $r->name }}">Pratinjau</button>
                    <form method="post" action="{{ route('reports.schedules.send', $r->id) }}" style="display:inline">@csrf<button class="btn sm">Kirim sekarang</button></form>
                    <a class="btn sm" href="{{ route('reports.schedules', ['ubah' => $r->id]) }}">Ubah</a>
                    <form method="post" action="{{ route('reports.schedules.destroy', $r->id) }}" style="display:inline" data-confirm="Hapus jadwal ini?">@csrf @method('delete')<button class="btn sm danger" aria-label="Hapus"><x-icon name="trash"/></button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>

<section class="card"><div class="hd"><h2>{{ $f ? 'Ubah jadwal' : 'Jadwal baru' }}</h2>@if($f)<a class="sub" href="{{ route('reports.schedules') }}">Batal</a>@endif</div><div class="bd">
    <form method="post" action="{{ $f ? route('reports.schedules.update', $f->id) : route('reports.schedules.store') }}" class="stack">@csrf @if($f) @method('put') @endif
        <div class="field"><label for="name">Nama</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name', $f->name ?? 'Ringkasan harian NOC') }}" required maxlength="128"><x-field-error name="name"/></div>
        <div class="field"><label for="frequency">Frekuensi</label><select class="input" id="frequency" name="frequency">
            @foreach(\App\Http\Controllers\ReportScheduleController::FREQ as $k => $v)<option value="{{ $k }}" @selected(old('frequency', $f->frequency ?? 'daily') === $k)>{{ $v }}</option>@endforeach</select>
            <div class="hint">Mingguan dikirim tiap Senin, bulanan tiap tanggal 1.</div></div>
        <div class="field"><label for="send_hour">Jam kirim</label><select class="input" id="send_hour" name="send_hour" style="max-width:140px">
            @for($h = 0; $h < 24; $h++)<option value="{{ $h }}" @selected((int) old('send_hour', $f->send_hour ?? 7) === $h)>{{ sprintf('%02d.00', $h) }}</option>@endfor</select></div>
        <div class="field"><label for="channel_id">Kirim ke kanal</label><select class="input @error('channel_id') is-err @enderror" id="channel_id" name="channel_id" required>
            <option value="">— pilih kanal —</option>
            @foreach($channels as $c)<option value="{{ $c->id }}" @selected((int) old('channel_id', $f->channel_id ?? 0) === $c->id)>{{ $c->name }} ({{ $c->type }})</option>@endforeach</select>
            <x-field-error name="channel_id"/><div class="hint">Penerima diatur di <a href="{{ route('channels.index') }}">Notifikasi</a>. Pengaturan severity/eskalasi kanal tidak berlaku untuk laporan.</div></div>
        <div class="field"><label for="site_id">Cakupan</label><select class="input" id="site_id" name="site_id">
            <option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected((int) old('site_id', $f->site_id ?? 0) === $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach</select></div>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $f->enabled ?? true))><span>Aktif</span></label>
        <div><button class="btn primary">{{ $f ? 'Simpan' : 'Buat jadwal' }}</button></div>
    </form>
</div></section>
</div>

<div class="modal" data-preview-modal hidden>
    <div class="modal-box" style="width:min(760px,96vw);height:min(88vh,1000px);display:flex;flex-direction:column">
        <div class="hd" style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line)"><b data-preview-title style="flex:1"></b><button type="button" class="btn sm" data-preview-close>Tutup</button></div>
        <iframe data-preview-frame title="Pratinjau laporan" sandbox style="flex:1;border:0;width:100%;background:#f3f4f6"></iframe>
    </div>
</div>
@endsection
