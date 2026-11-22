// Trafik live port (gaya grafik "realtime"): counter oktet dibaca langsung dari perangkat lewat collector,
// laju = selisih dua pembacaan ÷ selisih waktu. Berhenti saat tab disembunyikan dan setelah 15 menit.
import uPlot from 'uplot';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const csrf = () => $('meta[name="csrf-token"]')?.content;
const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const MAX_POINTS = 150;
const MAX_RUN_MS = 15 * 60 * 1000;

export const bps = (v) => {
    if (v == null || Number.isNaN(v)) return '–';
    const u = [['Gbps', 1e9], ['Mbps', 1e6], ['Kbps', 1e3]].find(([, d]) => v >= d);
    return (u ? `${(v / u[1]).toFixed(v / u[1] >= 100 ? 0 : 1)} ${u[0]}` : `${Math.round(v)} bps`).replace('.', ',');
};

/** Selisih counter dengan penanganan wrap 32-bit; null bila counter direset. */
export const delta = (prev, cur) => {
    if (cur >= prev) return cur - prev;
    if (prev < 2 ** 32) return cur + 2 ** 32 - prev; // counter 32-bit berputar
    return null;
};

// Banyak agen SNMP hanya memperbarui counter tiap beberapa detik (cache). Bila counter belum berubah dalam
// STALE_S detik, sampel dilewati (bukan dicatat 0) dan laju dihitung dari pembacaan terakhir yang berubah.
const STALE_S = 10;

/**
 * Hitung laju dari dua sampel {in, out, at}.
 * @returns {{in:number,out:number}|null|'wait'} 'wait' = counter belum diperbarui agen, pertahankan sampel lama
 */
export const rate = (a, b) => {
    const dt = b.at - a.at;
    if (dt <= 0.2) return 'wait';
    const di = delta(a.in, b.in);
    const dout = delta(a.out, b.out);
    if (di == null || dout == null) return null;
    if (di === 0 && dout === 0 && dt < STALE_S) return 'wait';
    return { in: (di * 8) / dt, out: (dout * 8) / dt };
};

async function fetchSamples(url, ports) {
    const r = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ ports }),
    });
    if (r.status === 429) return { samples: [], error: 'Terlalu sering — tunggu sebentar.' };
    if (!r.ok) return { samples: [], error: `Server menjawab ${r.status}` };
    return r.json();
}

export function initLive() {
    const card = $('[data-live-card]');
    if (!card) return;
    const url = card.dataset.url;
    const el = $('[data-live-chart]', card);
    const note = $('[data-live-note]', card);
    const dot = $('[data-live-dot]', card);
    const v = (k) => $(`[data-live-v="${k}"]`, card);

    let port = null; // {if, name, speed}
    let interval = 2;
    let timer = null;
    let paused = false;
    let startedAt = 0;
    let prev = null;
    let data = [[], [], []];
    let plot = null;

    const draw = () => {
        if (plot) plot.destroy();
        const axis = { stroke: css('--muted'), grid: { stroke: css('--line'), width: 1 }, ticks: { stroke: css('--line') } };
        const two = (n) => String(n).padStart(2, '0');
        const hms = (ts) => { const d = new Date(ts * 1000); return `${two(d.getHours())}.${two(d.getMinutes())}.${two(d.getSeconds())}`; };
        plot = new uPlot({
            width: el.clientWidth, height: 240,
            scales: { x: { time: true }, y: { range: (u, min, max) => [0, Math.max(max * 1.15, 1000)] } },
            axes: [{ ...axis, values: (u, splits) => splits.map(hms) }, { ...axis, values: (u, vals) => vals.map(bps), size: 78 }],
            series: [{ label: 'Waktu', value: (u, ts) => (ts == null ? '–' : hms(ts)) },
                { label: 'Masuk', stroke: css('--accent'), fill: `${css('--accent')}26`, width: 1.6, value: (u, x) => bps(x) },
                { label: 'Keluar', stroke: css('--info'), width: 1.6, value: (u, x) => bps(x) }],
            legend: { live: true }, cursor: { drag: { x: false, y: false } },
        }, data, el);
    };

    const stats = () => {
        const [, ins, outs] = data;
        const last = (a) => (a.length ? a[a.length - 1] : null);
        const avg = (a) => (a.length ? a.reduce((s, x) => s + x, 0) / a.length : null);
        const max = (a) => (a.length ? Math.max(...a) : null);
        v('in').textContent = bps(last(ins));
        v('out').textContent = bps(last(outs));
        v('in-avg').textContent = bps(avg(ins));
        v('out-avg').textContent = bps(avg(outs));
        v('in-max').textContent = bps(max(ins));
        v('out-max').textContent = bps(max(outs));
        const cur = Math.max(last(ins) ?? 0, last(outs) ?? 0);
        v('util').textContent = port.speed > 0 && ins.length ? `${((cur / port.speed) * 100).toFixed(1).replace('.', ',')}%` : '–';
        v('info').textContent = `${port.speed > 0 ? `dari ${bps(port.speed)} · ` : ''}${ins.length} titik · tiap ${interval} dtk`;
    };

    const setState = (s, msg) => {
        dot.className = `live-dot ${s}`;
        if (msg != null) note.textContent = msg;
    };

    const tick = async () => {
        if (!port || paused) return;
        if (Date.now() - startedAt > MAX_RUN_MS) {
            stop('Dijeda otomatis setelah 15 menit. Tekan Lanjut untuk meneruskan.');
            return;
        }
        try {
            const res = await fetchSamples(url, [port.if]);
            const s = (res.samples || []).find((x) => x.if === port.if);
            if (!s) { setState('err', res.error || 'Tidak ada data dari perangkat.'); return; }
            setState('on', `Dibaca langsung dari perangkat (SNMP) tiap ${interval} detik selama halaman ini terbuka; berhenti sendiri saat tab disembunyikan atau setelah 15 menit.`);
            const r = prev ? rate(prev, s) : null;
            if (r === 'wait') return; // counter belum berubah: tunggu pembacaan berikutnya
            if (r) {
                data[0].push(s.at); data[1].push(r.in); data[2].push(r.out);
                if (data[0].length > MAX_POINTS) data = data.map((a) => a.slice(-MAX_POINTS));
                plot.setData(data);
                stats();
            }
            prev = s;
        } catch (e) {
            setState('err', 'Gagal menghubungi server.');
        }
    };

    const schedule = () => {
        clearInterval(timer);
        timer = setInterval(tick, interval * 1000);
    };
    function stop(msg) {
        paused = true;
        clearInterval(timer);
        timer = null;
        $('[data-live-pause]', card).textContent = 'Lanjut';
        setState('', msg ?? null);
    }
    const resume = () => {
        paused = false;
        prev = null; // jeda panjang: jangan hitung laju lintas jeda
        startedAt = Date.now();
        $('[data-live-pause]', card).textContent = 'Jeda';
        tick();
        schedule();
    };

    const open = (row) => {
        port = { if: Number(row.dataset.port), name: row.dataset.portName, speed: Number(row.dataset.speed) || 0 };
        $('[data-live-title]', card).textContent = `Trafik live · ${port.name}`;
        data = [[], [], []];
        prev = null;
        card.hidden = false;
        draw();
        ['in', 'out', 'in-avg', 'out-avg', 'in-max', 'out-max', 'util'].forEach((k) => { v(k).textContent = '–'; });
        v('info').textContent = 'mengambil sampel pertama…';
        $$('[data-live-port]').forEach((b) => b.classList.toggle('primary', Number(b.dataset.livePort) === port.if));
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        resume();
    };

    $$('[data-live-port]').forEach((b) => b.addEventListener('click', (e) => {
        e.stopPropagation(); // jangan buka grafik riwayat
        open(b.closest('[data-port]') || document.querySelector(`[data-port="${b.dataset.livePort}"]`));
    }));
    $$('[data-live-int]', card).forEach((b) => b.addEventListener('click', () => {
        interval = Number(b.dataset.liveInt);
        $$('[data-live-int]', card).forEach((x) => x.classList.toggle('on', x === b));
        prev = null;
        if (!paused) schedule();
        if (port) stats();
    }));
    $('[data-live-pause]', card).addEventListener('click', () => (paused ? resume() : stop('Dijeda.')));
    $('[data-live-close]', card).addEventListener('click', () => {
        stop();
        port = null;
        card.hidden = true;
        $$('[data-live-port]').forEach((b) => b.classList.remove('primary'));
    });
    document.addEventListener('visibilitychange', () => {
        if (!port) return;
        if (document.hidden && !paused) stop('Dijeda karena tab tidak terlihat.');
    });
    window.addEventListener('resize', () => plot && plot.setSize({ width: el.clientWidth, height: 240 }));
    window.addEventListener('ant-theme', () => port && draw());

    initLiveAll(url);
}

/** "Live semua port": kolom Masuk/Keluar/Pemakaian diperbarui tiap 5 detik (port yang up, maks. 60). */
function initLiveAll(url) {
    const box = $('[data-live-all]');
    if (!box) return;
    let timer = null;
    let prev = {};
    let started = 0;
    const rows = () => $$('[data-port]').filter((r) => r.dataset.up === '1').slice(0, 60);
    const run = async () => {
        if (document.hidden) return;
        if (Date.now() - started > MAX_RUN_MS) { box.checked = false; off(); return; }
        const rs = rows();
        let res;
        try { res = await fetchSamples(url, rs.map((r) => Number(r.dataset.port))); } catch (e) { return; }
        const byIf = Object.fromEntries((res.samples || []).map((s) => [s.if, s]));
        rs.forEach((row) => {
            const s = byIf[row.dataset.port];
            if (!s) return;
            const p = prev[row.dataset.port];
            const r = p ? rate(p, s) : null;
            if (r === 'wait') return;
            prev[row.dataset.port] = s;
            if (!r) return;
            $('[data-cell-in]', row).textContent = bps(r.in);
            $('[data-cell-out]', row).textContent = bps(r.out);
            const speed = Number(row.dataset.speed) || 0;
            const cell = $('[data-cell-util]', row);
            if (speed > 0) {
                const u = Math.min(100, (Math.max(r.in, r.out) / speed) * 100);
                cell.innerHTML = '';
                const bar = document.createElement('div');
                bar.className = `bar ${u >= 90 ? 'crit' : u >= 70 ? 'warn' : ''}`;
                bar.title = `${u.toFixed(1).replace('.', ',')}%`;
                const fill = document.createElement('span');
                fill.style.width = `${Math.max(1, u)}%`;
                bar.append(fill);
                cell.append(bar);
            }
            row.classList.add('live-on');
        });
    };
    const off = () => {
        clearInterval(timer);
        timer = null;
        $$('[data-port]').forEach((r) => r.classList.remove('live-on'));
    };
    box.addEventListener('change', () => {
        if (box.checked) {
            prev = {};
            started = Date.now();
            run();
            timer = setInterval(run, 5000);
        } else {
            off();
        }
    });
}
