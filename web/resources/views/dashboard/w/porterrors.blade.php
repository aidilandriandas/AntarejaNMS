<section class="card">
    <div class="hd"><h2>Port dengan error</h2><span class="sub">per menit, putaran SNMP terakhir</span></div>
    <div class="bd flush">
    @if($portErrors->isEmpty())<div class="empty">Tidak ada port yang mencatat error atau discard.</div>@else
    <table class="t"><tbody>
        @foreach($portErrors as $p)
        <tr>
            <td class="name"><a href="{{ route('ports.show', [$p->device_id, $p->if_index]) }}">{{ $p->device }}, {{ $p->port }}</a>@if($p->alias)<small>{{ $p->alias }}</small>@endif</td>
            <td class="num nowrap"><span class="{{ $p->err_rate >= 1 ? 'txt-warn' : '' }}">{{ \App\Support\Fmt::perMin($p->err_rate) }}</span> error</td>
            <td class="num nowrap"><span class="{{ $p->discard_rate >= 1 ? 'txt-warn' : '' }}">{{ \App\Support\Fmt::perMin($p->discard_rate) }}</span> discard</td>
        </tr>
        @endforeach
    </tbody></table>
    @endif
    </div>
</section>
