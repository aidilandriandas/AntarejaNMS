{{-- Form komponen. $c = ServiceComponent|null, $s = Service, $devices --}}
@php($key = $c ? 'c' . $c->id : 'new')
@php($sel = collect(old('devices', $c?->devices->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v)->all())
<form method="post" action="{{ $c ? route('services.components.update', [$s, $c]) : route('services.components.store', $s) }}" class="stack">@csrf @if($c) @method('PUT') @endif
    <div class="field"><label for="{{ $key }}-name">Nama komponen</label><input class="input" id="{{ $key }}-name" name="name" value="{{ old('name', $c?->name) }}" maxlength="120" required placeholder="mis. Jalur ke Pit A, NVR, Uplink ISP"></div>
    <div class="field"><label for="{{ $key }}-dev">Perangkat <span class="muted">(Ctrl/⌘ + klik untuk memilih beberapa)</span></label>
        <input class="input" type="search" placeholder="Saring nama / IP…" data-filter-select="{{ $key }}-dev" aria-label="Saring perangkat">
        <select class="input" id="{{ $key }}-dev" name="devices[]" multiple size="9" style="height:auto" required>
            @foreach($devices as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, $sel, true))>{{ $d->name }}, {{ $d->host }}{{ $d->site ? ', ' . $d->site->code : '' }}</option>@endforeach
        </select></div>
    <div class="field"><label for="{{ $key }}-min">Minimal perangkat yang harus hidup</label>
        <input class="input" id="{{ $key }}-min" type="number" min="1" name="min_up" value="{{ old('min_up', $c?->min_up ?? 1) }}" required style="width:110px">
        <small class="muted" style="display:block;margin-top:4px">Jalur seri (setiap perangkat wajib): buat satu komponen per perangkat, atau isi sama dengan jumlah perangkat. Ada cadangan: isi 1.</small></div>
    <div><button class="btn primary sm">{{ $c ? 'Simpan komponen' : 'Tambah komponen' }}</button></div>
</form>
