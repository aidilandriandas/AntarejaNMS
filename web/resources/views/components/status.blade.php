@props(['device'])
<span class="badge {{ $device->statusTone() }}">{{ $device->statusLabel() }}</span>
