@extends('layouts.app')
@section('title', 'Kesehatan sistem')
@php($gb = fn ($b) => $b === null ? 'tidak ada' : ($b >= 1073741824 ? number_format($b / 1073741824, 1, ',', '.') . ' GB' : ($b >= 1048576 ? number_format($b / 1048576, 0, ',', '.') . ' MB' : number_format(max(1, $b / 1024), 0, ',', '.') . ' KB')))
@php($ago = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->diffForHumans() : 'belum ada')
@section('content')
<div class="page-head">
    <div><h1>Kesehatan sistem</h1><p>Kondisi ANTAREJA sendiri. Masalah di bawah juga dikirim ke kanal notifikasi (kecuali kanal "Disaster saja"): saat muncul, pengingat tiap 6 jam, dan saat pulih. Diperbarui tiap menit oleh collector.</p></div>
</div>

<div class="kpis">
    <div class="kpi {{ $stale ? 'crit' : 'ok' }}"><div class="lbl">Collector</div><div class="val" style="font-size:22px">{{ $stale ? 'TIDAK MELAPOR' : 'Berjalan' }}</div>
        <div class="note">detak {{ $c ? $c['at']->diffForHumans() : 'belum ada' }}@if($c && ($c['v']['version'] ?? null)), v{{ $c['v']['version'] }}@endif</div></div>
    <div class="kpi {{ $c && ($c['v']['ping_at'] ?? null) && \Illuminate\Support\Carbon::parse($c['v']['ping_at'])->gt(now()->subMinutes(3)) ? 'ok' : 'crit' }}"><div class="lbl">Putaran ping terakhir</div>
        <div class="val" style="font-size:22px">{{ $ago($c['v']['ping_at'] ?? null) }}</div><div class="note">{{ $counts->devices }} perangkat aktif, SNMP {{ $ago($c['v']['snmp_at'] ?? null) }}</div></div>
    @php($free = $b['v']['disk_free_kb'] ?? null)
    @php($tot = $b['v']['disk_total_kb'] ?? null)
    @php($pct = $free !== null && $tot ? $free / $tot * 100 : null)
    <div class="kpi {{ $pct === null ? '' : ($pct < 10 ? 'crit' : ($pct < 20 ? 'warn' : 'ok')) }}"><div class="lbl">Disk folder backup</div>
        <div class="val" style="font-size:22px">{{ $free === null ? 'tidak ada' : $gb($free * 1024) . ' kosong' }}</div><div class="note">@if($pct !== null){{ number_format($pct, 0) }}% dari {{ $gb($tot * 1024) }}, dilaporkan {{ $b['at']->diffForHumans() }}@else layanan backup belum melapor @endif</div></div>
    <div class="kpi {{ $metricsOk ? 'ok' : 'crit' }}"><div class="lbl">Database grafik</div><div class="val" style="font-size:22px">{{ $metricsOk ? 'Normal' : 'Tidak menjawab' }}</div><div class="note">VictoriaMetrics, database utama {{ $gb($dbSize) }}</div></div>
</div>

<div class="grid g-main">
<div class="stack">
    <section class="card"><div class="hd"><h2>Peringatan sistem</h2></div>
        <div class="bd flush">
        @if($alerts->isEmpty())<div class="empty">Belum pernah ada peringatan sistem.</div>@else
        <ul class="feed">
            @foreach($alerts as $a)
            <li><span class="ic {{ $a->active ? ($a->severity === 'high' ? 'crit' : 'warn') : 'ok' }}"><x-icon name="{{ $a->active ? 'alert' : 'check' }}"/></span>
                <div class="msg"><b>{{ \App\Http\Controllers\SystemController::LABELS[$a->key] ?? $a->key }}</b> @if($a->active)<span class="badge {{ $a->severity === 'high' ? 'crit' : 'warn' }} plain">aktif</span>@else<span class="badge ok plain">pulih</span>@endif
                    <small>{{ $a->message }}</small></div>
                <time>{{ $a->active ? 'sejak ' . \Illuminate\Support\Carbon::parse($a->since)->format('d/m H.i') : 'pulih ' . ($a->resolved_at ? \Illuminate\Support\Carbon::parse($a->resolved_at)->format('d/m H.i') : '') }}</time></li>
            @endforeach
        </ul>
        @endif
        </div></section>

    <section class="card"><div class="hd"><h2>Notifikasi 24 jam</h2><a class="sub" href="{{ route('channels.index') }}">Kelola kanal</a></div>
        <div class="bd flush">
        @if($notify->isEmpty())<div class="empty">Belum ada notifikasi terkirim dalam 24 jam.</div>@else
        <table class="t"><thead><tr><th>Kanal</th><th class="num">Terkirim</th><th class="num">Gagal</th></tr></thead><tbody>
            @foreach($notify as $n)<tr><td class="name">{{ $n->name }}@if($n->err)<small style="color:var(--crit)">{{ \Illuminate\Support\Str::limit($n->err, 120) }}</small>@endif</td><td class="num">{{ $n->sent }}</td><td class="num" style="{{ $n->failed ? 'color:var(--crit);font-weight:600' : '' }}">{{ $n->failed }}</td></tr>@endforeach
        </tbody></table>
        @endif
        </div></section>
</div>

<div class="stack">
    <section class="card"><div class="hd"><h2>Backup database</h2><a class="sub" href="{{ route('backups.index') }}">Kelola backup</a></div>
        <div class="bd flush"><ul class="feed">
            @forelse($backups as $bk)
            <li><span class="ic {{ $bk->status === 'ok' ? 'ok' : ($bk->status === 'failed' ? 'crit' : 'muted') }}"><x-icon name="database"/></span>
                <div class="msg"><b>{{ ['ok' => 'Berhasil', 'failed' => 'Gagal', 'running' => 'Berjalan', 'requested' => 'Antre', 'deleted' => 'Dihapus (retensi)'][$bk->status] ?? $bk->status }}</b>
                    <small>{{ $bk->kind === 'manual' ? 'manual' : 'terjadwal' }}{{ $bk->size_bytes ? ', ' . $gb($bk->size_bytes) : '' }}{{ $bk->error ? ', ' . \Illuminate\Support\Str::limit($bk->error, 80) : '' }}</small></div>
                <time>{{ $ago($bk->finished_at ?? $bk->requested_at) }}</time></li>
            @empty<li><div class="msg muted">Belum ada backup.</div></li>@endforelse
        </ul></div></section>

    <section class="card"><div class="hd"><h2>Syslog & data</h2></div><div class="bd">
        <dl class="facts">
            @php($sl = $c['v']['syslog'] ?? null)
            <dt>Syslog diterima</dt><dd>{{ $sl ? number_format($sl['received'], 0, ',', '.') : 'tidak ada' }} sejak collector mulai, {{ number_format($counts->logs_1h, 0, ',', '.') }} dalam 1 jam</dd>
            <dt>Syslog dibuang</dt><dd style="{{ ($c['v']['syslog_dropped_1h'] ?? 0) ? 'color:var(--warn);font-weight:600' : '' }}">{{ number_format($c['v']['syslog_dropped_1h'] ?? 0, 0, ',', '.') }} dalam 1 jam{{ $sl ? ', ' . number_format($sl['dropped'], 0, ',', '.') . ' total' : '' }}</dd>
            <dt>Collector mulai</dt><dd>{{ $c && ($c['v']['started_at'] ?? null) ? \Illuminate\Support\Carbon::parse($c['v']['started_at'])->format('d/m/Y H.i') : 'tidak ada' }}</dd>
            <dt>Problem terbuka</dt><dd>{{ $counts->problems }}</dd>
            <dt>Tabel terbesar</dt><dd>@foreach($tables as $t){{ $t->name }} {{ $gb($t->b) }}@if(! $loop->last), @endif @endforeach</dd>
        </dl>
    </div></section>
</div>
</div>
@endsection
