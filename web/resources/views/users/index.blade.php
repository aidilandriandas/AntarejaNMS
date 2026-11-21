@extends('layouts.app')
@section('title', 'Pengguna')
@section('content')
<div class="page-head"><div><h1>Pengguna</h1><p>Admin: semua akses · Engineer: kelola perangkat & site · Viewer: hanya melihat</p></div>
    <div class="actions"><a class="btn primary" href="{{ route('users.create') }}"><x-icon name="plus"/>Tambah pengguna</a></div></div>
<section class="card"><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr><th>Nama</th><th>Peran</th><th>Status</th><th>Login terakhir</th><th></th></tr></thead>
    <tbody>
    @foreach($users as $u)
        <tr class="{{ $u->active ? '' : 'is-off' }}">
            <td class="name">{{ $u->name }}<small class="mono">{{ $u->username }}{{ $u->email ? ' · ' . $u->email : '' }}</small></td>
            <td><span class="badge plain {{ $u->role === 'admin' ? 'info' : '' }}">{{ $u->roleLabel() }}</span></td>
            <td>{!! $u->active ? '<span class="badge ok">aktif</span>' : '<span class="badge">nonaktif</span>' !!}</td>
            <td>{{ $u->last_login_at?->diffForHumans() ?? 'belum pernah' }}</td>
            <td class="right"><a class="btn sm" href="{{ route('users.edit', $u) }}"><x-icon name="edit"/>Ubah</a></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div></section>
@endsection
