@extends('layouts.app')
@section('title', 'Discovery ' . $s->cidr)
@php([$label, $tone] = \App\Http\Controllers\DiscoveryController::STATUS[$s->status])
@php($active = in_array($s->status, ['queued', 'running']))
@php($counts = $rows->countBy('state'))
@php($tab = request('tab', 'new'))
@php($shown = $tab === 'all' ? $rows : $rows->where('state', $tab))
@section('content')
<div class="page-head">
    <div><div class="crumbs"><a href="{{ route('devices.index') }}">Perangkat</a> / <a href="{{ route('discovery.index') }}">Discovery</a> / {{ $s->cidr }}</div>
        <h1 class="mono">{{ $s->cidr }}</h1>
        <p><span class="badge {{ $tone }}" data-disc-status>{{ $label }}</span>
            <span data-disc-text>{{ $s->progress }} / {{ $s->total ?: '…' }} alamat · {{ $s->found }} menjawab</span>
            @if($s->error) · <span style="color:var(--crit)">{{ $s->error }}</span>@endif
            @if($s->site_code) · site {{ $s->site_code }}@endif</p></div>
    @if($active)<div class="actions"><form method="post" action="{{ route('discovery.cancel', $s->id) }}">@csrf<button class="btn">Batalkan</button></form></div>@endif
</div>

@if($active)
<section class="card" data-disc="{{ route('discovery.status', $s->id) }}"><div class="bd">
    <div class="bar" style="height:10px"><span data-disc-bar style="width:{{ $s->total ? round($s->progress / $s->total * 100) : 2 }}%;background:var(--info)"></span></div>
    <p class="muted" style="margin:10px 0 0">Memindai… halaman diperbarui otomatis. Anda boleh meninggalkan halaman ini; pemindaian tetap berjalan di server.</p>
</div></section>
@endif

<section class="card">
    <div class="tabs">
        @foreach(['new' => 'Baru', 'exists' => 'Sudah terdaftar', 'added' => 'Ditambahkan', 'ignored' => 'Diabaikan', 'all' => 'Semua'] as $k => $v)
            <a href="{{ route('discovery.show', [$s->id, 'tab' => $k]) }}" class="{{ $tab === $k ? 'on' : '' }}">{{ $v }} <span class="muted">{{ $k === 'all' ? $rows->count() : ($counts[$k] ?? 0) }}</span></a>
        @endforeach
    </div>
    @if($shown->isEmpty())
        <div class="empty">{{ $active ? 'Belum ada hasil — tunggu sebentar.' : ($tab === 'new' ? 'Tidak ada perangkat baru di subnet ini.' : 'Kosong.') }}</div>
    @else
    <form method="post" action="{{ route('discovery.add', $s->id) }}" data-disc-form>@csrf
        @if($tab === 'new')
        <div class="filters">
            <select class="input" name="site_id" aria-label="Site"><option value="">Site: {{ $s->site_code ?? '— tanpa site —' }}</option>
                @foreach($sites as $st)<option value="{{ $st->id }}">{{ $st->code }} — {{ $st->name }}</option>@endforeach</select>
            <select class="input" name="parent_id" aria-label="Perangkat induk"><option value="">Tanpa perangkat induk</option>
                @foreach($parents as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->host }})</option>@endforeach</select>
            <button class="btn primary sm" data-disc-add disabled><x-icon name="plus"/><span>Tambah terpilih (<span data-disc-n>0</span>)</span></button>
            <button class="btn sm" formaction="{{ route('discovery.ignore', $s->id) }}" data-disc-ign disabled>Abaikan</button>
            @unless($canAddSnmp)<span class="muted" style="font-size:12px">Community sudah dihapus (lebih dari 7 hari) — perangkat ditambahkan tanpa SNMP; isi community di halaman perangkat.</span>@endunless
            <x-field-error name="pick"/>
        </div>
        @endif
        <div class="table-wrap"><table class="t">
            <thead><tr>@if($tab === 'new')<th style="width:32px"><input type="checkbox" data-disc-all aria-label="Pilih semua"></th>@endif<th>Alamat</th><th>Ping</th><th>SNMP</th><th>Nama</th><th>Kategori</th><th>Vendor · sysDescr</th></tr></thead>
            <tbody>
            @foreach($shown as $r)
                <tr>
                    @if($tab === 'new')<td><input type="checkbox" name="pick[]" value="{{ $r->id }}" aria-label="Pilih {{ $r->ip }}"></td>@endif
                    <td class="mono nowrap">{{ $r->ip }}</td>
                    <td class="nowrap">@if($r->ping_ok)<span style="color:var(--ok)">{{ \App\Support\Fmt::ms($r->rtt_ms) }}</span>@else<span class="muted" title="Tidak menjawab ping">–</span>@endif</td>
                    <td>@if($r->snmp_ok)<span class="badge ok plain">OK</span>@else<span class="muted">–</span>@endif</td>
                    <td>@if($tab === 'new')<input class="input" name="name[{{ $r->id }}]" value="{{ $r->sys_name ?: $r->ip }}" maxlength="128" style="min-width:170px;height:32px">
                        @elseif($r->device_id)<a href="{{ route('devices.show', $r->device_id) }}">{{ $r->device_name }}</a>@else{{ $r->sys_name }}@endif</td>
                    <td>@if($tab === 'new')<select class="input" name="category[{{ $r->id }}]" style="height:32px;min-width:150px">@foreach(\App\Models\Device::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected($r->category === $k)>{{ $v }}</option>@endforeach</select>
                        @else{{ \App\Models\Device::CATEGORIES[$r->category] ?? $r->category }}@endif</td>
                    <td class="muted" style="font-size:12px;max-width:380px">@if($r->vendor)<b style="color:var(--ink-2)">{{ $r->vendor }}</b> · @endif{{ \Illuminate\Support\Str::limit($r->sys_descr, 120) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </form>
    @endif
</section>
@endsection
