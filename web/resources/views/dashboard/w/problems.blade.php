        <section class="card">
            <div class="hd"><h2>Problem aktif</h2><a class="sub" href="{{ route('problems.index') }}">{{ $problemCount }} terbuka · semua →</a></div>
            <div class="bd flush">@include('problems.table', ['problems' => $problems, 'empty' => 'Tidak ada problem aktif. Semua normal.'])</div>
        </section>
