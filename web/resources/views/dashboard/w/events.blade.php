    <section class="card">
        <div class="hd"><h2>Kejadian 24 jam</h2><a class="sub" href="{{ route('events.index') }}">Semua →</a></div>
        <div class="bd flush">@include('events.feed', ['events' => $events, 'showDevice' => true])</div>
    </section>
