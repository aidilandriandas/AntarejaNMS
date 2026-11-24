{{-- Lokasi port switch sebuah MAC. $r: sw_id, sw_name, sw_if, sw_port, sw_vlan, sw_since --}}
@if(! empty($r->sw_id))
    <a href="{{ route('ports.show', [$r->sw_id, $r->sw_if]) }}">{{ $r->sw_name }}, {{ $r->sw_port ?? 'port ' . $r->sw_if }}</a>@if($r->sw_vlan)<span class="muted"> VLAN {{ $r->sw_vlan }}</span>@endif
    <div class="muted" style="font-size:12px">sejak {{ \Illuminate\Support\Carbon::parse($r->sw_since)->format('d/m H.i') }}</div>
@else<span class="muted">belum terlacak</span>@endif
