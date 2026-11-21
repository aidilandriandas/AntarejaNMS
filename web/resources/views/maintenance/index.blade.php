@extends('layouts.app')
@section('title', 'Maintenance')
@section('content')
<div class="page-head"><div><h1>Maintenance</h1><p>Selama jendela maintenance, problem perangkat terkait tetap tercatat tetapi tidak dikirim sebagai notifikasi.</p></div></div>
<div class="grid g-main">
    <div class="stack">
        <section class="card"><div class="hd"><h2>Berjalan & terjadwal</h2></div><div class="bd flush">
            @if($active->isEmpty())<div class="empty">Tidak ada maintenance berjalan atau terjadwal.</div>@else
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Judul</th><th>Cakupan</th><th>Waktu</th><th>Status</th>@can('manage-devices')<th></th>@endcan</tr></thead>
                <tbody>
                @foreach($active as $m)
                    <tr>
                        <td class="name">{{ $m->title }}<small>{{ $m->note }}{{ $m->creator ? ' · oleh ' . $m->creator->username : '' }}</small></td>
                        <td>{{ $m->scopeLabel() }}</td>
                        <td class="nowrap">{{ $m->starts_at->format('d/m H:i') }} – {{ $m->ends_at->format('d/m H:i') }}</td>
                        <td>@if($m->state() === 'berjalan')<span class="badge info">berjalan</span>@else<span class="badge plain">terjadwal</span>@endif</td>
                        @can('manage-devices')
                        <td class="right nowrap">
                            @if($m->state() === 'berjalan')<form method="post" action="{{ route('maintenance.finish', $m) }}" style="display:inline">@csrf<button class="btn sm">Akhiri sekarang</button></form>@endif
                            <form method="post" action="{{ route('maintenance.destroy', $m) }}" style="display:inline" data-confirm="Hapus maintenance {{ $m->title }}?">@csrf @method('DELETE')<button class="btn sm danger" aria-label="Hapus"><x-icon name="trash"/></button></form>
                        </td>
                        @endcan
                    </tr>
                @endforeach
                </tbody>
            </table></div>@endif
        </div></section>
        <section class="card"><div class="hd"><h2>Riwayat</h2><span class="sub">30 terakhir</span></div><div class="bd flush">
            @if($past->isEmpty())<div class="empty">Belum ada.</div>@else
            <div class="table-wrap"><table class="t"><tbody>
                @foreach($past as $m)<tr class="is-off"><td class="name">{{ $m->title }}<small>{{ $m->scopeLabel() }}</small></td><td class="nowrap right">{{ $m->starts_at->format('d/m/Y H:i') }} – {{ $m->ends_at->format('d/m H:i') }}</td></tr>@endforeach
            </tbody></table></div>@endif
        </div></section>
    </div>
    @can('manage-devices')
    <section class="card"><div class="hd"><h2>Jadwalkan maintenance</h2></div><div class="bd">
        <form method="post" action="{{ route('maintenance.store') }}" class="form" data-maint-form>
            @csrf
            <div class="field"><label for="title">Judul</label><input class="input @error('title') is-err @enderror" id="title" name="title" value="{{ old('title') }}" placeholder="mis. Penggantian UPS rak Pit A" required><x-field-error name="title"/></div>
            <div class="row">
                <div class="field"><label for="starts_at">Mulai</label><input class="input" type="datetime-local" id="starts_at" name="starts_at" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required></div>
                <div class="field"><label for="ends_at">Selesai</label><input class="input @error('ends_at') is-err @enderror" type="datetime-local" id="ends_at" name="ends_at" value="{{ old('ends_at', now()->addHours(2)->format('Y-m-d\TH:i')) }}" required><x-field-error name="ends_at"/></div>
            </div>
            <div class="field"><label for="scope">Cakupan</label>
                <select class="input" id="scope" name="scope" data-scope>
                    <option value="devices" @selected(old('scope', 'devices') === 'devices')>Perangkat tertentu</option>
                    <option value="site" @selected(old('scope') === 'site')>Satu site</option>
                    <option value="all" @selected(old('scope') === 'all')>Semua perangkat</option>
                </select></div>
            <div class="field" data-scope-show="site"><label for="site_id">Site</label>
                <select class="input @error('site_id') is-err @enderror" id="site_id" name="site_id"><option value="">— pilih —</option>
                    @foreach($sites as $s)<option value="{{ $s->id }}" @selected(old('site_id') == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach</select><x-field-error name="site_id"/></div>
            <div class="field" data-scope-show="devices"><label for="device_ids">Perangkat</label>
                <select class="input @error('device_ids') is-err @enderror" id="device_ids" name="device_ids[]" multiple size="8" style="height:auto">
                    @foreach($devices as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, old('device_ids', [])))>{{ $d->name }} ({{ $d->host }})</option>@endforeach</select>
                <div class="hint">Tahan Ctrl/⌘ untuk memilih beberapa.</div><x-field-error name="device_ids"/></div>
            <div class="field"><label for="note">Catatan <span class="muted">(opsional)</span></label><input class="input" id="note" name="note" value="{{ old('note') }}" placeholder="Nomor tiket / PIC"></div>
            <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Jadwalkan</button></div>
        </form>
    </div></section>
    @endcan
</div>
@endsection
