@extends('layouts.app')
@section('title', 'Tautan status')
@section('content')
<div class="page-head">
    <div><h1>Tautan status</h1><p>Halaman status hanya-baca untuk manajemen atau pihak terkait tanpa akun ANTAREJA: status per site, ketersediaan bulan ini, jumlah problem. Tidak menampilkan IP, konfigurasi, atau kredensial. Siapa pun yang memegang tautan bisa membukanya — cabut bila bocor.</p></div>
</div>

@if($newUrl)
<section class="card" style="margin-bottom:16px;border-color:var(--accent)">
    <div class="hd"><h2>Tautan baru</h2><span class="sub">hanya ditampilkan sekali</span></div>
    <div class="bd" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input class="input mono" value="{{ $newUrl }}" readonly data-copy-src style="flex:1;min-width:260px">
        <button type="button" class="btn primary" data-copy>Salin</button>
    </div>
</section>
@endif

<div class="grid g-main">
<section class="card"><div class="hd"><h2>Tautan</h2></div>
    <div class="bd flush">
    @if($links->isEmpty())<div class="empty">Belum ada tautan status.</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Nama</th><th>Cakupan</th><th>Status</th><th>Dibuka</th><th></th></tr></thead>
        <tbody>
        @foreach($links as $l)
            @php($expired = $l->expires_at && now()->greaterThan($l->expires_at))
            <tr class="{{ $l->revoked_at || $expired ? 'is-off' : '' }}">
                <td class="name"><b>{{ $l->name }}</b><small>{{ $l->user_name }} · {{ \Illuminate\Support\Carbon::parse($l->created_at)->format('d/m/Y') }}{{ $l->show_devices ? ' · nama perangkat down' : '' }}</small></td>
                <td>{{ $l->site_code ?? 'Semua site' }}</td>
                <td>@if($l->revoked_at)<span class="badge">dicabut</span>@elseif($expired)<span class="badge">kedaluwarsa</span>@else<span class="badge ok">aktif</span><small class="muted" style="display:block">{{ $l->expires_at ? 's.d. ' . \Illuminate\Support\Carbon::parse($l->expires_at)->format('d/m/Y') : 'tanpa batas' }}</small>@endif</td>
                <td class="muted">{{ $l->use_count }}×@if($l->last_used_at)<small>terakhir {{ \Illuminate\Support\Carbon::parse($l->last_used_at)->diffForHumans() }}</small>@endif</td>
                <td>@unless($l->revoked_at || $expired)<form method="post" action="{{ route('status.links.revoke', $l->id) }}" onsubmit="return confirm('Cabut tautan ini? Siapa pun yang memegangnya tidak bisa membuka lagi.')">@csrf<button class="btn sm danger">Cabut</button></form>@endunless</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
</section>

<section class="card"><div class="hd"><h2>Tautan baru</h2></div><div class="bd">
    <form method="post" action="{{ route('status.links.store') }}" class="stack">@csrf
        <div class="field"><label for="name">Nama / penerima</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name') }}" placeholder="Manajemen site — layar ruang direksi" required maxlength="128"><x-field-error name="name"/></div>
        <div class="field"><label for="site_id">Cakupan</label><select class="input" id="site_id" name="site_id"><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(old('site_id') == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach</select></div>
        <div class="field"><label for="days">Berlaku</label><select class="input" id="days" name="days">
            @foreach([30 => '30 hari', 90 => '90 hari', 180 => '6 bulan', 365 => '1 tahun', 7 => '7 hari', 0 => 'Tanpa batas (cabut manual)'] as $k => $v)<option value="{{ $k }}" @selected((int) old('days', 90) === $k)>{{ $v }}</option>@endforeach</select></div>
        <label class="check"><input type="checkbox" name="show_devices" value="1" @checked(old('show_devices'))><span>Tampilkan nama perangkat yang down<small>Tanpa IP. Biarkan mati bila tautan untuk pihak luar.</small></span></label>
        <div><button class="btn primary">Buat tautan</button></div>
    </form>
</div></section>
</div>
@endsection
