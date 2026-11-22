@extends('layouts.app')
@php($editing = $device->exists)
@section('title', $editing ? 'Ubah ' . $device->name : 'Tambah perangkat')
@section('content')
<div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / {{ $editing ? $device->name : 'Tambah' }}</div>
<div class="page-head"><div><h1>{{ $editing ? 'Ubah perangkat' : 'Tambah perangkat' }}</h1>
    <p>Uji koneksi dulu sebelum menyimpan. Pemantauan hanya membaca perangkat — tidak ada konfigurasi yang diubah.</p></div></div>

<form method="post" action="{{ $editing ? route('devices.update', $device) : route('devices.store') }}" class="grid g-main" data-device-form>
    @csrf @if($editing) @method('PUT') <input type="hidden" name="device_id" value="{{ $device->id }}"> @endif
    <section class="card"><div class="bd form">
        <div class="row">
            <div class="field"><label for="name">Nama perangkat</label>
                <input class="input @error('name') is-err @enderror" id="name" name="name" value="{{ old('name', $device->name) }}" placeholder="mis. Core Switch Pit A" required>
                <x-field-error name="name"/></div>
            <div class="field"><label for="host">Alamat IP / nama DNS</label>
                <input class="input mono @error('host') is-err @enderror" id="host" name="host" value="{{ old('host', $device->host) }}" placeholder="10.10.1.1" required autocapitalize="none" spellcheck="false">
                <x-field-error name="host"/></div>
        </div>
        <div class="row">
            <div class="field"><label for="site_id">Site</label>
                <select class="input" id="site_id" name="site_id">
                    <option value="">— Tanpa site —</option>
                    @foreach($sites as $s)<option value="{{ $s->id }}" @selected(old('site_id', $device->site_id) == $s->id)>{{ $s->code }} — {{ $s->name }}</option>@endforeach
                </select>
                <div class="hint">Belum ada? <a href="{{ route('sites.index') }}">Kelola site</a></div></div>
            <div class="field"><label for="category">Kategori</label>
                <select class="input" id="category" name="category">
                    @foreach(\App\Models\Device::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(old('category', $device->category) === $k)>{{ $v }}</option>@endforeach
                </select></div>
        </div>
        <div class="field"><label for="parent_id">Perangkat induk (uplink) <span class="muted">(opsional)</span></label>
            <select class="input @error('parent_id') is-err @enderror" id="parent_id" name="parent_id">
                <option value="">— Tanpa induk —</option>
                @foreach($parents as $pd)<option value="{{ $pd->id }}" @selected(old('parent_id', $device->parent_id) == $pd->id)>{{ $pd->name }} ({{ $pd->host }})</option>@endforeach
            </select>
            <div class="hint">Perangkat yang dilewati untuk menjangkau perangkat ini (mis. radio PTP atau switch distribusi). Bila induk down, problem perangkat ini ditandai <em>terdampak</em> dan tidak membanjiri notifikasi.</div>
            <x-field-error name="parent_id"/></div>
        <div class="field"><label for="notes">Catatan <span class="muted">(opsional)</span></label>
            <textarea class="input" id="notes" name="notes" rows="2" placeholder="Lokasi rak, PIC, nomor sirkuit…">{{ old('notes', $device->notes) }}</textarea></div>

        <div class="section-title">Pemantauan</div>
        <label class="check"><input type="checkbox" name="ping_enabled" value="1" @checked(old('ping_enabled', $device->ping_enabled))>
            <span>Ping tiap menit<small>Matikan hanya bila perangkat memblokir ICMP — status lalu dinilai dari SNMP.</small></span></label>
        <x-field-error name="ping_enabled"/>
        <label class="check"><input type="checkbox" name="snmp_enabled" value="1" data-toggle="snmp" @checked(old('snmp_enabled', $device->snmp_enabled))>
            <span>Baca SNMP tiap 5 menit<small>Uptime, reboot, port & trafik.</small></span></label>

        <div data-section="snmp" class="form">
            <div class="row r3">
                <div class="field"><label for="snmp_version">Versi</label>
                    <select class="input" id="snmp_version" name="snmp_version" data-toggle="v3">
                        @foreach(['2c' => 'v2c', '3' => 'v3', '1' => 'v1'] as $k => $v)<option value="{{ $k }}" @selected((string) old('snmp_version', $device->snmp_version) === (string) $k)>{{ $v }}</option>@endforeach
                    </select></div>
                <div class="field"><label for="snmp_port">Port UDP</label>
                    <input class="input" id="snmp_port" name="snmp_port" type="number" min="1" max="65535" value="{{ old('snmp_port', $device->snmp_port) }}"></div>
                <div class="field" data-section="v2"><label for="snmp_community">Community</label>
                    <input class="input @error('snmp_community') is-err @enderror" id="snmp_community" name="snmp_community" type="password" autocomplete="off"
                        placeholder="{{ $device->hasSecret('snmp_community_enc') ? '•••••• (tersimpan — kosongkan bila tetap)' : '' }}">
                    <x-field-error name="snmp_community"/></div>
            </div>
            <div data-section="v3" class="form">
                <div class="row">
                    <div class="field"><label for="snmp_v3_user">User</label>
                        <input class="input @error('snmp_v3_user') is-err @enderror" id="snmp_v3_user" name="snmp_v3_user" value="{{ old('snmp_v3_user', $device->snmp_v3_user) }}" autocomplete="off"><x-field-error name="snmp_v3_user"/></div>
                    <div class="field"><label for="snmp_v3_level">Tingkat keamanan</label>
                        <select class="input" id="snmp_v3_level" name="snmp_v3_level">
                            @foreach(['authPriv' => 'authPriv (auth + enkripsi) — disarankan', 'authNoPriv' => 'authNoPriv (auth saja)', 'noAuthNoPriv' => 'noAuthNoPriv'] as $k => $v)
                                <option value="{{ $k }}" @selected(old('snmp_v3_level', $device->snmp_v3_level ?? 'authPriv') === $k)>{{ $v }}</option>@endforeach
                        </select></div>
                </div>
                <div class="row">
                    <div class="field"><label for="snmp_v3_auth_proto">Protokol auth</label>
                        <select class="input" id="snmp_v3_auth_proto" name="snmp_v3_auth_proto">
                            @foreach(['SHA', 'SHA256', 'SHA512', 'SHA224', 'SHA384', 'MD5'] as $p)<option @selected(old('snmp_v3_auth_proto', $device->snmp_v3_auth_proto ?? 'SHA') === $p)>{{ $p }}</option>@endforeach
                        </select></div>
                    <div class="field"><label for="snmp_v3_auth_pass">Password auth</label>
                        <input class="input @error('snmp_v3_auth_pass') is-err @enderror" id="snmp_v3_auth_pass" name="snmp_v3_auth_pass" type="password" autocomplete="new-password"
                            placeholder="{{ $device->hasSecret('snmp_v3_auth_enc') ? '•••••• (tersimpan)' : '' }}"><x-field-error name="snmp_v3_auth_pass"/></div>
                </div>
                <div class="row">
                    <div class="field"><label for="snmp_v3_priv_proto">Protokol privacy</label>
                        <select class="input" id="snmp_v3_priv_proto" name="snmp_v3_priv_proto">
                            @foreach(['AES', 'AES256', 'AES192', 'DES'] as $p)<option @selected(old('snmp_v3_priv_proto', $device->snmp_v3_priv_proto ?? 'AES') === $p)>{{ $p }}</option>@endforeach
                        </select></div>
                    <div class="field"><label for="snmp_v3_priv_pass">Password privacy</label>
                        <input class="input @error('snmp_v3_priv_pass') is-err @enderror" id="snmp_v3_priv_pass" name="snmp_v3_priv_pass" type="password" autocomplete="new-password"
                            placeholder="{{ $device->hasSecret('snmp_v3_priv_enc') ? '•••••• (tersimpan)' : '' }}"><x-field-error name="snmp_v3_priv_pass"/></div>
                </div>
            </div>
            <p class="hint muted" style="margin:0">Kredensial disimpan terenkripsi dan tidak pernah ditampilkan kembali.</p>
        </div>

        <details class="thr" @if($errors->has('thr.*') || ! empty($device->thresholds)) open @endif>
            <summary class="section-title" style="cursor:pointer;list-style:none">Ambang khusus perangkat ini {{ ! empty($device->thresholds) ? '(' . count($device->thresholds) . ' diatur)' : '' }}</summary>
            <p class="hint muted" style="margin:10px 0">Kosongkan untuk memakai ambang bawaan. Contoh: radio PTP jarak jauh boleh latensi & sinyal lebih longgar daripada core switch.</p>
            <div class="thr-grid">
                @foreach(\App\Models\Device::THRESHOLDS as $k => [$label, $unit, $def, $type])
                    @php($cur = $device->thresholds[$k] ?? null)
                    <div class="field"><label>{{ $label }} <span class="muted">({{ $unit }})</span></label>
                        @if($type === 'single')
                            <input class="input" type="number" step="any" name="thr[{{ $k }}]" value="{{ old("thr.$k", $cur) }}" placeholder="bawaan {{ $def }}">
                        @else
                            <div class="row" style="gap:6px">
                                <input class="input" type="number" step="any" name="thr[{{ $k }}][warn]" value="{{ old("thr.$k.warn", $cur[0] ?? null) }}" placeholder="peringatan" aria-label="{{ $label }} peringatan">
                                <input class="input" type="number" step="any" name="thr[{{ $k }}][crit]" value="{{ old("thr.$k.crit", $cur[1] ?? null) }}" placeholder="kritis" aria-label="{{ $label }} kritis">
                            </div><div class="hint">bawaan {{ $def }}</div>
                        @endif
                        <x-field-error :name="'thr.'.$k"/></div>
                @endforeach
            </div>
        </details>

        @if($editing)
            <label class="check" style="border-top:1px solid var(--line);padding-top:14px"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $device->enabled))>
                <span>Perangkat aktif dipantau<small>Matikan sementara (mis. perangkat dipindah/diperbaiki) tanpa menghapus riwayat.</small></span></label>
        @else
            <input type="hidden" name="enabled" value="1">
        @endif
        <div class="form-foot">
            <a class="btn" href="{{ $editing ? route('devices.show', $device) : route('devices.index') }}">Batal</a>
            <button class="btn primary">{{ $editing ? 'Simpan perubahan' : 'Simpan & mulai pantau' }}</button>
        </div>
    </div></section>

    <aside class="stack">
        <section class="card"><div class="hd"><h2>Uji koneksi</h2></div><div class="bd">
            <div class="testbox">
                <p style="margin:0 0 10px" class="muted">Ping dan SNMP dari server ANTAREJA ke perangkat, memakai isian di form. Tidak ada yang disimpan.</p>
                <button type="button" class="btn" data-test-url="{{ route('devices.test') }}"><x-icon name="pulse"/>Uji sekarang</button>
                <div class="res" data-test-result aria-live="polite"></div>
            </div>
        </div></section>
        @if($editing)
            <section class="card"><div class="hd"><h2>Hapus perangkat</h2></div><div class="bd">
                <p class="muted" style="margin-top:0">Riwayat kejadian ikut terhapus. Untuk berhenti sementara, cukup hilangkan centang <em>Perangkat aktif</em>.</p>
                <button type="submit" form="del-form" class="btn danger"><x-icon name="trash"/>Hapus {{ $device->name }}</button>
            </div></section>
        @endif
    </aside>
</form>
@if($editing)
    <form id="del-form" method="post" action="{{ route('devices.destroy', $device) }}" data-confirm="Hapus {{ $device->name }} beserta riwayatnya?">@csrf @method('DELETE')</form>
@endif
@endsection
