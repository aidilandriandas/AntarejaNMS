@extends('layouts.app')
@section('title', $c->name)
@section('content')
@php($st = $c->state())
<div class="crumbs"><a href="{{ route('collectors.index') }}">Collector site</a> / {{ $c->name }}</div>
<div class="page-head">
    <div><h1>{{ $c->name }} <span class="badge {{ ['online' => 'ok', 'late' => 'crit', 'never' => 'warn', 'off' => ''][$st] }}">{{ $c->stateLabel() }}</span></h1>
        <p>{{ $c->site ? $c->site->code . ' (' . $c->site->name . ')' : 'Tanpa site' }}{{ $c->notes ? '. ' . $c->notes : '' }}</p></div>
</div>

@if($token)
<section class="card" style="margin-bottom:var(--space-xl)">
    <div class="hd"><h2>Token collector</h2><span class="sub">hanya ditampilkan sekali</span></div>
    <div class="bd stack">
        <div style="display:flex;gap:var(--space-xs);flex-wrap:wrap;align-items:center">
            <input class="input mono" value="{{ $token }}" readonly data-copy-src style="flex:1;min-width:260px" aria-label="Token collector site">
            <button type="button" class="btn primary" data-copy>Salin</button>
        </div>
    </div>
</section>
@endif

<div class="grid g-main">
<div class="stack">
<section class="card"><div class="hd"><h2>Koneksi</h2></div><div class="bd">
    <dl class="facts">
        <dt>Terakhir melapor</dt><dd>{{ $c->last_seen_at ? $c->last_seen_at->format('d/m/Y H.i.s') . ' (' . $c->last_seen_at->diffForHumans() . ')' : 'belum pernah' }}@if($c->last_ip) dari <span class="mono">{{ $c->last_ip }}</span>@endif</dd>
        <dt>Hasil terbaru diterima</dt><dd>{{ $c->newest_sample_at?->format('d/m/Y H.i.s') ?? 'belum ada' }}</dd>
        <dt>Antrean di site</dt><dd>{{ $c->queued === null ? 'tidak ada' : ($c->queued ? $c->queued . ' kiriman menunggu (sedang dikirim ulang)' : 'kosong') }}</dd>
        <dt>Selisih jam site</dt><dd>@if($c->clock_skew_s === null) tidak ada @elseif(abs($c->clock_skew_s) > 30)<span class="txt-warn">{{ $c->clock_skew_s }} detik</span>, waktu hasil dikoreksi otomatis. Atur NTP di site. @else {{ $c->clock_skew_s }} detik @endif</dd>
        <dt>Versi</dt><dd class="mono">{{ $c->version ?? 'tidak ada' }}</dd>
        <dt>Token</dt><dd class="mono">{{ $c->token_hint }}...</dd>
    </dl>
</div></section>

<section class="card"><div class="hd"><h2>Perangkat</h2><span class="sub">{{ $devices->count() }} perangkat</span></div><div class="bd flush">
    @if($c->site_id && $siteCandidates)
    <form method="post" action="{{ route('collectors.assign', $c) }}" class="bulkbar" style="padding:var(--space-sm) var(--space-md)" data-confirm="Pindahkan {{ $siteCandidates }} perangkat {{ $c->site->code }} ke collector ini?">@csrf
        <span class="muted">{{ $siteCandidates }} perangkat di {{ $c->site->code }} masih dipantau dari pusat.</span><button class="btn sm primary">Pindahkan ke collector ini</button>
    </form>
    @endif
    @if($devices->isEmpty())<div class="empty">Belum ada perangkat. Pilih collector ini di form perangkat (Dipantau dari), lewat ubah massal di daftar Perangkat, atau tombol di atas.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Perangkat</th><th>Status</th><th>Site</th><th>SNMP</th></tr></thead>
        <tbody>
        @foreach($devices as $d)
            <tr class="{{ ! $d->enabled ? 'is-off' : ($d->status === 'down' ? 'is-down' : '') }}">
                <td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a><small class="mono">{{ $d->host }}</small></td>
                <td><x-status :device="$d"/></td>
                <td>{{ $d->site?->code ?? 'tidak ada' }}</td>
                <td>@if(! $d->snmp_enabled)<span class="muted">tidak ada</span>@elseif($d->snmp_ok === null)<span class="badge">menunggu</span>@elseif($d->snmp_ok)<span class="badge ok plain">v{{ $d->snmp_version }}</span>@else<span class="badge warn">gagal</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div></section>

<section class="card"><div class="hd"><h2>Pasang di site</h2></div><div class="bd notes">
    <ol class="help-steps">
        <li>Siapkan VM atau mini PC Linux di site dengan Docker, yang bisa menjangkau perangkat lokal dan alamat <code>{{ $central }}</code> lewat HTTPS.</li>
        <li>Salin folder <code>deploy/site</code> dari paket ANTAREJA ke mesin itu, lalu buat berkas <code>.env</code> di folder tersebut:
<pre class="code">ANTAREJA_CENTRAL_URL={{ $central }}
ANTAREJA_SITE_TOKEN={{ $token ?? 'isi dengan token collector ini' }}
# bila sertifikat pusat dari CA internal kantor:
# ANTAREJA_CENTRAL_CA=/certs/ca.pem</pre></li>
        <li>Jalankan <code>docker compose up -d</code>. Dalam satu menit status di halaman ini berubah menjadi terhubung.</li>
    </ol>
    <p class="muted">Token hanya untuk collector ini. Bila mesin site hilang atau token bocor, klik Ganti token; token lama langsung ditolak.</p>
</div></section>
</div>

<div class="stack">
<section class="card"><div class="hd"><h2>Ubah</h2></div><div class="bd">
    <form method="post" action="{{ route('collectors.update', $c) }}" class="form">@csrf @method('PUT')
        <div class="field"><label for="c-name">Nama</label><input class="input @error('name') is-err @enderror" id="c-name" name="name" value="{{ old('name', $c->name) }}" maxlength="64" required><x-field-error name="name"/></div>
        <div class="field"><label for="c-site">Site</label><select class="input" id="c-site" name="site_id"><option value="">Tanpa site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(old('site_id', $c->site_id) == $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach</select></div>
        <div class="field"><label for="c-notes">Catatan</label><input class="input" id="c-notes" name="notes" value="{{ old('notes', $c->notes) }}" maxlength="1000"></div>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $c->enabled))><span>Aktif<small>Collector nonaktif ditolak saat menghubungi pusat.</small></span></label>
        <div class="form-foot"><button class="btn primary">Simpan</button></div>
    </form>
</div></section>
<section class="card"><div class="hd"><h2>Token dan hapus</h2></div><div class="bd stack-sm">
    <form method="post" action="{{ route('collectors.rotate', $c) }}" data-confirm="Ganti token {{ $c->name }}? Collector di site berhenti terhubung sampai token baru dipasang.">@csrf<button class="btn"><x-icon name="key"/>Ganti token</button></form>
    <form method="post" action="{{ route('collectors.destroy', $c) }}" data-confirm="Hapus collector {{ $c->name }}? {{ $devices->count() }} perangkatnya kembali dipantau dari server pusat.">@csrf @method('DELETE')<button class="btn danger">Hapus collector</button></form>
</div></section>
</div>
</div>
@endsection
