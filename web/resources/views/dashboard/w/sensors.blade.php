<section class="card">
    <div class="hd"><h2>Sensor di luar ambang</h2><a class="sub" href="{{ route('sensors.index') }}">Semua sensor →</a></div>
    <div class="bd flush">
    @if($sensors->isEmpty())<div class="empty">Semua sensor dalam batas normal.</div>@else
    <div class="table-wrap"><table class="t"><tbody>
        @foreach($sensors as $s)
        <tr><td class="name"><a href="{{ route('devices.show', $s->device_id) }}">{{ $s->name }}</a><small>{{ $s->label }}</small></td>
            <td class="num nowrap" style="font-weight:600;color:var(--{{ $s->status === 'crit' ? 'crit' : 'warn' }})">{{ $s->value !== null ? rtrim(rtrim(number_format($s->value, 1, ',', '.'), '0'), ',') . ' ' . $s->unit : $s->text }}</td>
            <td style="width:1%"><span class="badge {{ $s->status === 'crit' ? 'crit' : 'warn' }} plain">{{ $s->status === 'crit' ? 'kritis' : 'peringatan' }}</span></td></tr>
        @endforeach
    </tbody></table></div>
    @endif
    </div>
</section>
