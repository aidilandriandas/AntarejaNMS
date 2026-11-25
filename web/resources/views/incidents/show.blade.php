@extends('layouts.app')
@section('title', 'Insiden #' . $i->id)
@php($active = $i->problems->whereNull('resolved_at')->count())
@section('content')
<div class="page-head">
    <div><div class="crumbs"><a href="{{ route('incidents.index') }}">Insiden</a> / #{{ $i->id }}</div>
        <h1>{{ $i->title }}</h1>
        <div class="meta" style="margin-top:8px;align-items:center">
            <span><span class="badge {{ $i->statusTone() }}">{{ $i->statusLabel() }}</span> <span class="badge plain {{ $i->sevTone() }}">{{ $i->sevLabel() }}</span></span>
            <span>{{ $i->site ? $i->site->code . ' (' . $i->site->name . ')' : 'Tanpa site' }}</span>
            <span>Dibuka {{ $i->opened_at->format('d/m/Y H:i') }}{{ $i->auto ? ' otomatis' : '' }}</span>
            <span>Lama gangguan {{ \App\Support\Fmt::duration($i->downtimeSeconds()) }}</span>
        </div></div>
    @can('manage-devices')
    <div class="actions">
        @if($i->status === 'closed')
            <form method="post" action="{{ route('incidents.reopen', $i) }}" data-confirm="Buka kembali insiden ini?">@csrf<button class="btn">Buka kembali</button></form>
        @elseif(! $i->owner_id || $i->owner_id !== auth()->id())
            <form method="post" action="{{ route('incidents.take', $i) }}">@csrf<button class="btn primary">Saya tangani</button></form>
        @endif
    </div>
    @endcan
</div>

<div class="kpis">
    <div class="kpi {{ $active ? 'crit' : 'ok' }}"><div class="lbl">Problem aktif</div><div class="val">{{ $active }}<small> / {{ $i->problems->count() }}</small></div><div class="note">{{ $devices }} perangkat terdampak</div></div>
    <div class="kpi"><div class="lbl">Penanggung jawab</div><div class="val" style="font-size:20px">{{ $i->owner?->name ?? 'Belum ada' }}</div><div class="note">{{ $i->handled_at ? 'ditangani ' . $i->handled_at->format('d/m H:i') . ' (' . \App\Support\Fmt::duration($i->opened_at->diffInSeconds($i->handled_at)) . ' setelah dibuka)' : 'belum ditangani' }}</div></div>
    <div class="kpi {{ $i->recovered_at ? 'ok' : '' }}"><div class="lbl">Pulih</div><div class="val" style="font-size:20px">{{ $i->recovered_at?->format('d/m H:i') ?? 'Belum' }}</div><div class="note">{{ $i->closed_at ? 'ditutup ' . $i->closed_at->format('d/m H:i') : 'belum ditutup' }}</div></div>
</div>

<div class="grid g-main">
<div class="stack">
    <section class="card"><div class="hd"><h2>Problem</h2></div><div class="bd flush">
        <div class="table-wrap"><table class="t"><thead><tr><th>Severity</th><th>Problem</th><th>Durasi</th><th>Status</th></tr></thead><tbody>
        @foreach($i->problems as $p)
            <tr class="{{ $p->resolved_at ? 'is-off' : ($p->sevTone() === 'crit' ? 'is-down' : '') }}">
                <td><span class="badge plain {{ $p->sevTone() }}">{{ $p->sevLabel() }}</span></td>
                <td class="name">{{ $p->title }}<small>@if($p->device)<a href="{{ route('devices.show', $p->device) }}">{{ $p->device->name }}</a>@endif{{ $p->suppressed ? ', ' . $p->suppressed_note : '' }}</small></td>
                <td class="nowrap">{{ $p->durationHuman() }}</td>
                <td>@if($p->resolved_at)<span class="badge ok plain">pulih {{ $p->resolved_at->format('H:i') }}</span>@elseif($p->acked_at)<span class="badge ok plain">ACK</span>@else<span class="badge warn plain">aktif</span>@endif</td>
            </tr>
        @endforeach
        </tbody></table></div>
    </div></section>

    <section class="card"><div class="hd"><h2>Penyebab & tindakan</h2>@if($i->status === 'recovered')<span class="sub" style="color:var(--warn)">isi lalu tutup insiden</span>@endif</div><div class="bd">
        @can('manage-devices')
        <form method="post" action="{{ route('incidents.rca', $i) }}" class="stack">@csrf
            <div class="field"><label for="title">Judul</label><input class="input" id="title" name="title" value="{{ old('title', $i->title) }}" maxlength="255" required></div>
            <div class="field"><label for="cause">Penyebab</label><textarea class="input @error('cause') is-err @enderror" id="cause" name="cause" rows="3" placeholder="mis. PLN padam di Pit A, UPS habis setelah 40 menit">{{ old('cause', $i->cause) }}</textarea><x-field-error name="cause"/></div>
            <div class="field"><label for="action_taken">Tindakan</label><textarea class="input" id="action_taken" name="action_taken" rows="3" placeholder="mis. Genset dinyalakan, baterai UPS dijadwalkan diganti">{{ old('action_taken', $i->action_taken) }}</textarea></div>
            <div><button class="btn sm">Simpan</button></div>
        </form>
        @if($i->status !== 'closed')
        <form method="post" action="{{ route('incidents.close', $i) }}" class="stack" style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">@csrf
            @if($active)<label class="check"><input type="checkbox" name="force" value="1"><span>Tutup walau masih ada {{ $active }} problem aktif</span></label>@endif
            <div><button class="btn primary sm" @disabled(! $i->cause || ! $i->action_taken) title="{{ ! $i->cause || ! $i->action_taken ? 'Simpan penyebab & tindakan dulu' : '' }}">Tutup insiden</button></div>
        </form>
        @endif
        @else
        <dl class="facts"><dt>Penyebab</dt><dd style="white-space:pre-line">{{ $i->cause ?: 'tidak ada' }}</dd><dt>Tindakan</dt><dd style="white-space:pre-line">{{ $i->action_taken ?: 'tidak ada' }}</dd></dl>
        @endcan
    </div></section>
</div>

<div class="stack">
    @can('manage-devices')
    @if($i->status !== 'closed')
    <section class="card"><div class="hd"><h2>Tugaskan</h2></div><div class="bd">
        <form method="post" action="{{ route('incidents.take', $i) }}" style="display:flex;gap:8px">@csrf
            <select class="input" name="owner_id" aria-label="Penanggung jawab">@foreach($engineers as $e)<option value="{{ $e->id }}" @selected($i->owner_id === $e->id)>{{ $e->name }}</option>@endforeach</select>
            <button class="btn sm">Tugaskan</button></form>
    </div></section>
    @endif
    @endcan
    <section class="card"><div class="hd"><h2>Kronologi</h2></div>
        <div class="bd flush"><ul class="feed timeline">
            @foreach($i->notes as $n)
            <li><span class="ic {{ ['note' => 'info', 'status' => 'warn', 'problem' => 'crit', 'owner' => 'ok', 'system' => 'muted'][$n->kind] ?? 'muted' }}"><x-icon name="{{ ['note' => 'edit', 'status' => 'pulse', 'problem' => 'alert', 'owner' => 'users', 'system' => 'info'][$n->kind] ?? 'info' }}"/></span>
                <div class="msg" style="white-space:pre-line">{{ $n->body }}<small>{{ $n->user?->name ?? 'Sistem' }}</small></div>
                <time title="{{ $n->at->format('d/m/Y H:i:s') }}">{{ $n->at->format('d/m H:i') }}</time></li>
            @endforeach
        </ul></div>
        @can('manage-devices')
        <form method="post" action="{{ route('incidents.note', $i) }}" class="bd stack" style="border-top:1px solid var(--line)">@csrf
            <textarea class="input @error('body') is-err @enderror" name="body" rows="2" placeholder="Catatan: temuan di lapangan, langkah yang sudah dicoba" required maxlength="2000" aria-label="Catatan"></textarea><x-field-error name="body"/>
            <div><button class="btn sm">Tambah catatan</button></div>
        </form>
        @endcan
    </section>
</div>
</div>
@endsection
