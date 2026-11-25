<form class="filters" method="get">
    <select class="input" name="periode" aria-label="Periode" data-autosubmit>
        @foreach(\App\Http\Controllers\IspSlaController::PERIODS as $k => $v)<option value="{{ $k }}" @selected($periode === $k)>{{ $v }}</option>@endforeach
    </select>
    @if($periode === 'kustom')
        <input class="input" type="date" name="dari" value="{{ request('dari', $start->format('Y-m-d')) }}" aria-label="Dari tanggal">
        <input class="input" type="date" name="sampai" value="{{ request('sampai', $end->subDay()->format('Y-m-d')) }}" aria-label="Sampai tanggal">
        <button class="btn sm">Tampilkan</button>
    @endif
    <span class="muted" style="margin-left:auto">{{ $label }}</span>
</form>
