@extends('layouts.app')
@section('title', 'Perangkat')
@section('content')
<div class="page-head">
    <div><h1>Perangkat</h1><p>{{ number_format($devices->total(), 0, ',', '.') }} perangkat, yang bermasalah tampil paling atas</p></div>
    @can('manage-devices')<div class="actions"><a class="btn" href="{{ route('discovery.index') }}"><x-icon name="search"/>Discovery</a><a class="btn" href="{{ route('devices.import') }}"><x-icon name="download"/>Impor CSV</a><a class="btn primary" href="{{ route('devices.create') }}"><x-icon name="plus"/>Tambah perangkat</a></div>@endcan
</div>
<section class="card">
    <form class="filters" method="get">
        <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="Nama, IP, sysName">
        <select class="input" name="status" aria-label="Status">
            <option value="">Semua status</option>
            @foreach(['down' => 'Down', 'up' => 'Online', 'unknown' => 'Menunggu', 'snmp_fail' => 'SNMP gagal', 'off' => 'Nonaktif'] as $k => $v)
                <option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <select class="input" name="site" aria-label="Site">
            <option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }} ({{ $s->name }})</option>@endforeach
        </select>
        <select class="input" name="category" aria-label="Kategori">
            <option value="">Semua kategori</option>
            @foreach(\App\Models\Device::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $v }}</option>@endforeach
        </select>
        <button class="btn sm">Terapkan</button>
        @if(request()->hasAny(['q', 'status', 'site', 'category']))<a href="{{ route('devices.index') }}" class="btn sm">Reset</a>@endif
    </form>
    @php($bulk = auth()->user()->can('manage-devices') && $devices->isNotEmpty())
    @if($bulk)
    <form id="massal" method="post" action="{{ route('devices.bulk') }}" class="bulk-edit" data-bulk>@csrf
        <div class="bulkbar">
            <span class="muted" data-bulk-count>Centang perangkat untuk mengubah beberapa sekaligus.</span>
            <select class="input" name="action" aria-label="Aksi massal">
                @foreach(\App\Http\Controllers\DeviceBulkController::ACTIONS as $k => $v)<option value="{{ $k }}" @selected(old('action') === $k)>{{ $v }}</option>@endforeach</select>
            <select class="input" name="site_id" aria-label="Site tujuan" data-show-if="action=site"><option value="">Tanpa site</option>
                @foreach($sites as $s)<option value="{{ $s->id }}">{{ $s->code }} ({{ $s->name }})</option>@endforeach</select>
            <select class="input" name="parent_id" aria-label="Perangkat induk" data-show-if="action=parent"><option value="">Tanpa induk</option>
                @foreach($parents as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->host }})</option>@endforeach</select>
            <select class="input" name="collector_id" aria-label="Dipantau dari" data-show-if="action=collector"><option value="">Server pusat</option>
                @foreach($collectors as $col)<option value="{{ $col->id }}">Collector site {{ $col->name }}</option>@endforeach</select>
            <select class="input" name="template_id" aria-label="Templat" data-show-if="action=template"><option value="">Lepas templat</option>
                @foreach(\App\Models\DeviceTemplate::orderBy('name')->get(['id', 'name']) as $tp)<option value="{{ $tp->id }}">{{ $tp->name }}</option>@endforeach</select>
            <select class="input" name="category" aria-label="Kategori" data-show-if="action=category">
                @foreach(\App\Models\Device::CATEGORIES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <input class="input @error('confirm') is-err @enderror" name="confirm" placeholder="ketik HAPUS" aria-label="Konfirmasi hapus" autocomplete="off" data-show-if="action=delete">
            <button class="btn sm primary" data-bulk-submit disabled>Terapkan ke yang dicentang</button>
        </div>
        <div class="bulk-snmp stack-sm" data-show-if="action=snmp">
            <div class="row r3">
                <div class="field"><label for="b-ver">Versi</label><select class="input" id="b-ver" name="snmp_version">@foreach(['2c' => 'v2c', '1' => 'v1', '3' => 'v3'] as $k => $v)<option value="{{ $k }}" @selected(old('snmp_version') === $k)>{{ $v }}</option>@endforeach</select></div>
                <div class="field"><label for="b-port">Port</label><input class="input" type="number" id="b-port" name="snmp_port" value="{{ old('snmp_port', 161) }}"></div>
                <div class="field" data-show-if="snmp_version=1,2c"><label for="b-comm">Community</label><input class="input mono @error('snmp_community') is-err @enderror" type="password" id="b-comm" name="snmp_community" autocomplete="new-password"><x-field-error name="snmp_community"/></div>
            </div>
            <div class="row r3" data-show-if="snmp_version=3">
                <div class="field"><label for="b-user">User</label><input class="input mono @error('snmp_v3_user') is-err @enderror" id="b-user" name="snmp_v3_user" value="{{ old('snmp_v3_user') }}" autocomplete="off"><x-field-error name="snmp_v3_user"/></div>
                <div class="field"><label for="b-level">Level</label><select class="input" id="b-level" name="snmp_v3_level">@foreach(['authPriv', 'authNoPriv', 'noAuthNoPriv'] as $l)<option @selected(old('snmp_v3_level') === $l)>{{ $l }}</option>@endforeach</select></div>
                <div class="field"><label for="b-aproto">Autentikasi</label><select class="input" id="b-aproto" name="snmp_v3_auth_proto">@foreach(['SHA', 'SHA256', 'SHA512', 'SHA224', 'SHA384', 'MD5'] as $l)<option>{{ $l }}</option>@endforeach</select></div>
                <div class="field"><label for="b-apass">Password autentikasi</label><input class="input @error('snmp_v3_auth_pass') is-err @enderror" type="password" id="b-apass" name="snmp_v3_auth_pass" autocomplete="new-password"><x-field-error name="snmp_v3_auth_pass"/></div>
                <div class="field"><label for="b-pproto">Enkripsi</label><select class="input" id="b-pproto" name="snmp_v3_priv_proto">@foreach(['AES', 'AES192', 'AES256', 'DES'] as $l)<option>{{ $l }}</option>@endforeach</select></div>
                <div class="field"><label for="b-ppass">Password enkripsi</label><input class="input @error('snmp_v3_priv_pass') is-err @enderror" type="password" id="b-ppass" name="snmp_v3_priv_pass" autocomplete="new-password"><x-field-error name="snmp_v3_priv_pass"/></div>
            </div>
            <p class="hint muted" style="margin:0">Kredensial hanya disimpan di ANTAREJA (terenkripsi) untuk membaca perangkat. Tidak ada yang dikirim atau diubah di perangkat.</p>
        </div>
        <x-field-error name="ids"/><x-field-error name="confirm"/>
    </form>
    @endif
    <div class="bd flush"><div class="table-wrap"><table class="t">
        <thead><tr>@if($bulk)<th class="pick"><input type="checkbox" data-check-all aria-label="Centang semua di halaman ini"></th>@endif<th>Perangkat</th><th>Status</th><th>Site</th><th>Kategori</th><th class="num">RTT</th><th class="num">Loss</th><th>SNMP</th><th class="num">Uptime</th></tr></thead>
        <tbody>
        @forelse($devices as $d)
            <tr class="{{ ! $d->enabled ? 'is-off' : ($d->status === 'down' ? 'is-down' : '') }}">
                @if($bulk)<td class="pick"><input type="checkbox" name="ids[]" value="{{ $d->id }}" form="massal" aria-label="Pilih {{ $d->name }}"></td>@endif
                <td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a><small class="mono">{{ $d->host }}@if($d->sys_name && $d->sys_name !== $d->name), {{ $d->sys_name }}@endif</small></td>
                <td><x-status :device="$d"/>@if($d->status === 'down' && $d->status_changed_at)<div class="muted" style="font-size:12px;margin-top:2px">{{ $d->status_changed_at->diffForHumans(short: true) }}</div>@endif</td>
                <td>{{ $d->site?->code ?? 'tidak ada' }}</td>
                <td><span class="nowrap"><x-icon :name="\App\Models\Device::CATEGORY_ICONS[$d->category] ?? 'box'" class="i muted"/> {{ $d->categoryLabel() }}</span></td>
                <td class="num">{{ $d->ping_enabled ? \App\Support\Fmt::ms($d->last_rtt_ms) : 'tidak ada' }}</td>
                <td class="num">{{ $d->ping_enabled && $d->last_loss_pct !== null ? (int) $d->last_loss_pct . '%' : 'tidak ada' }}</td>
                <td>@if(! $d->snmp_enabled)<span class="muted"><span class="nil">tidak ada</span></span>@elseif($d->snmp_ok === null)<span class="badge">menunggu</span>@elseif($d->snmp_ok)<span class="badge ok plain">v{{ $d->snmp_version }}</span>@else<span class="badge warn" title="{{ $d->snmp_error }}">gagal</span>@endif</td>
                <td class="num">{{ $d->uptimeHuman() ?? 'tidak ada' }}</td>
            </tr>
        @empty
            <tr><td colspan="9"><div class="empty">Tidak ada perangkat yang cocok.</div></td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    @include('partials.pager', ['p' => $devices])
</section>
@endsection
