{{-- Satu baris problem. $p = Problem, $compact = sembunyikan kolom perangkat --}}
@php($dim = $p->acked_at || $p->suppressed || $p->resolved_at)
<tr class="{{ $dim ? 'is-off' : ($p->sevTone() === 'crit' ? 'is-down' : '') }}">
    @if($select ?? false)<td class="chk">@if(! $p->resolved_at && $p->incidents->isEmpty())<input type="checkbox" name="problems[]" value="{{ $p->id }}" form="buat-insiden" aria-label="Pilih problem {{ $p->title }}">@endif</td>@endif
    <td><span class="badge plain {{ $p->sevTone() }}">{{ $p->sevLabel() }}</span></td>
    <td class="name">
        {{ $p->title }}
        <small>
            @if(! ($compact ?? false) && $p->device)<a href="{{ route('devices.show', $p->device) }}">{{ $p->device->name }}</a>@if($p->device->site) · {{ $p->device->site->code }}@endif · @endif
            @if($p->relationLoaded('incidents') && ($inc = $p->incidents->first()))<a class="inc-tag" href="{{ route('incidents.show', $inc) }}">Insiden #{{ $inc->id }}</a> · @endif
            @if($p->suppressed)<span style="color:var(--info)">{{ $p->suppressed_note }}</span>@else{{ $p->detail }}@endif
        </small>
    </td>
    <td class="nowrap" title="Mulai {{ $p->started_at->format('d/m/Y H:i:s') }}">
        {{ $p->durationHuman() }}<div class="muted" style="font-size:12px">{{ $p->resolved_at ? 'selesai ' . $p->resolved_at->format('d/m H:i') : 'sejak ' . ($p->started_at->isToday() ? $p->started_at->format('H:i') : $p->started_at->format('d/m H:i')) }}</div>
    </td>
    <td>
        @if($p->acked_at)
            <span class="badge ok plain" title="{{ $p->ack_note }}">ACK {{ $p->acker?->username }}</span>
            @if($p->ack_note)<div class="muted" style="font-size:12px;max-width:220px">{{ \Illuminate\Support\Str::limit($p->ack_note, 80) }}</div>@endif
        @elseif($p->suppressed)
            <span class="badge info plain">{{ $p->suppressed === 'maintenance' ? 'maintenance' : 'terdampak' }}</span>
        @elseif(! $p->resolved_at)
            <span class="badge warn plain">belum ACK</span>
        @endif
    </td>
    @can('manage-devices')
    <td class="right">
        @if(! $p->resolved_at && ! $p->acked_at)
            <details class="ack">
                <summary class="btn sm">ACK</summary>
                <form method="post" action="{{ route('problems.ack', $p) }}" class="card ack-pop">
                    @csrf
                    <label for="note-{{ $p->id }}" style="font-weight:500">Catatan <span class="muted">(opsional)</span></label>
                    <input class="input" id="note-{{ $p->id }}" name="note" maxlength="500" placeholder="mis. Tim lapangan menuju lokasi">
                    <button class="btn primary sm">ACK problem</button>
                </form>
            </details>
        @elseif(! $p->resolved_at && $p->acked_at)
            <form method="post" action="{{ route('problems.unack', $p) }}">@csrf @method('DELETE')<button class="linkbtn" style="font-size:12.5px">batalkan ACK</button></form>
        @endif
    </td>
    @endcan
</tr>
