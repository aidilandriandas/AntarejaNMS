@extends('layouts.app')
@section('title', 'Topologi')
@section('content')
<div class="page-head">
    <div><h1>Topologi</h1><p>Sambungan dari LLDP/CDP (otomatis), sambungan manual, dan perangkat induk (garis putus-putus). Label di ujung garis adalah port di masing-masing perangkat.</p></div>
    <div class="actions"><a class="btn" href="{{ route('noc') }}" target="_blank" rel="noopener"><x-icon name="monitor"/>Buka Layar NOC</a></div>
</div>

<div data-topology data-url="{{ route('topology.data') }}" data-live-url="{{ route('topology.live') }}" data-positions-url="{{ route('topology.positions') }}">
    <div class="topo-bar">
        <div class="topo-bar-g">
            <select class="input" data-topo-site aria-label="Site">
                <option value="">Semua site</option>
                @foreach($sites as $s)<option value="{{ $s->id }}">{{ $s->code }} ({{ $s->name }})</option>@endforeach
            </select>
            <label class="check"><input type="checkbox" data-topo-live checked><span>Trafik live</span></label>
            <div class="seg" role="tablist" aria-label="Tampilan">
                <button type="button" role="tab" class="on" aria-selected="true" data-topo-view="map">Peta</button>
                <button type="button" role="tab" aria-selected="false" data-topo-view="list">Daftar sambungan</button>
            </div>
        </div>
        <div class="topo-bar-g">
            <button type="button" class="btn sm" data-topo-fit><x-icon name="expand"/>Pas layar</button>
            <button type="button" class="btn sm" data-topo-legend aria-expanded="false"><x-icon name="list"/>Legenda</button>
            @can('manage-devices')
                <form method="post" action="{{ route('topology.sync') }}" class="inline-form">@csrf<button class="btn sm" title="Baca ulang tetangga LLDP/CDP semua perangkat SNMP sekarang, tanpa menunggu putaran berikutnya"><x-icon name="rotate"/>Sinkron LLDP sekarang</button></form>
                <button type="button" class="btn sm" data-topo-unlock title="Lepas semua posisi dan biarkan peta menyusun ulang"><x-icon name="refresh"/>Atur ulang tata letak</button>
                <a class="btn sm" href="#sambung"><x-icon name="share"/>Sambungkan</a>
                <a class="btn sm" href="{{ route('devices.create') }}"><x-icon name="plus"/>Tambah perangkat</a>
                <button type="button" class="btn sm primary" data-topo-save>Simpan posisi</button>
            @endcan
        </div>
    </div>

    <p class="alert info" data-topo-crowded hidden><x-icon name="info"/><span>Posisi yang tersimpan membuat kartu perangkat bertumpuk (tata letak lama), jadi peta disusun ulang otomatis.@can('manage-devices') Tekan <b>Simpan posisi</b> untuk menyimpan susunan baru.@endcan</span></p>
    <section class="topo-wrap" data-topo-panel="map">
        <div class="topo" data-topo-canvas role="application" aria-label="Peta topologi. Seret untuk menggeser, roda tetikus atau tombol plus dan minus untuk zoom, panah untuk menggeser saat peta difokuskan. Versi teks ada di tab Daftar sambungan."></div>
        <div class="topo-legend" data-topo-legend-panel hidden>
            <b>Perangkat</b>
            <ul>
                <li><span class="lg-card ok"></span>Online (titik hijau)</li>
                <li><span class="lg-card warn"></span>Ada problem, tulisan PROBLEM</li>
                <li><span class="lg-card crit"></span>Down, tulisan DOWN dan tepi merah</li>
                <li><span class="lg-card info"></span>Maintenance, tulisan MAINT</li>
                <li><span class="lg-card ext"></span>Belum terdaftar, tulisan BARU</li>
            </ul>
            <b>Sambungan</b>
            <ul>
                <li><svg width="34" height="8" aria-hidden="true"><line x1="0" y1="4" x2="34" y2="4" class="lg-ln acc"/></svg>Terhubung (LLDP/CDP atau manual)</li>
                <li><svg width="34" height="8" aria-hidden="true"><line x1="0" y1="4" x2="34" y2="4" class="lg-ln warn" stroke-width="4"/></svg>Pemakaian ≥ 70% (lebih tebal = lebih sibuk)</li>
                <li><svg width="34" height="8" aria-hidden="true"><line x1="0" y1="4" x2="34" y2="4" class="lg-ln crit" stroke-dasharray="8 5"/></svg>Putus</li>
                <li><svg width="34" height="8" aria-hidden="true"><line x1="0" y1="4" x2="34" y2="4" class="lg-ln mut" stroke-dasharray="5 5"/></svg>Perangkat induk (tanpa data port)</li>
                <li><svg width="34" height="8" aria-hidden="true"><line x1="0" y1="4" x2="34" y2="4" class="lg-ln mut" stroke-dasharray="2 4"/></svg>Ke tetangga belum terdaftar</li>
            </ul>
            <p>Label kecil di ujung garis adalah nama port di perangkat itu. Label tampil saat peta cukup dekat.</p>
        </div>
        <div class="topo-foot"><span class="muted" data-topo-stats></span><span class="muted">Klik perangkat untuk detail. Peta dimuat ulang tiap 30 detik.</span></div>
    </section>

    <section class="card" data-topo-panel="list" hidden>
        <div class="bd flush"><div class="table-wrap"><table class="t">
            <thead><tr><th>Perangkat A</th><th>Port A</th><th>Perangkat B</th><th>Port B</th><th>Sumber</th><th>Status</th><th class="num">Masuk</th><th class="num">Keluar</th></tr></thead>
            <tbody data-topo-rows><tr><td colspan="8" class="empty">Memuat sambungan…</td></tr></tbody>
        </table></div></div>
    </section>

    <div class="grid g-main" style="margin-top:var(--space-xl)">
        <section class="card"><div class="hd"><h2>Perangkat terpilih</h2></div><div class="bd" data-topo-info><span class="muted">Klik perangkat di peta, atau nama perangkat di daftar sambungan.</span></div></section>
        <div class="stack">
            @can('manage-devices')
            <section class="card" id="sambung"><div class="hd"><h2>Tambah sambungan manual</h2></div><div class="bd">
                <form method="post" action="{{ route('topology.links.store') }}" class="form" data-link-form data-ports-url="{{ url('topologi/port') }}">
                    @csrf
                    @foreach(['a' => 'Perangkat A', 'b' => 'Perangkat B'] as $k => $label)
                        <div class="row">
                            <div class="field"><label for="{{ $k }}_device_id">{{ $label }}</label>
                                <select class="input @error($k.'_device_id') is-err @enderror" id="{{ $k }}_device_id" name="{{ $k }}_device_id" data-link-device="{{ $k }}" required>
                                    <option value="">Pilih perangkat</option>
                                    @foreach($devices as $d)<option value="{{ $d->id }}" @selected(old($k.'_device_id') == $d->id)>{{ $d->name }}</option>@endforeach
                                </select><x-field-error :name="$k.'_device_id'"/></div>
                            <div class="field"><label for="{{ $k }}_if_index">Port</label>
                                <select class="input" id="{{ $k }}_if_index" name="{{ $k }}_if_index" data-link-port="{{ $k }}"><option value="">(tidak diketahui)</option></select></div>
                        </div>
                    @endforeach
                    <div class="field"><label for="note">Keterangan <span class="muted">(opsional)</span></label><input class="input" id="note" name="note" placeholder="mis. FO 12 core jalur hauling"></div>
                    <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Tambah</button></div>
                </form>
                <p class="hint muted" style="margin:10px 0 0">Untuk perangkat tanpa LLDP/CDP (radio, NVR, kamera). Pilih port di sisi yang ber-SNMP agar trafik live tampil.</p>
            </div></section>
            @endcan
            @if($links->isNotEmpty())
            <section class="card"><div class="hd"><h2>Sambungan manual</h2></div><div class="bd flush"><table class="t"><tbody>
                @foreach($links as $l)
                    <tr><td>{{ $l->a_name }}<span class="muted">{{ $l->a_port ? ', ' . $l->a_port : '' }}</span> ↔ {{ $l->b_name }}<span class="muted">{{ $l->b_port ? ', ' . $l->b_port : '' }}</span>
                        @if($l->note)<div class="muted" style="font-size:12px">{{ $l->note }}</div>@endif</td>
                        @can('manage-devices')<td class="right"><form method="post" action="{{ route('topology.links.destroy', $l->id) }}" data-confirm="Hapus sambungan ini?">@csrf @method('DELETE')<button class="btn sm danger" aria-label="Hapus sambungan {{ $l->a_name }} ke {{ $l->b_name }}"><x-icon name="trash"/></button></form></td>@endcan</tr>
                @endforeach
            </tbody></table></div></section>
            @endif
        </div>
    </div>
</div>
@endsection
