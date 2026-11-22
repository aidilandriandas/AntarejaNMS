        <section class="card">
            <div class="hd"><h2>Latensi & loss tertinggi</h2><span class="sub">ping terakhir</span></div>
            <div class="bd flush"><div class="table-wrap"><table class="t">
                <thead><tr><th>Perangkat</th><th>Site</th><th class="num">RTT</th><th class="num">Loss</th></tr></thead>
                <tbody>
                @forelse($slow as $d)
                    <tr>
                        <td class="name"><a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a></td>
                        <td>{{ $d->site?->code ?? 'tidak ada' }}</td>
                        <td class="num" style="color:{{ $d->last_rtt_ms >= 150 ? 'var(--crit)' : ($d->last_rtt_ms >= 50 ? 'var(--warn)' : 'inherit') }}">{{ \App\Support\Fmt::ms($d->last_rtt_ms) }}</td>
                        <td class="num" style="color:{{ $d->last_loss_pct > 0 ? 'var(--warn)' : 'inherit' }}">{{ (int) $d->last_loss_pct }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">Belum ada data ping.</td></tr>
                @endforelse
                </tbody>
            </table></div></div>
        </section>
