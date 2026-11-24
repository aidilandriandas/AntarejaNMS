@php($R = \App\Http\Controllers\RoutingController::class)
@if($bgp->isNotEmpty())
<section class="card"><div class="hd"><h2>Sesi BGP</h2><span class="sub">{{ $bgp->where('state', 6)->where('missing', false)->count() }} dari {{ $bgp->count() }} established</span></div><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr>@if($showDevice)<th>Router</th>@endif<th>Tetangga</th><th>AS</th><th>Status</th><th>Sejak</th>@can('manage-devices')<th></th>@endcan</tr></thead><tbody>
    @foreach($bgp as $p)@php($bad = $R::bgpBad($p))
        <tr>@if($showDevice)<td class="name"><a href="{{ route('devices.show', $p->device_id) }}">{{ $p->device }}</a>@if($p->local_as)<small>AS {{ $p->local_as }}</small>@endif</td>@endif
            <td class="mono">@if($p->peer_id)<a href="{{ route('devices.show', $p->peer_id) }}">{{ $p->addr }}</a> <span class="muted">{{ $p->peer_name }}</span>@else{{ $p->addr }}@endif</td>
            <td class="mono">{{ $p->remote_as }}</td>
            <td>@if($p->missing)<span class="badge warn plain">hilang dari tabel</span>@elseif((int) $p->admin_status === 1)<span class="badge plain">dimatikan admin</span>@else<span class="badge {{ $bad ? 'crit' : 'ok' }} plain">{{ $R::BGP[$p->state] ?? $p->state }}</span>@endif</td>
            <td class="muted nowrap">{{ $p->missing ? \Illuminate\Support\Carbon::parse($p->state_changed_at)->diffForHumans() : $R::ago($p->since_s) }}</td>
            @can('manage-devices')<td class="right">@if($p->missing)<form method="post" action="{{ route('routing.forget', $p->device_id) }}" data-confirm="Lupakan sesi BGP {{ $p->addr }}?">@csrf @method('DELETE')<input type="hidden" name="proto" value="bgp"><input type="hidden" name="addr" value="{{ $p->addr }}"><button class="btn sm">Lupakan</button></form>@endif</td>@endcan</tr>
    @endforeach
</tbody></table></div></div></section>
@endif
@if($ospf->isNotEmpty())
<section class="card"><div class="hd"><h2>Tetangga OSPF</h2><span class="sub">{{ $ospf->whereIn('state', [4, 8])->where('missing', false)->count() }} dari {{ $ospf->count() }} normal</span></div><div class="bd flush"><div class="table-wrap"><table class="t">
    <thead><tr>@if($showDevice)<th>Router</th>@endif<th>Alamat</th><th>Router ID</th><th>Status</th><th>Berubah</th>@can('manage-devices')<th></th>@endcan</tr></thead><tbody>
    @foreach($ospf as $n)@php($bad = $R::ospfBad($n))
        <tr>@if($showDevice)<td class="name"><a href="{{ route('devices.show', $n->device_id) }}">{{ $n->device }}</a></td>@endif
            <td class="mono">@if($n->peer_id)<a href="{{ route('devices.show', $n->peer_id) }}">{{ $n->ip }}</a> <span class="muted">{{ $n->peer_name }}</span>@else{{ $n->ip }}@endif</td>
            <td class="mono">{{ $n->router_id }}</td>
            <td><span class="badge {{ $bad ? 'crit' : 'ok' }} plain">{{ $n->missing ? 'hilang' : ($R::OSPF[$n->state] ?? $n->state) }}</span></td>
            <td class="muted nowrap">{{ \Illuminate\Support\Carbon::parse($n->state_changed_at)->diffForHumans() }}</td>
            @can('manage-devices')<td class="right">@if($n->missing)<form method="post" action="{{ route('routing.forget', $n->device_id) }}" data-confirm="Lupakan tetangga OSPF {{ $n->ip }}?">@csrf @method('DELETE')<input type="hidden" name="proto" value="ospf"><input type="hidden" name="addr" value="{{ $n->ip }}"><button class="btn sm">Lupakan</button></form>@endif</td>@endcan</tr>
    @endforeach
</tbody></table></div></div></section>
@endif
