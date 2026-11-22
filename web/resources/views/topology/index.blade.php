@extends('layouts.app')
@section('title', 'Topologi')
@section('content')
<div class="page-head">
    <div><h1>Topologi</h1><p>Sambungan dari LLDP/CDP (otomatis), sambungan manual, dan perangkat induk (garis putus-putus).</p></div>
    <div class="actions"><a class="btn" href="{{ route('noc') }}" target="_blank" rel="noopener"><x-icon name="monitor"/>Buka Layar NOC</a></div>
</div>
<div class="grid g-main" data-topology data-url="{{ route('topology.data') }}" data-live-url="{{ route('topology.live') }}" data-positions-url="{{ route('topology.positions') }}">
    <section class="card topo-card">
        <div class="hd">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select class="input" data-topo-site style="width:auto;height:32px" aria-label="Site">
                    <option value="">Semua site</option>
                    @foreach($sites as $s)<option value="{{ $s->id }}">{{ $s->code }} ({{ $s->name }})</option>@endforeach
                </select>
                <label class="check" style="align-items:center"><input type="checkbox" data-topo-live checked><span>Trafik live (5 dtk)</span></label>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="btn sm" data-topo-fit><x-icon name="expand"/>Pas layar</button>
                @can('manage-devices')
                    <button type="button" class="btn sm" data-topo-unlock title="Biarkan node menyusun ulang posisi otomatis">Susun otomatis</button>
                    <button type="button" class="btn sm primary" data-topo-save>Simpan tata letak</button>
                @endcan
            </div>
        </div>
        <div class="topo" data-topo-canvas aria-label="Peta topologi"></div>
        <div class="topo-foot">
            <span class="legend"><i style="background:var(--ok)"></i>Online <i style="background:var(--warn)"></i>Ada problem <i style="background:var(--crit)"></i>Down <i style="background:var(--info)"></i>Maintenance <i class="hollow"></i>Belum terdaftar</span>
            <span class="muted" data-topo-stats></span>
        </div>
    </section>
    <aside class="stack">
        <section class="card"><div class="hd"><h2>Perangkat terpilih</h2></div><div class="bd" data-topo-info><span class="muted">Klik perangkat di peta.</span></div></section>
        @can('manage-devices')
        <section class="card"><div class="hd"><h2>Tambah sambungan manual</h2></div><div class="bd">
            <form method="post" action="{{ route('topology.links.store') }}" class="form" data-link-form data-ports-url="{{ url('topologi/port') }}">
                @csrf
                @foreach(['a' => 'Perangkat A', 'b' => 'Perangkat B'] as $k => $label)
                    <div class="row">
                        <div class="field"><label for="{{ $k }}_device_id">{{ $label }}</label>
                            <select class="input @error($k.'_device_id') is-err @enderror" id="{{ $k }}_device_id" name="{{ $k }}_device_id" data-link-device="{{ $k }}" required>
                                <option value="">— pilih —</option>
                                @foreach($devices as $d)<option value="{{ $d->id }}" @selected(old($k.'_device_id') == $d->id)>{{ $d->name }}</option>@endforeach
                            </select><x-field-error :name="$k.'_device_id'"/></div>
                        <div class="field"><label for="{{ $k }}_if_index">Port</label>
                            <select class="input" id="{{ $k }}_if_index" name="{{ $k }}_if_index" data-link-port="{{ $k }}"><option value="">(tidak diketahui)</option></select></div>
                    </div>
                @endforeach
                <div class="field"><label for="note">Keterangan <span class="muted">(opsional)</span></label><input class="input" id="note" name="note" placeholder="mis. FO 12 core jalur hauling"></div>
                <div class="form-foot"><button class="btn primary"><x-icon name="plus"/>Tambah</button></div>
            </form>
            <p class="hint muted" style="margin:10px 0 0;font-size:12.5px">Untuk perangkat tanpa LLDP/CDP (radio, NVR, kamera). Pilih port di sisi yang ber-SNMP agar trafik live tampil.</p>
        </div></section>
        @endcan
        @if($links->isNotEmpty())
        <section class="card"><div class="hd"><h2>Sambungan manual</h2></div><div class="bd flush"><table class="t"><tbody>
            @foreach($links as $l)
                <tr><td>{{ $l->a_name }}<span class="muted">{{ $l->a_port ? ', ' . $l->a_port : '' }}</span> ↔ {{ $l->b_name }}<span class="muted">{{ $l->b_port ? ', ' . $l->b_port : '' }}</span>
                    @if($l->note)<div class="muted" style="font-size:12px">{{ $l->note }}</div>@endif</td>
                    @can('manage-devices')<td class="right"><form method="post" action="{{ route('topology.links.destroy', $l->id) }}" data-confirm="Hapus sambungan ini?">@csrf @method('DELETE')<button class="btn sm danger" aria-label="Hapus"><x-icon name="trash"/></button></form></td>@endcan</tr>
            @endforeach
        </tbody></table></div></section>
        @endif
    </aside>
</div>
@endsection
