@extends('layouts.app')
@section('title', 'Token API')
@section('content')
<div class="page-head">
    <div><h1>Token API</h1><p>Akses baca-saja untuk sistem lain (SOC, dashboard manajemen, skrip): perangkat, site, problem, insiden, kejadian, anomali, dan layanan dalam format JSON. API ini tidak bisa mengubah data apa pun dan tidak pernah mengirim kredensial perangkat atau kanal.</p></div>
</div>

@if($newToken)
<section class="card" style="margin-bottom:var(--space-xl)">
    <div class="hd"><h2>Token baru</h2><span class="sub">hanya ditampilkan sekali</span></div>
    <div class="bd stack">
        <div style="display:flex;gap:var(--space-xs);flex-wrap:wrap;align-items:center">
            <input class="input mono" value="{{ $newToken }}" readonly data-copy-src style="flex:1;min-width:260px" aria-label="Token API">
            <button type="button" class="btn primary" data-copy>Salin</button>
        </div>
        <p class="hint muted" style="margin:0">Simpan di pengelola rahasia sistem pemakainya. Contoh: <code>curl -H "Authorization: Bearer {{ $newToken }}" {{ $base }}/problems</code></p>
    </div>
</section>
@endif

<div class="grid g-main">
<section class="card"><div class="hd"><h2>Token</h2></div>
    <div class="bd flush">
    @if($tokens->isEmpty())<div class="empty">Belum ada token API.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Nama</th><th>Batasan</th><th>Status</th><th>Dipakai</th><th><span class="sr-only">Aksi</span></th></tr></thead>
        <tbody>
        @foreach($tokens as $t)
            @php($expired = $t->expires_at && now()->greaterThan($t->expires_at))
            <tr class="{{ $t->revoked_at || $expired ? 'is-off' : '' }}">
                <td class="name"><b>{{ $t->name }}</b><small><span class="mono">{{ $t->prefix }}</span>, {{ $t->user_name }}, {{ \Illuminate\Support\Carbon::parse($t->created_at)->format('d/m/Y') }}</small></td>
                <td>{{ $t->allowed_ips ? str_replace(',', ', ', $t->allowed_ips) : 'semua IP' }}</td>
                <td>@if($t->revoked_at)<span class="badge">dicabut</span>@elseif($expired)<span class="badge">kedaluwarsa</span>@else<span class="badge ok">aktif</span><small class="muted" style="display:block">{{ $t->expires_at ? 'sampai ' . \Illuminate\Support\Carbon::parse($t->expires_at)->format('d/m/Y') : 'tanpa batas waktu' }}</small>@endif</td>
                <td class="muted">{{ number_format($t->use_count, 0, ',', '.') }} kali @if($t->last_used_at)<small style="display:block">terakhir {{ \Illuminate\Support\Carbon::parse($t->last_used_at)->diffForHumans() }} dari {{ $t->last_ip }}</small>@endif</td>
                <td class="right">@unless($t->revoked_at || $expired)<form method="post" action="{{ route('apitokens.revoke', $t->id) }}" data-confirm="Cabut token {{ $t->name }}? Sistem yang memakainya langsung ditolak.">@csrf<button class="btn sm danger">Cabut</button></form>@endunless</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>

<div class="stack">
<section class="card"><div class="hd"><h2>Token baru</h2></div><div class="bd">
    <form method="post" action="{{ route('apitokens.store') }}" class="form">@csrf
        <div class="field"><label for="name">Nama / pemakai</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name') }}" placeholder="SOC, dashboard direksi" required maxlength="128"><x-field-error name="name"/></div>
        <div class="field"><label for="days">Berlaku</label><select class="input" id="days" name="days">
            @foreach([90 => '90 hari', 180 => '6 bulan', 365 => '1 tahun', 30 => '30 hari', 0 => 'Tanpa batas (cabut manual)'] as $k => $v)<option value="{{ $k }}" @selected((int) old('days', 365) === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="field"><label for="allowed_ips">Hanya dari IP <span class="muted">(opsional)</span></label><input class="input mono @error('allowed_ips') is-err @enderror" id="allowed_ips" name="allowed_ips" value="{{ old('allowed_ips') }}" placeholder="10.10.5.20, 10.20.0.0/24">
            <div class="hint">Kosong berarti dari mana saja. Isi IP server SOC supaya token yang bocor tidak bisa dipakai dari tempat lain.</div><x-field-error name="allowed_ips"/></div>
        <div class="form-foot"><button class="btn primary"><x-icon name="key"/>Buat token</button></div>
    </form>
</div></section>
<section class="card"><div class="hd"><h2>Endpoint</h2></div><div class="bd notes">
    <p>Semua <b>GET</b> dengan header <code>Authorization: Bearer &lt;token&gt;</code>, dasar <code>{{ $base }}</code>.</p>
    <p><code>/sites</code>, <code>/devices</code> (saring <code>status</code>, <code>site</code>, <code>category</code>), <code>/devices/{id}</code> (beserta port dan problem terbuka), <code>/problems</code> (<code>status</code> open, resolved, all; <code>min_severity</code>; <code>since</code>), <code>/incidents</code>, <code>/events</code> (<code>since</code>, <code>kind</code>), <code>/anomalies</code>, <code>/services</code>.</p>
    <p>Daftar dibagi per halaman: <code>limit</code> sampai 500, lanjutkan dengan <code>after_id</code> dari <code>next_after_id</code>. Batas 120 permintaan per menit. Rincian di <code>docs/API.md</code>.</p>
</div></section>
</div>
</div>
@endsection
