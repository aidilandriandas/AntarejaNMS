@if($events->isEmpty())
    <div class="empty"><x-icon name="check"/>Tidak ada kejadian.</div>
@else
<ul class="feed">
    @foreach($events as $e)
        @php([$label, $tone, $icon] = $e->meta())
        <li>
            <span class="ic {{ $tone }}" title="{{ $label }}"><x-icon :name="$icon"/></span>
            <span class="msg">{{ $e->message }}
                <small>{{ $label }}@if($showDevice && $e->device) · <a href="{{ route('devices.show', $e->device) }}">{{ $e->device->name }}</a>@endif</small></span>
            <time datetime="{{ $e->at->toIso8601String() }}" title="{{ $e->at->format('d/m/Y H:i:s') }}">{{ $e->at->isToday() ? $e->at->format('H:i') : $e->at->format('d/m H:i') }}</time>
        </li>
    @endforeach
</ul>
@endif
