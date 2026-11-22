// Grafik detail port: trafik (masuk ke atas / keluar ke bawah + persentil 95), pemakaian %, paket, error & discard.
// Kursor keempat grafik disinkronkan.
import uPlot from 'uplot';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const two = (n) => String(n).padStart(2, '0');
const num = (v, d = 1) => v.toFixed(d).replace('.', ',');

const si = (v, units) => {
    if (v == null || Number.isNaN(v)) return '–';
    const a = Math.abs(v);
    const u = units.find(([, d]) => a >= d);
    return u ? `${num(v / u[1], a / u[1] >= 100 ? 0 : 1)} ${u[0]}` : `${num(v, a >= 10 || a === 0 ? 0 : 2)}${units.base}`;
};
const BPS = Object.assign([['Gbps', 1e9], ['Mbps', 1e6], ['Kbps', 1e3]], { base: ' bps' });
const PPS = Object.assign([['Mpps', 1e6], ['Kpps', 1e3]], { base: ' pps' });
const BYTES = Object.assign([['TB', 1e12], ['GB', 1e9], ['MB', 1e6], ['KB', 1e3]], { base: ' B' });
export const fmtBps = (v) => si(v == null ? v : Math.abs(v), BPS);
const fmtPps = (v) => si(v == null ? v : Math.abs(v), PPS);
const fmtRate = (v) => (v == null ? '–' : `${num(Math.abs(v), Math.abs(v) >= 10 ? 0 : 2)}/s`);
const fmtCount = (v) => (v == null ? '–' : Math.round(v).toLocaleString('id-ID'));

const neg = (a) => a.map((v) => (v == null ? null : -v));
const add = (a, b) => a.map((v, i) => (v == null && b[i] == null ? null : (v ?? 0) + (b[i] ?? 0)));

export function initPortGraphs() {
    const root = $('[data-port-graphs]');
    if (!root) return;
    let range = '24h';
    let last = null;
    const plots = {};
    const sync = uPlot.sync('port');

    const xValues = (u, splits, ax, space, incr) => splits.map((ts) => {
        const d = new Date(ts * 1000);
        if (incr >= 86400 * 28) return `${two(d.getMonth() + 1)}/${String(d.getFullYear()).slice(2)}`;
        return incr >= 86400 || (d.getHours() === 0 && d.getMinutes() === 0) ? `${two(d.getDate())}/${two(d.getMonth() + 1)}` : `${two(d.getHours())}.${two(d.getMinutes())}`;
    });
    const xLegend = (u, ts) => {
        if (ts == null) return '–';
        const d = new Date(ts * 1000);
        return `${two(d.getDate())}/${two(d.getMonth() + 1)} ${two(d.getHours())}.${two(d.getMinutes())}`;
    };

    const make = (key, series, cols, fmt, extra = {}) => {
        const el = $(`[data-pg-chart="${key}"]`, root);
        plots[key]?.destroy();
        el.querySelector('.empty')?.remove();
        const axis = { stroke: css('--muted'), grid: { stroke: css('--line'), width: 1 }, ticks: { stroke: css('--line') } };
        plots[key] = new uPlot({
            width: el.clientWidth, height: 220,
            scales: { x: { time: true }, ...(extra.scales || {}) },
            axes: [{ ...axis, values: xValues }, { ...axis, values: (u, vals) => vals.map(fmt), size: 80 }],
            series: [{ label: 'Waktu', value: xLegend }, ...series],
            legend: { live: true },
            cursor: { sync: { key: sync.key }, drag: { x: false, y: false } },
            hooks: extra.hooks || {},
        }, cols, el);
        sync.sub(plots[key]);
        if (cols.slice(1).every((c) => c.every((v) => v == null))) {
            const n = document.createElement('div');
            n.className = 'empty';
            n.textContent = key === 'pkts' || key === 'errs' ? 'Belum ada data (dikumpulkan sejak versi ini; perangkat lama mungkin tidak mendukung).' : 'Belum ada data untuk rentang ini.';
            el.prepend(n);
        }
    };

    // garis horizontal persentil 95 (masuk di atas 0, keluar di bawah 0)
    const p95Hook = (stats) => ({
        draw: [(u) => {
            const { ctx } = u;
            const lines = [[stats.in?.p95, css('--accent')], [stats.out?.p95 != null ? -stats.out.p95 : null, css('--info')]];
            ctx.save();
            ctx.setLineDash([6, 4]);
            ctx.lineWidth = 1.2 * devicePixelRatio;
            for (const [v, col] of lines) {
                if (v == null) continue;
                const y = Math.round(u.valToPos(v, 'y', true));
                ctx.strokeStyle = col;
                ctx.beginPath();
                ctx.moveTo(u.bbox.left, y);
                ctx.lineTo(u.bbox.left + u.bbox.width, y);
                ctx.stroke();
            }
            ctx.restore();
        }],
    });

    const draw = (d) => {
        last = d;
        const s = d.series;
        const acc = css('--accent');
        const inf = css('--info');
        const sym = (u, min, max) => { const m = Math.max(Math.abs(min), Math.abs(max), 1); return [-m * 1.1, m * 1.1]; };
        make('bits', [
            { label: 'Masuk', stroke: acc, fill: `${acc}30`, width: 1.4, value: (u, v) => fmtBps(v) },
            { label: 'Keluar', stroke: inf, fill: `${inf}30`, width: 1.4, value: (u, v) => fmtBps(v) },
        ], [d.t, s.in, neg(s.out)], fmtBps, { scales: { y: { range: sym } }, hooks: p95Hook(d.stats) });

        const pct = (a) => a.map((v) => (v == null || !d.speed ? null : (v / d.speed) * 100));
        make('util', [
            { label: 'Masuk', stroke: acc, width: 1.4, value: (u, v) => (v == null ? '–' : `${num(v)}%`) },
            { label: 'Keluar', stroke: inf, width: 1.4, value: (u, v) => (v == null ? '–' : `${num(v)}%`) },
        ], [d.t, pct(s.in), pct(s.out)], (v) => `${num(v, 0)}%`, { scales: { y: { range: (u, min, max) => [0, Math.max(10, Math.ceil(max / 10) * 10)] } } });

        make('pkts', [
            { label: 'Masuk unicast', stroke: acc, fill: `${acc}26`, width: 1.3, value: (u, v) => fmtPps(v) },
            { label: 'Masuk non-unicast', stroke: css('--warn'), width: 1.3, value: (u, v) => fmtPps(v) },
            { label: 'Keluar unicast', stroke: inf, fill: `${inf}26`, width: 1.3, value: (u, v) => fmtPps(v) },
            { label: 'Keluar non-unicast', stroke: css('--crit'), width: 1.3, value: (u, v) => fmtPps(v) },
        ], [d.t, s.in_ucast, add(s.in_mcast, s.in_bcast), neg(s.out_ucast), neg(add(s.out_mcast, s.out_bcast))], fmtPps, { scales: { y: { range: sym } } });

        make('errs', [
            { label: 'Error masuk', stroke: css('--crit'), width: 1.4, value: (u, v) => fmtRate(v) },
            { label: 'Error keluar', stroke: css('--warn'), width: 1.4, value: (u, v) => fmtRate(v) },
            { label: 'Discard masuk', stroke: acc, width: 1.2, dash: [4, 3], value: (u, v) => fmtRate(v) },
            { label: 'Discard keluar', stroke: inf, width: 1.2, dash: [4, 3], value: (u, v) => fmtRate(v) },
        ], [d.t, s.in_err, s.out_err, s.in_disc, s.out_disc], (v) => num(v, v >= 10 ? 0 : 2), { scales: { y: { range: (u, min, max) => [0, Math.max(max * 1.15, 1)] } } });

        for (const dir of ['in', 'out']) {
            const st = d.stats[dir];
            const set = (k, txt) => { $(`[data-pg-stat="${dir}.${k}"]`, root).textContent = txt; };
            ['now', 'avg', 'max', 'p95'].forEach((k) => set(k, fmtBps(st[k])));
            set('bytes', si(st.bytes, BYTES));
            set('errors', fmtCount(st.errors));
            set('discards', fmtCount(st.discards));
        }
    };

    const load = async () => {
        const u = new URL(root.dataset.url, location.origin);
        u.searchParams.set('range', range);
        root.style.opacity = '.55';
        try {
            const r = await fetch(u, { headers: { Accept: 'application/json' } });
            if (r.ok) draw(await r.json());
        } finally {
            root.style.opacity = '';
        }
    };

    $$('[data-pg-range]', root).forEach((b) => b.addEventListener('click', () => {
        range = b.dataset.pgRange;
        $$('[data-pg-range]', root).forEach((x) => x.classList.toggle('on', x === b));
        $('[data-pg-range-label]', root).textContent = b.textContent;
        load();
    }));
    window.addEventListener('resize', () => Object.entries(plots).forEach(([k, p]) => p.setSize({ width: $(`[data-pg-chart="${k}"]`, root).clientWidth, height: 220 })));
    window.addEventListener('ant-theme', () => last && draw(last));
    $('[data-port-jump]')?.addEventListener('change', (e) => { location.href = e.target.value; });
    load();
    setInterval(() => { if (!document.hidden && (range === '6h' || range === '24h')) load(); }, 60000);
}
