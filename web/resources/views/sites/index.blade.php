@extends('layouts.app')
@section('title', 'Site')
@section('content')
<div class="page-head"><div><h1>Site</h1><p>Lokasi tambang tempat perangkat berada (mis. PIT-A, Workshop, Jetty, Mess)</p></div></div>
<div class="grid g-main">
    <section class="card"><div class="bd flush"><div class="table-wrap"><table class="t">
        <thead><tr><th>Kode</th><th>Nama</th><th class="num">Perangkat</th><th class="num">Down</th>@can('manage-devices')<th></th>@endcan</tr></thead>
        <tbody>
        @forelse($sites as $s)
            <tr>
                <td class="name"><a href="{{ route('devices.index', ['site' => $s->id]) }}">{{ $s->code }}</a></td>
                <td>{{ $s->name }}@if($s->description)<div class="muted" style="font-size:12.5px">{{ $s->description }}</div>@endif</td>
                <td class="num">{{ $s->devices_count }}</td>
                <td class="num" style="{{ $s->down ? 'color:var(--crit);font-weight:600' : '' }}">{{ $s->down }}</td>
                @can('manage-devices')
                <td class="right nowrap">
                    <details style="display:inline-block;text-align:left">
                        <summary class="btn sm" style="list-style:none">Ubah</summary>
                        <form method="post" action="{{ route('sites.update', $s) }}" class="form card" style="position:absolute;right:24px;z-index:3;padding:14px;width:320px;margin-top:6px">
                            @csrf @method('PUT')
                            <div class="field"><label>Kode</label><input class="input" name="code" value="{{ $s->code }}" required></div>
                            <div class="field"><label>Nama</label><input class="input" name="name" value="{{ $s->name }}" required></div>
                            <div class="field"><label>Keterangan</label><input class="input" name="description" value="{{ $s->description }}"></div>
                            <div class="form-foot"><button class="btn primary sm">Simpan</button></div>
                        </form>
                    </details>
                    <form method="post" action="{{ route('sites.destroy', $s) }}" style="display:inline" data-confirm="Hapus site {{ $s->code }}? {{ $s->devices_count }} perangkat akan menjadi tanpa site.">@csrf @method('DELETE')<button class="btn sm danger" aria-label="Hapus {{ $s->code }}"><x-icon name="trash"/></button></form>
                </td>
                @endcan
            </tr>
        @empty
            <tr><td colspan="5"><div class="empty">Belum ada site.</div></td></tr>
        @endforelse
        </tbody>
    </table></div></div></section>
    @can('manage-devices')
    <section class="card"><div class="hd"><h2>Tambah site</h2></div><div class="bd">
        <form method="post" action="{{ route('sites.store') }}" class="form">
            @csrf
            <div class="field"><label for="code">Kode</label><input class="input @error('code') is-err @enderror" id="code" name="code" value="{{ old('code') }}" placeholder="PIT-A" required><x-field-error name="code"/></div>
            <div class="field"><label for="sname">Nama</label><input class="input @error('name') is-err @enderror" id="sname" name="name" value="{{ old('name') }}" placeholder="Pit A Utara" required><x-field-error name="name"/></div>
            <div class="field"><label for="desc">Keterangan <span class="muted">(opsional)</span></label><input class="input" id="desc" name="description" value="{{ old('description') }}"></div>
            <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Tambah site</button></div>
        </form>
    </div></section>
    @endcan
</div>
@endsection
