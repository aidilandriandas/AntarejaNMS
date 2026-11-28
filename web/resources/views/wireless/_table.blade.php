{{-- Tabel link radio. $links: baris dari App\Support\Wireless::query(); $showReporter: tampilkan kolom AP/pelapor. --}}
@php($W = \App\Support\Wireless::class)
<div class="table-wrap"><table class="t">
    <thead><tr>@if($showReporter)<th>Radio</th>@endif<th>Tersambung ke</th><th class="num">Sinyal</th><th class="num">CCQ</th><th class="num">Kapasitas</th><th class="num">Rate kirim / terima</th><th class="num">Jarak</th><th>Tersambung</th></tr></thead>
    <tbody>
    @foreach($links as $w)
        @php($sig = $w->signal_dbm !== null ? (float) $w->signal_dbm : null)
        <tr class="{{ $w->connected ? '' : 'is-off' }}">
            @if($showReporter)<td class="name"><a href="{{ route('devices.show', $w->device_id) }}">{{ $w->dev_name }}</a><small>{{ $W::KINDS[$w->kind] ?? $w->kind }}</small></td>@endif
            <td class="name">
                @if($w->remote_device_id)<a href="{{ route('devices.show', $w->remote_device_id) }}">{{ $w->remote_name }}</a>@else{{ $w->name ?: 'Belum terdaftar' }}@endif
                <small class="mono">{{ collect([$w->ip, str_starts_with($w->mac, 'ip:') ? null : $w->mac])->filter()->implode(', ') }}@if(! $w->remote_device_id && $w->connected), belum terdaftar di ANTAREJA @endif</small>
            </td>
            <td class="num nowrap">@if($sig !== null)<span class="badge {{ $W::tone($sig) }} plain">{{ round($sig) }} dBm</span>@else<span class="muted">tidak ada</span>@endif
                @if($w->remote_signal_dbm !== null)<div class="muted" style="font-size:12px" title="Sinyal yang diterima ujung lawan">lawan {{ round((float) $w->remote_signal_dbm) }} dBm</div>@endif
                @if($w->noise_dbm !== null)<div class="muted" style="font-size:12px">noise {{ round((float) $w->noise_dbm) }} dBm</div>@endif</td>
            <td class="num">{{ $w->ccq_pct !== null ? round((float) $w->ccq_pct) . '%' : '' }}</td>
            <td class="num">@if($w->capacity_pct !== null){{ round((float) $w->capacity_pct) }}%@endif @if($w->quality_pct !== null)<div class="muted" style="font-size:12px">kualitas {{ round((float) $w->quality_pct) }}%</div>@endif</td>
            <td class="num nowrap">@if($w->tx_mbps !== null || $w->rx_mbps !== null){{ $w->tx_mbps !== null ? round((float) $w->tx_mbps) : '?' }} / {{ $w->rx_mbps !== null ? round((float) $w->rx_mbps) : '?' }} Mbps @endif</td>
            <td class="num nowrap">{{ $w->distance_m ? number_format($w->distance_m / 1000, 1, ',', '.') . ' km' : '' }}</td>
            <td class="nowrap">@if($w->connected){{ $W::uptime($w->uptime_s) ?? 'ya' }}@else<span class="badge crit plain">terputus</span><div class="muted" style="font-size:12px">sejak {{ \Illuminate\Support\Carbon::parse($w->last_seen)->format('d/m H.i') }}</div>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
