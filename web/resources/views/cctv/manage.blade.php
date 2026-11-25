@extends('layouts.app')
@section('title', 'Kelola kamera')
@section('content')
<div class="crumbs"><a href="{{ route('cctv.index') }}">CCTV</a> / Kelola</div>
<div class="page-head"><div><h1>Kelola kamera</h1><p>Gambar setiap kamera dicek otomatis tiap 15 menit (gelap, tertutup/buram, beku, tanpa sinyal); kamera yang terkait perangkat menjadi problem bila bermasalah. Untuk NVR Hikvision: satu baris per kanal dengan host NVR. Path bawaan Hikvision: <code>/Streaming/Channels/{ch}01</code> (main) dan <code>{ch}02</code> (sub).</p></div>
    <div class="actions"><a class="btn" href="#profil">Profil login</a><a class="btn primary" href="#massal"><x-icon name="plus"/>Tambah banyak{{ $candidates->count() ? ' (' . $candidates->count() . ')' : '' }}</a></div></div>
<div class="grid g-main">
    <section class="card"><div class="hd"><h2>Kamera</h2><span class="sub">{{ $cams->count() }}</span></div><div class="bd flush">
        @if($cams->isEmpty())<div class="empty">Belum ada kamera.</div>@else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Nama</th><th>Sumber</th><th>Site</th><th>Gambar</th><th></th></tr></thead>
            <tbody>
            @foreach($cams as $c)
                <tr class="{{ $c->enabled ? '' : 'is-off' }} {{ $edit?->id === $c->id ? 'is-sel' : '' }}">
                    <td class="name">{{ $c->name }}<small>{{ $c->device?->name ?? 'tanpa perangkat' }}{{ $c->enabled ? '' : ', nonaktif' }}</small></td>
                    <td class="mono" style="font-size:12.5px">{{ $c->host }}:{{ $c->rtsp_port }}, kanal {{ $c->channel }}<div class="muted">{{ $c->profile ? 'profil ' . $c->profile->name : ($c->username ? 'user ' . $c->username : 'tanpa login') }}</div></td>
                    <td>{{ $c->site?->code ?? 'tidak ada' }}</td>
                    <td>@if($h = $c->healthMeta())<span class="badge {{ $h[1] }}" title="{{ $c->health_detail }}">{{ $h[0] }}</span>
                        <div class="muted" style="font-size:12px">{{ $c->health_at?->diffForHumans() }}{{ $c->brightness !== null ? ', terang ' . round($c->brightness) . ', detail ' . round($c->detail_score) : '' }}</div>
                        @else<span class="muted">belum dicek</span>@endif</td>
                    <td class="right nowrap">
                        <a class="btn sm" href="{{ route('cctv.manage', ['ubah' => $c->id]) }}#ubah"><x-icon name="edit"/>Ubah</a>
                        <form method="post" action="{{ route('cctv.destroy', $c) }}" style="display:inline" data-confirm="Hapus kamera {{ $c->name }}?">@csrf @method('DELETE')<button class="btn sm danger" aria-label="Hapus"><x-icon name="trash"/></button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>@endif
    </div></section>
    <div class="stack">
    @if($edit)
    <section class="card" id="ubah"><div class="hd"><h2>Ubah {{ $edit->name }}</h2><a class="sub" href="{{ route('cctv.manage') }}">Batal ubah</a></div><div class="bd">
        <form method="post" action="{{ route('cctv.update', $edit) }}" class="form">@csrf @method('PUT')
            <div class="field"><label for="e-name">Nama</label><input class="input @error('name') is-err @enderror" id="e-name" name="name" value="{{ old('name', $edit->name) }}" maxlength="128" required><x-field-error name="name"/></div>
            <div class="row">
                <div class="field"><label for="e-device">Perangkat (NVR / kamera)</label><select class="input" id="e-device" name="device_id"><option value="">Tidak terkait</option>
                    @foreach($devices as $d)<option value="{{ $d->id }}" @selected((int) old('device_id', $edit->device_id) === $d->id)>{{ $d->name }} ({{ $d->host }})</option>@endforeach</select></div>
                <div class="field"><label for="e-site">Site</label><select class="input" id="e-site" name="site_id"><option value="">Tidak ada</option>
                    @foreach($sites as $st)<option value="{{ $st->id }}" @selected((int) old('site_id', $edit->site_id) === $st->id)>{{ $st->code }}</option>@endforeach</select></div>
            </div>
            <div class="row"><div class="field"><label for="e-host">Host RTSP</label><input class="input mono @error('host') is-err @enderror" id="e-host" name="host" value="{{ old('host', $edit->host) }}" required><x-field-error name="host"/></div>
                <div class="field"><label for="e-port">Port</label><input class="input @error('rtsp_port') is-err @enderror" type="number" min="1" max="65535" id="e-port" name="rtsp_port" value="{{ old('rtsp_port', $edit->rtsp_port) }}" required><x-field-error name="rtsp_port"/></div></div>
            <div class="row"><div class="field"><label for="e-ch">Kanal</label><input class="input @error('channel') is-err @enderror" type="number" min="1" max="256" id="e-ch" name="channel" value="{{ old('channel', $edit->channel) }}" required><x-field-error name="channel"/></div>
                <div class="field"><label for="e-sort">Urutan di grid</label><input class="input" type="number" id="e-sort" name="sort" value="{{ old('sort', $edit->sort) }}"></div></div>
            <div class="field"><label for="e-main">Path main</label><input class="input mono @error('path_main') is-err @enderror" id="e-main" name="path_main" value="{{ old('path_main', $edit->path_main) }}" required><x-field-error name="path_main"/></div>
            <div class="field"><label for="e-sub">Path sub</label><input class="input mono @error('path_sub') is-err @enderror" id="e-sub" name="path_sub" value="{{ old('path_sub', $edit->path_sub) }}" required><x-field-error name="path_sub"/>
                <div class="hint"><code>{ch}</code> diganti nomor kanal.</div></div>
            <div class="field"><label for="e-profile">Login</label><select class="input" id="e-profile" name="profile_id"><option value="">Isi sendiri</option>
                @foreach($profiles as $pf)<option value="{{ $pf->id }}" @selected((int) old('profile_id', $edit->profile_id) === $pf->id)>Profil {{ $pf->name }}{{ $pf->username ? ' (' . $pf->username . ')' : '' }}</option>@endforeach</select></div>
            <div class="row" data-show-if="profile_id="><div class="field"><label for="e-user">User RTSP</label><input class="input" id="e-user" name="username" value="{{ old('username', $edit->username) }}" autocomplete="off"></div>
                <div class="field"><label for="e-pass">Password</label><input class="input" type="password" id="e-pass" name="password" placeholder="{{ $edit->password_enc ? 'tersimpan' : '' }}" autocomplete="new-password"></div></div>
            <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $edit->enabled))><span>Aktif<small>Kamera nonaktif tidak tampil di grid dan tidak dicek gambarnya.</small></span></label>
            <div class="form-foot"><a class="btn" href="{{ route('cctv.manage') }}">Batal</a><button class="btn primary">Simpan</button></div>
        </form>
    </div></section>
    @else
    <section class="card"><div class="hd"><h2>Tambah kamera / kanal NVR</h2></div><div class="bd">
        <form method="post" action="{{ route('cctv.store') }}" class="form" data-cam-form>
            @csrf
            <div class="field"><label for="device_id">Perangkat (NVR / kamera) <span class="muted">(opsional)</span></label>
                <select class="input" id="device_id" name="device_id" data-cam-device>
                    <option value="">Tidak terkait</option>
                    @foreach($devices as $d)<option value="{{ $d->id }}" data-host="{{ $d->host }}" data-site="{{ $d->site_id }}" @selected(old('device_id') == $d->id)>{{ $d->name }} ({{ $d->host }})</option>@endforeach
                </select><div class="hint">Host & site terisi otomatis. Bila perangkat down, kameranya ditandai.</div></div>
            <div class="field"><label for="name">Nama</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name') }}" placeholder="CAM Pit A" required><x-field-error name="name"/>
                <div class="hint">Untuk beberapa kanal, nomor kanal ditambahkan otomatis (CAM Pit A 01, 02, dan seterusnya).</div></div>
            <div class="row"><div class="field"><label for="host">Host RTSP</label><input class="input mono @error('host') is-err @enderror" id="host" name="host" value="{{ old('host') }}" required><x-field-error name="host"/></div>
                <div class="field"><label for="rtsp_port">Port</label><input class="input" type="number" id="rtsp_port" name="rtsp_port" value="{{ old('rtsp_port', 554) }}"></div></div>
            <div class="row"><div class="field"><label for="channel_from">Kanal dari</label><input class="input" type="number" min="1" max="256" id="channel_from" name="channel_from" value="{{ old('channel_from', 1) }}"></div>
                <div class="field"><label for="channel_to">sampai</label><input class="input @error('channel_to') is-err @enderror" type="number" min="1" max="256" id="channel_to" name="channel_to" value="{{ old('channel_to', 1) }}"><x-field-error name="channel_to"/></div></div>
            <input type="hidden" name="channel" value="1">
            <div class="field"><label for="profile_id">Login</label><select class="input" id="profile_id" name="profile_id"><option value="">Isi sendiri</option>
                @foreach($profiles as $pf)<option value="{{ $pf->id }}" @selected((int) old('profile_id') === $pf->id)>Profil {{ $pf->name }}{{ $pf->username ? ' (' . $pf->username . ')' : '' }}</option>@endforeach</select>
                <div class="hint">Pakai profil supaya user dan password tidak diketik ulang; ganti password cukup di profil.</div></div>
            <div class="row" data-show-if="profile_id="><div class="field"><label for="username">User RTSP</label><input class="input" id="username" name="username" value="{{ old('username') }}" autocomplete="off"></div>
                <div class="field"><label for="password">Password</label><input class="input" type="password" id="password" name="password" autocomplete="new-password"></div></div>
            <div class="hint" style="margin-top:-8px">Disarankan user khusus di NVR dengan hak <em>Live View</em> saja. Password disimpan terenkripsi.</div>
            <details><summary class="muted" style="cursor:pointer">Path stream (bukan Hikvision?)</summary>
                <div class="field" style="margin-top:8px"><label for="path_main">Path main</label><input class="input mono" id="path_main" name="path_main" value="{{ old('path_main', '/Streaming/Channels/{ch}01') }}"></div>
                <div class="field"><label for="path_sub">Path sub</label><input class="input mono" id="path_sub" name="path_sub" value="{{ old('path_sub', '/Streaming/Channels/{ch}02') }}"></div>
                <div class="hint"><code>{ch}</code> diganti nomor kanal.</div></details>
            <input type="hidden" name="site_id" value="{{ old('site_id') }}" data-cam-site>
            <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Tambah</button></div>
        </form>
    </div></section>
    @endif

    <section class="card" id="profil"><div class="hd"><h2>Profil login</h2>@if($editProfile)<a class="sub" href="{{ route('cctv.manage') }}#profil">Batal ubah</a>@endif</div><div class="bd">
        @if($profiles->isNotEmpty())
        <ul class="impact-list" style="margin-top:0">
            @foreach($profiles as $pf)
            <li class="row-actions" style="justify-content:space-between"><span><b>{{ $pf->name }}</b> <span class="muted">{{ $pf->username ?: 'tanpa user' }}, port {{ $pf->rtsp_port }}, {{ $pf->cameras_count }} kamera</span></span>
                <span class="row-actions nowrap"><a class="btn sm" href="{{ route('cctv.manage', ['profil' => $pf->id]) }}#profil"><x-icon name="edit"/>Ubah</a>
                <form method="post" action="{{ route('cctv.profiles.destroy', $pf) }}" data-confirm="Hapus profil {{ $pf->name }}?">@csrf @method('DELETE')<button class="btn sm" aria-label="Hapus profil {{ $pf->name }}"><x-icon name="trash"/></button></form></span></li>
            @endforeach
        </ul>
        @endif
        @php($pp = $editProfile ?? new \App\Models\CameraProfile(['rtsp_port' => 554, 'path_main' => '/Streaming/Channels/{ch}01', 'path_sub' => '/Streaming/Channels/{ch}02']))
        <form method="post" action="{{ $editProfile ? route('cctv.profiles.update', $editProfile) : route('cctv.profiles.store') }}" class="form">@csrf @if($editProfile) @method('PUT') @endif
            <div class="field"><label for="p-name">{{ $editProfile ? 'Ubah profil' : 'Profil baru' }}</label><input class="input @error('name') is-err @enderror" id="p-name" name="name" maxlength="80" required value="{{ old('name', $pp->name) }}" placeholder="Hikvision Mess"><x-field-error name="name"/></div>
            <div class="row"><div class="field"><label for="p-user">User RTSP</label><input class="input" id="p-user" name="username" maxlength="64" value="{{ old('username', $pp->username) }}" autocomplete="off"></div>
                <div class="field"><label for="p-pass">Password</label><input class="input" type="password" id="p-pass" name="password" autocomplete="new-password" placeholder="{{ $pp->password_enc ? 'tersimpan' : '' }}"></div></div>
            <details @if($editProfile) open @endif><summary class="muted" style="cursor:pointer">Port dan path (bukan Hikvision?)</summary>
                <div class="field" style="margin-top:8px"><label for="p-port">Port RTSP</label><input class="input" type="number" min="1" max="65535" id="p-port" name="rtsp_port" value="{{ old('rtsp_port', $pp->rtsp_port) }}"></div>
                <div class="field"><label for="p-main">Path main</label><input class="input mono @error('path_main') is-err @enderror" id="p-main" name="path_main" value="{{ old('path_main', $pp->path_main) }}"><x-field-error name="path_main"/></div>
                <div class="field"><label for="p-sub">Path sub</label><input class="input mono @error('path_sub') is-err @enderror" id="p-sub" name="path_sub" value="{{ old('path_sub', $pp->path_sub) }}"><x-field-error name="path_sub"/></div></details>
            <div class="hint">Password disimpan terenkripsi dan tidak pernah tampil lagi. Mengubah password profil langsung berlaku untuk semua kamera yang memakainya.</div>
            <div class="form-foot"><button class="btn primary">{{ $editProfile ? 'Simpan profil' : 'Buat profil' }}</button></div>
        </form>
    </div></section>
    </div>
</div>

<section class="card" id="massal" style="margin-top:var(--space-md)"><div class="hd"><h2>Tambah banyak dari perangkat</h2><span class="sub">{{ $candidates->count() }} kamera IP belum ditambahkan</span></div><div class="bd">
    @if($candidates->isEmpty())
        <div class="empty">Semua perangkat berkategori Kamera CCTV sudah ada di daftar kamera.</div>
    @elseif($profiles->isEmpty())
        <div class="empty">Buat profil login dulu (kartu Profil login di atas), lalu pilih kamera di sini.</div>
    @else
    <form method="post" action="{{ route('cctv.bulk') }}" class="form" data-pick-list>@csrf
        <div class="filters" style="padding:0">
            <input class="input" type="search" placeholder="Saring nama atau IP, mis. MESS" aria-label="Saring kamera" data-pick-filter>
            <button type="button" class="btn sm" data-pick-all>Centang yang tampil</button>
            <button type="button" class="btn sm" data-pick-none>Kosongkan</button>
            <span class="muted" data-pick-count>0 dipilih</span>
        </div>
        <div class="pick-list">
            @foreach($candidates as $c)
            <label class="check" data-pick-row data-text="{{ mb_strtolower($c->name . ' ' . $c->host . ' ' . ($c->site?->code ?? '')) }}"><input type="checkbox" name="devices[]" value="{{ $c->id }}"><span>{{ $c->name }}<small class="mono">{{ $c->host }}{{ $c->site ? ', ' . $c->site->code : '' }}</small></span></label>
            @endforeach
        </div>
        <div class="row r3">
            <div class="field"><label for="b-profile">Profil login</label><select class="input @error('profile_id') is-err @enderror" id="b-profile" name="profile_id" required>
                @foreach($profiles as $pf)<option value="{{ $pf->id }}">{{ $pf->name }}{{ $pf->username ? ' (' . $pf->username . ')' : '' }}</option>@endforeach</select><x-field-error name="profile_id"/></div>
            <div class="field"><label for="b-ch">Kanal</label><input class="input" type="number" min="1" max="256" id="b-ch" name="channel" value="1"><div class="hint">Kamera IP langsung: 1.</div></div>
        </div>
        <x-field-error name="devices"/>
        <p class="hint muted" style="margin:0">Nama, IP, dan site diambil dari perangkat; port dan path dari profil. Kamera yang sudah ada dilewati.</p>
        <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Tambahkan kamera terpilih</button></div>
    </form>
    @endif
</div></section>

@endsection
