@extends('layouts.app')
@section('title', 'Kelola kamera')
@section('content')
<div class="crumbs"><a href="{{ route('cctv.index') }}">CCTV</a> / Kelola</div>
<div class="page-head"><div><h1>Kelola kamera</h1><p>Gambar setiap kamera dicek otomatis tiap 15 menit (gelap, tertutup/buram, beku, tanpa sinyal); kamera yang terkait perangkat menjadi problem bila bermasalah. Untuk NVR Hikvision: satu baris per kanal dengan host NVR. Path bawaan Hikvision: <code>/Streaming/Channels/{ch}01</code> (main) dan <code>{ch}02</code> (sub).</p></div></div>
<div class="grid g-main">
    <section class="card"><div class="hd"><h2>Kamera</h2><span class="sub">{{ $cams->count() }}</span></div><div class="bd flush">
        @if($cams->isEmpty())<div class="empty">Belum ada kamera.</div>@else
        <div class="table-wrap"><table class="t">
            <thead><tr><th>Nama</th><th>Sumber</th><th>Site</th><th>Gambar</th><th></th></tr></thead>
            <tbody>
            @foreach($cams as $c)
                <tr class="{{ $c->enabled ? '' : 'is-off' }}">
                    <td class="name">{{ $c->name }}<small>{{ $c->device?->name ?? 'tanpa perangkat' }}{{ $c->enabled ? '' : ' · nonaktif' }}</small></td>
                    <td class="mono" style="font-size:12.5px">{{ $c->host }}:{{ $c->rtsp_port }} · kanal {{ $c->channel }}<div class="muted">{{ $c->username ? 'user ' . $c->username : 'tanpa login' }}</div></td>
                    <td>{{ $c->site?->code ?? '–' }}</td>
                    <td>@if($h = $c->healthMeta())<span class="badge {{ $h[1] }}" title="{{ $c->health_detail }}">{{ $h[0] }}</span>
                        <div class="muted" style="font-size:12px">{{ $c->health_at?->diffForHumans() }}{{ $c->brightness !== null ? ' · terang ' . round($c->brightness) . ' · detail ' . round($c->detail_score) : '' }}</div>
                        @else<span class="muted">belum dicek</span>@endif</td>
                    <td class="right nowrap">
                        <details class="ack"><summary class="btn sm">Ubah</summary>
                            <form method="post" action="{{ route('cctv.update', $c) }}" class="card ack-pop form" style="width:340px">@csrf @method('PUT')
                                <div class="field"><label>Nama</label><input class="input" name="name" value="{{ $c->name }}" required></div>
                                <div class="row"><div class="field"><label>Host</label><input class="input mono" name="host" value="{{ $c->host }}" required></div>
                                    <div class="field"><label>Port</label><input class="input" type="number" name="rtsp_port" value="{{ $c->rtsp_port }}"></div></div>
                                <div class="row"><div class="field"><label>Kanal</label><input class="input" type="number" name="channel" value="{{ $c->channel }}"></div>
                                    <div class="field"><label>Urutan</label><input class="input" type="number" name="sort" value="{{ $c->sort }}"></div></div>
                                <div class="field"><label>Path main</label><input class="input mono" name="path_main" value="{{ $c->path_main }}"></div>
                                <div class="field"><label>Path sub</label><input class="input mono" name="path_sub" value="{{ $c->path_sub }}"></div>
                                <div class="row"><div class="field"><label>User</label><input class="input" name="username" value="{{ $c->username }}" autocomplete="off"></div>
                                    <div class="field"><label>Password</label><input class="input" type="password" name="password" placeholder="{{ $c->password_enc ? '•••• (tetap)' : '' }}" autocomplete="new-password"></div></div>
                                <input type="hidden" name="device_id" value="{{ $c->device_id }}"><input type="hidden" name="site_id" value="{{ $c->site_id }}">
                                <label class="check"><input type="checkbox" name="enabled" value="1" @checked($c->enabled)><span>Aktif</span></label>
                                <button class="btn primary sm">Simpan</button>
                            </form></details>
                        <form method="post" action="{{ route('cctv.destroy', $c) }}" style="display:inline" data-confirm="Hapus kamera {{ $c->name }}?">@csrf @method('DELETE')<button class="btn sm danger" aria-label="Hapus"><x-icon name="trash"/></button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>@endif
    </div></section>
    <section class="card"><div class="hd"><h2>Tambah kamera / kanal NVR</h2></div><div class="bd">
        <form method="post" action="{{ route('cctv.store') }}" class="form" data-cam-form>
            @csrf
            <div class="field"><label for="device_id">Perangkat (NVR / kamera) <span class="muted">(opsional)</span></label>
                <select class="input" id="device_id" name="device_id" data-cam-device>
                    <option value="">— tidak terkait —</option>
                    @foreach($devices as $d)<option value="{{ $d->id }}" data-host="{{ $d->host }}" data-site="{{ $d->site_id }}" @selected(old('device_id') == $d->id)>{{ $d->name }} ({{ $d->host }})</option>@endforeach
                </select><div class="hint">Host & site terisi otomatis. Bila perangkat down, kameranya ditandai.</div></div>
            <div class="field"><label for="name">Nama</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name') }}" placeholder="CAM Pit A" required><x-field-error name="name"/>
                <div class="hint">Untuk beberapa kanal, nomor kanal ditambahkan otomatis (CAM Pit A 01, 02, …).</div></div>
            <div class="row"><div class="field"><label for="host">Host RTSP</label><input class="input mono @error('host') is-err @enderror" id="host" name="host" value="{{ old('host') }}" required><x-field-error name="host"/></div>
                <div class="field"><label for="rtsp_port">Port</label><input class="input" type="number" id="rtsp_port" name="rtsp_port" value="{{ old('rtsp_port', 554) }}"></div></div>
            <div class="row"><div class="field"><label for="channel_from">Kanal dari</label><input class="input" type="number" min="1" max="256" id="channel_from" name="channel_from" value="{{ old('channel_from', 1) }}"></div>
                <div class="field"><label for="channel_to">sampai</label><input class="input @error('channel_to') is-err @enderror" type="number" min="1" max="256" id="channel_to" name="channel_to" value="{{ old('channel_to', 1) }}"><x-field-error name="channel_to"/></div></div>
            <input type="hidden" name="channel" value="1">
            <div class="row"><div class="field"><label for="username">User RTSP</label><input class="input" id="username" name="username" value="{{ old('username') }}" autocomplete="off"></div>
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
</div>
@push('scripts')<script>
document.querySelector('[data-cam-device]')?.addEventListener('change', (e) => {
    const o = e.target.selectedOptions[0];
    const host = document.getElementById('host');
    if (o?.dataset.host && !host.value) host.value = o.dataset.host;
    document.querySelector('[data-cam-site]').value = o?.dataset.site || '';
    const name = document.getElementById('name');
    if (o?.value && !name.value) name.value = o.textContent.replace(/\s*\(.*\)$/, '');
});
</script>@endpush
@endsection
