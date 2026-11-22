@extends('layouts.app')
@section('title', 'Insiden')
@section('content')
<div class="page-head">
    <div><h1>Insiden</h1><p>Satu gangguan nyata = satu insiden, berisi semua problem terkait. Dibuat otomatis (problem Disaster, atau ≥ 3 problem High di satu site dalam 10 menit) atau manual dari halaman Problem. Pulih otomatis saat semua problemnya selesai; tutup setelah penyebab & tindakan diisi.</p></div>
    <div class="actions">
        <div class="seg"><a class="btn sm {{ $tab === 'aktif' ? 'primary' : '' }}" href="{{ route('incidents.index') }}">Aktif</a><a class="btn sm {{ $tab === 'ditutup' ? 'primary' : '' }}" href="{{ route('incidents.index', ['tab' => 'ditutup']) }}">Ditutup</a></div>
        <a class="btn sm" href="{{ route('incidents.csv') }}"><x-icon name="download"/>CSV 90 hari</a>
    </div>
</div>
<div class="kpis">
    <div class="kpi {{ $stats->active ? 'crit' : 'ok' }}"><div class="lbl">Sedang berlangsung</div><div class="val">{{ $stats->active }}</div><div class="note">terbuka + ditangani</div></div>
    <div class="kpi {{ $stats->to_close ? 'warn' : '' }}"><div class="lbl">Pulih, belum ditutup</div><div class="val">{{ $stats->to_close }}</div><div class="note">isi penyebab & tindakan</div></div>
    <div class="kpi"><div class="lbl">Insiden 30 hari</div><div class="val">{{ $stats->d30 }}</div></div>
    <div class="kpi"><div class="lbl">MTTR 30 hari</div><div class="val" style="font-size:24px">{{ $stats->mttr ? \App\Support\Fmt::duration((int) $stats->mttr) : '–' }}</div><div class="note">rata-rata lama sampai pulih</div></div>
</div>
<section class="card">
    <form class="filters" method="get">@if($tab !== 'aktif')<input type="hidden" name="tab" value="{{ $tab }}">@endif
        <select class="input" name="site" aria-label="Site" data-autosubmit><option value="">Semua site</option>
            @foreach($sites as $s)<option value="{{ $s->id }}" @selected(request('site') == $s->id)>{{ $s->code }}</option>@endforeach</select>
    </form>
    <div class="bd flush">
    @if($incidents->isEmpty())<div class="empty"><x-icon name="check"/>{{ $tab === 'aktif' ? 'Tidak ada insiden aktif.' : 'Belum ada insiden yang ditutup.' }}</div>@else
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Severity</th><th>Insiden</th><th>Status</th><th>Penanggung jawab</th><th class="num">Problem</th><th>Lama</th></tr></thead>
        <tbody>
        @foreach($incidents as $i)
            <tr class="{{ in_array($i->status, ['open']) && $i->sevTone() === 'crit' ? 'is-down' : ($i->status === 'closed' ? 'is-off' : '') }}">
                <td><span class="badge plain {{ $i->sevTone() }}">{{ $i->sevLabel() }}</span></td>
                <td class="name"><a href="{{ route('incidents.show', $i) }}">#{{ $i->id }} {{ $i->title }}</a><small>{{ $i->site?->code ?? 'tanpa site' }} · dibuka {{ $i->opened_at->format('d/m H:i') }}{{ $i->auto ? ' · otomatis' : '' }}</small></td>
                <td><span class="badge {{ $i->statusTone() }}">{{ $i->statusLabel() }}</span></td>
                <td>{{ $i->owner?->name ?? '–' }}</td>
                <td class="num">{{ $i->problems_count }}</td>
                <td class="nowrap">{{ \App\Support\Fmt::duration($i->downtimeSeconds()) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    </div>
    @include('partials.pager', ['p' => $incidents])
</section>
@endsection
