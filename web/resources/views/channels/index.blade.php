@extends('layouts.app')
@section('title', 'Notifikasi')
@section('content')
<div class="page-head">
    <div><h1>Notifikasi & eskalasi</h1><p>Kanal dengan jeda 0 menit dikirim segera. Jeda &gt; 0 = eskalasi: dikirim hanya bila problem masih terbuka dan belum di-ACK selama itu.</p></div>
    <div class="actions">
        <a class="btn primary" href="{{ route('channels.create', ['type' => 'telegram']) }}"><x-icon name="plus"/>Telegram</a>
        <a class="btn" href="{{ route('channels.create', ['type' => 'email']) }}"><x-icon name="plus"/>Email</a>
        <a class="btn" href="{{ route('channels.create', ['type' => 'webhook']) }}"><x-icon name="plus"/>Webhook</a>
    </div>
</div>
<div class="grid g-main">
    <div class="stack">
        <section class="card"><div class="hd"><h2>Kanal</h2></div><div class="bd flush">
            @if($channels->isEmpty())
                <div class="empty">Belum ada kanal. Mulai dengan Telegram: buat bot di @BotFather, masukkan bot ke grup NOC, lalu tambahkan di sini.</div>
            @else
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Kanal</th><th>Severity</th><th>Kirim</th><th>Site</th><th></th></tr></thead>
                <tbody>
                @foreach($channels as $c)
                    <tr class="{{ $c->enabled ? '' : 'is-off' }}">
                        <td class="name">{{ $c->name }}<small>{{ \App\Models\NotifyChannel::TYPES[$c->type] }} · {{ $c->target() }}{{ $c->enabled ? '' : ' · nonaktif' }}</small></td>
                        <td>≥ {{ \App\Models\Problem::SEVERITIES[$c->min_severity][0] }}</td>
                        <td>{!! $c->delay_minutes ? '<span class="badge warn plain">eskalasi ' . $c->delay_minutes . ' mnt</span>' : 'segera' !!}{{ $c->notify_resolve ? ' · + pulih' : '' }}</td>
                        <td>{{ $c->site?->code ?? 'semua' }}</td>
                        <td class="right nowrap">
                            <form method="post" action="{{ route('channels.test', $c) }}" style="display:inline">@csrf<button class="btn sm"><x-icon name="pulse"/>Kirim uji</button></form>
                            <a class="btn sm" href="{{ route('channels.edit', $c) }}"><x-icon name="edit"/></a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @endif
        </div></section>
        <section class="card"><div class="hd"><h2>Riwayat pengiriman</h2><span class="sub">30 terakhir</span></div><div class="bd flush">
            @if($logs->isEmpty())<div class="empty">Belum ada pengiriman.</div>@else
            <div class="table-wrap"><table class="t"><tbody>
                @foreach($logs as $l)
                <tr>
                    <td class="nowrap mono">{{ $l->at->format('d/m H:i') }}</td>
                    <td>{{ $l->channel?->name }}</td>
                    <td><span class="tag">{{ ['open' => 'problem', 'resolve' => 'pulih', 'test' => 'uji', 'summary' => 'ringkasan'][$l->kind] ?? $l->kind }}</span></td>
                    <td>{{ $l->problem?->title ?? '—' }}</td>
                    <td>@if($l->status === 'sent')<span class="badge ok plain">terkirim</span>@else<span class="badge crit plain" title="{{ $l->error }}">gagal</span><div class="muted" style="font-size:12px;max-width:280px">{{ $l->error }}</div>@endif</td>
                </tr>
                @endforeach
            </tbody></table></div>@endif
        </div></section>
    </div>
    <section class="card"><div class="hd"><h2>Ambang problem</h2></div><div class="bd">
        <form method="post" action="{{ route('thresholds.update') }}" class="form">
            @csrf @method('PUT')
            <div class="field"><label for="latency_ms">Latensi tinggi (ms)</label><input class="input" type="number" step="1" min="1" id="latency_ms" name="latency_ms" value="{{ old('latency_ms', $thresholds['latency_ms']) }}"><div class="hint">Rata-rata 5 menit di atas nilai ini → Warning.</div></div>
            <div class="field"><label for="loss_pct">Packet loss (%)</label><input class="input" type="number" step="1" min="1" max="100" id="loss_pct" name="loss_pct" value="{{ old('loss_pct', $thresholds['loss_pct']) }}"><div class="hint">Rata-rata 5 menit di atas nilai ini → Average (≥ 50% → High).</div></div>
            <div class="field"><label for="util_pct">Port penuh (%)</label><input class="input" type="number" step="1" min="10" max="100" id="util_pct" name="util_pct" value="{{ old('util_pct', $thresholds['util_pct']) }}"><div class="hint">Hanya port yang ditandai <em>Pantau</em> di halaman perangkat.</div></div>
            <div class="field"><label for="snmp_fail_cycles">SNMP gagal (putaran)</label><input class="input" type="number" step="1" min="1" max="20" id="snmp_fail_cycles" name="snmp_fail_cycles" value="{{ old('snmp_fail_cycles', $thresholds['snmp_fail_cycles']) }}"><div class="hint">1 putaran = 5 menit.</div></div>
            <div class="form-foot"><button class="btn primary">Simpan ambang</button></div>
        </form>
        <p class="muted" style="font-size:12.5px;margin:12px 0 0">Down: perangkat tidak menjawab 2 putaran ping (±2 menit) → High; Core/Distribution/Firewall → Disaster. Perangkat yang induknya down ditandai <em>terdampak</em> dan tidak dinotifikasi.</p>
    </div></section>
</div>
@endsection
