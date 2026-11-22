@php($steps = \App\Support\Onboarding::steps())
@php($done = collect($steps)->where('done', true)->count())
@if($done < count($steps))
<section class="card onboard" style="margin-bottom:16px">
    <div class="hd"><h2>Langkah awal: {{ $done }}/{{ count($steps) }} selesai</h2>
        <form method="post" action="{{ route('onboarding.hide') }}" data-confirm="Sembunyikan daftar langkah awal? Bisa ditampilkan lagi dari halaman Panduan.">@csrf<button class="btn sm">Sembunyikan</button></form></div>
    <div class="bar" style="height:6px;border-radius:0"><span style="width:{{ round($done / count($steps) * 100) }}%"></span></div>
    <ol class="onboard-list">
        @foreach($steps as $s)
        <li class="{{ $s['done'] ? 'done' : '' }}"><span class="ob-mark" aria-hidden="true">{{ $s['done'] ? '✓' : $loop->iteration }}</span>
            <div><a href="{{ route($s['route']) }}"><b>{{ $s['title'] }}</b></a><small>{{ $s['done'] ? 'Selesai' : $s['hint'] }}</small></div></li>
        @endforeach
    </ol>
</section>
@endif
