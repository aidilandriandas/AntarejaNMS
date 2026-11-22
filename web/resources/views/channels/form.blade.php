@extends('layouts.app')
@php($editing = $channel->exists)
@php($t = $channel->type)
@section('title', $editing ? 'Ubah kanal' : 'Tambah kanal')
@section('content')
<div class="crumbs"><a href="{{ route('channels.index') }}">Notifikasi</a> / {{ $editing ? $channel->name : 'Tambah' }}</div>
<div class="page-head"><div><h1>{{ $editing ? 'Ubah kanal' : 'Tambah kanal' }}, {{ \App\Models\NotifyChannel::TYPES[$t] }}</h1></div></div>
<div class="grid g-main">
<section class="card"><div class="bd">
<form method="post" action="{{ $editing ? route('channels.update', $channel) : route('channels.store') }}" class="form" id="channel-form">
    @csrf @if($editing) @method('PUT') @endif
    <input type="hidden" name="type" value="{{ $t }}">
    <div class="field"><label for="name">Nama kanal</label><input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name', $channel->name) }}" placeholder="{{ $t === 'telegram' ? 'Grup Telegram NOC' : ($t === 'email' ? 'Email tim jaringan' : 'WhatsApp gateway') }}" required><x-field-error name="name"/></div>

    @if($t === 'telegram')
        <div class="field"><label for="bot_token">Token bot</label>
            <input class="input mono @error('cfg.bot_token') is-err @enderror" id="bot_token" name="cfg[bot_token]" type="password" autocomplete="off" placeholder="{{ ($config['bot_token'] ?? '') ? '•••••• (tersimpan, kosongkan bila tetap)' : '123456789:AAF…' }}">
            <x-field-error name="cfg.bot_token"/></div>
        <div class="field"><label for="chat_id">Chat ID</label>
            <input class="input mono @error('cfg.chat_id') is-err @enderror" id="chat_id" name="cfg[chat_id]" value="{{ old('cfg.chat_id', $config['chat_id'] ?? '') }}" placeholder="-1001234567890">
            <div class="hint">ID grup diawali <code>-100</code>. Cara mudah: masukkan bot ke grup, kirim pesan, lalu buka <code>api.telegram.org/bot&lt;token&gt;/getUpdates</code> dari browser.</div>
            <x-field-error name="cfg.chat_id"/></div>
    @elseif($t === 'email')
        <div class="row r3">
            <div class="field"><label for="host">Server SMTP</label><input class="input @error('cfg.host') is-err @enderror" id="host" name="cfg[host]" value="{{ old('cfg.host', $config['host'] ?? '') }}" placeholder="smtp.perusahaan.co.id"><x-field-error name="cfg.host"/></div>
            <div class="field"><label for="port">Port</label><input class="input" id="port" name="cfg[port]" type="number" value="{{ old('cfg.port', $config['port'] ?? 587) }}"></div>
            <div class="field"><label for="security">Keamanan</label><select class="input" id="security" name="cfg[security]">
                @foreach(['starttls' => 'STARTTLS (587)', 'tls' => 'TLS (465)', 'none' => 'Tanpa (relay internal)'] as $k => $v)<option value="{{ $k }}" @selected(old('cfg.security', $config['security'] ?? 'starttls') === $k)>{{ $v }}</option>@endforeach</select></div>
        </div>
        <div class="row">
            <div class="field"><label for="username">Username <span class="muted">(opsional)</span></label><input class="input" id="username" name="cfg[username]" value="{{ old('cfg.username', $config['username'] ?? '') }}" autocomplete="off"></div>
            <div class="field"><label for="password">Password</label><input class="input" id="password" name="cfg[password]" type="password" autocomplete="new-password" placeholder="{{ ($config['password'] ?? '') ? '•••••• (tersimpan)' : '' }}"></div>
        </div>
        <div class="row">
            <div class="field"><label for="from">Alamat pengirim</label><input class="input @error('cfg.from') is-err @enderror" id="from" name="cfg[from]" value="{{ old('cfg.from', $config['from'] ?? '') }}" placeholder="nms@perusahaan.co.id"><x-field-error name="cfg.from"/></div>
            <div class="field"><label for="to">Penerima</label><input class="input @error('cfg.to') is-err @enderror" id="to" name="cfg[to]" value="{{ old('cfg.to', $config['to'] ?? '') }}" placeholder="noc@…, network@…"><div class="hint">Pisahkan dengan koma.</div><x-field-error name="cfg.to"/></div>
        </div>
    @else
        <div class="field"><label for="url">URL webhook</label><input class="input mono @error('cfg.url') is-err @enderror" id="url" name="cfg[url]" value="{{ old('cfg.url', $config['url'] ?? '') }}" placeholder="https://gateway.local/hook/antareja"><x-field-error name="cfg.url"/></div>
        <div class="field"><label for="secret">Secret tanda tangan <span class="muted">(opsional)</span></label><input class="input" id="secret" name="cfg[secret]" type="password" autocomplete="off" placeholder="{{ ($config['secret'] ?? '') ? '•••••• (tersimpan)' : '' }}">
            <div class="hint">Bila diisi, setiap kiriman membawa header <code>X-Antareja-Signature</code> = HMAC-SHA256 dari body.</div></div>
    @endif

    <div class="section-title">Aturan</div>
    <div class="row r3">
        <div class="field"><label for="min_severity">Severity minimal</label><select class="input" id="min_severity" name="min_severity">
            @foreach(\App\Models\Problem::SEVERITIES as $k => [$label])<option value="{{ $k }}" @selected(old('min_severity', $channel->min_severity) === $k)>{{ $label }} ke atas</option>@endforeach</select></div>
        <div class="field"><label for="delay_minutes">Jeda (menit)</label><input class="input" type="number" min="0" max="1440" id="delay_minutes" name="delay_minutes" value="{{ old('delay_minutes', $channel->delay_minutes) }}"><div class="hint">0 = segera. &gt; 0 = eskalasi bila belum di-ACK.</div></div>
        <div class="field"><label for="site_id">Site</label><select class="input" id="site_id" name="site_id"><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(old('site_id', $channel->site_id) == $s->id)>{{ $s->code }}</option>@endforeach</select></div>
    </div>
    <label class="check"><input type="checkbox" name="notify_resolve" value="1" @checked(old('notify_resolve', $channel->notify_resolve))><span>Kirim juga saat problem pulih</span></label>
    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $channel->enabled))><span>Kanal aktif</span></label>
    <div class="form-foot"><a class="btn" href="{{ route('channels.index') }}">Batal</a><button class="btn primary">Simpan</button></div>
</form>
</div></section>
<aside class="stack">
    <section class="card"><div class="hd"><h2>Contoh pengaturan</h2></div><div class="bd" style="font-size:13.5px">
        <p style="margin-top:0"><b>Grup NOC</b>: Telegram, severity Warning ke atas, jeda 0.</p>
        <p><b>Supervisor</b>: Telegram/Email, severity High ke atas, jeda 30 menit (eskalasi bila belum ada yang ACK).</p>
        <p style="margin-bottom:0"><b>Manajemen</b>: Email, Disaster, jeda 60 menit.</p>
    </div></section>
    @if($editing)
    <section class="card"><div class="hd"><h2>Hapus kanal</h2></div><div class="bd">
        <form method="post" action="{{ route('channels.destroy', $channel) }}" data-confirm="Hapus kanal {{ $channel->name }}?">@csrf @method('DELETE')<button class="btn danger"><x-icon name="trash"/>Hapus</button></form>
    </div></section>
    @endif
</aside>
</div>
@endsection
