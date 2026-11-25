// Grafik flow: laju per aplikasi / IP sumber / IP tujuan, bertumpuk. Riwayat dari ringkasan 5 menit, atau live
// (potongan 10 detik dari collector, diambil tiap 10 detik). Warna mengikuti entitas, bukan peringkat: kunci yang
// pertama terlihat mendapat slot palet berikutnya dan tetap selama halaman terbuka.
import uPlot from 'uplot';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const num = (v, d = 1) => v.toFixed(d).replace('.', ',');
const bps = (v) => {
    if (v == null) return '';
    const u = [['Gbps', 1e9], ['Mbps', 1e6], ['Kbps', 1e3]].find(([, d]) => v >= d);
    return u ? `${num(v / u[1], v / u[1] >= 100 ? 0 : 1)} ${u[0]}` : `${Math.round(v)} bps`;
};
const two = (n) => String(n).padStart(2, '0');

export function initFlowChart() {
    const root = $('[data-flow-chart]');
    if (!root) return;
    const plotEl = $('[data-flow-plot]', root);
    const status = $('[data-flow-status]', root);
    const slots = new Map(); // kunci -> slot palet 1..8
    let per = 'app';
    let live = false;
    let timer = null;
    let plot = null;
    let raw = [];

    const colorOf = (key) => {
        if (key === 'lainnya') return css('--muted');
        if (!slots.has(key)) slots.set(key, (slots.size % 8) + 1);
        return css(`--color-cat-${slots.get(key)}`);
    };
    const alpha = (c, a) => (c.startsWith('#') && c.length === 7 ? c + Math.round(a * 255).toString(16).padStart(2, '0') : c);

    function draw(j) {
        const t = j.t || [];
        const series = j.series || [];
        if (!t.length || !series.length || series.every((s) => s.values.every((v) => !v))) {
            plot?.destroy();
            plot = null;
            plotEl.innerHTML = '';
            status.textContent = live
                ? 'Belum ada flow dalam 5 menit terakhir untuk port dan arah ini. Router mengirim data saat flow selesai atau saat batas active timeout.'
                : 'Tidak ada flow dalam rentang ini.';
            return;
        }
        status.textContent = '';
        raw = series.map((s) => s.values);
        // bertumpuk: tiap seri digambar sebagai jumlah kumulatif, legenda menampilkan nilai aslinya
        const cum = [];
        series.forEach((s, i) => cum.push(s.values.map((v, k) => (v || 0) + (i ? cum[i - 1][k] : 0))));
        const timeLabel = (v) => {
            const d = new Date(v * 1000);
            return `${two(d.getHours())}.${two(d.getMinutes())}${live ? `:${two(d.getSeconds())}` : ''}`;
        };
        const opts = {
            width: Math.max(320, plotEl.clientWidth), height: 280,
            cursor: { drag: { x: false, y: false } },
            scales: { x: { time: true }, y: { range: (u, min, max) => [0, max > 0 ? max * 1.08 : 1] } },
            axes: [
                { stroke: css('--muted'), grid: { stroke: css('--line'), width: 1 }, ticks: { show: false }, values: (u, vals) => vals.map(timeLabel) },
                { stroke: css('--muted'), grid: { stroke: css('--line'), width: 1 }, ticks: { show: false }, size: 76, values: (u, vals) => vals.map((v) => bps(v)) },
            ],
            series: [
                { label: 'Waktu', value: (u, v) => (v == null ? '' : timeLabel(v)) },
                ...series.map((s) => {
                    const c = colorOf(s.key);
                    return { label: s.label, stroke: c, width: 2, fill: alpha(c, 0.28), points: { show: false },
                        value: (u, v, si, idx) => (idx == null ? '' : bps(raw[si - 1][idx])) };
                }),
            ],
            bands: series.slice(1).map((s, i) => ({ series: [i + 2, i + 1] })),
            legend: { live: true },
        };
        plot?.destroy();
        plotEl.innerHTML = '';
        plot = new uPlot(opts, [t, ...cum], plotEl);
    }

    const query = () => new URLSearchParams({ perangkat: root.dataset.dev, port: root.dataset.if, arah: root.dataset.dir, per, rentang: root.dataset.range });

    async function load() {
        const url = `${live ? root.dataset.liveUrl : root.dataset.dataUrl}?${query()}`;
        try {
            const r = await fetch(url, { headers: { Accept: 'application/json' } });
            const j = await r.json();
            if (!r.ok) throw new Error(j.error || `HTTP ${r.status}`);
            draw(j);
        } catch (e) {
            status.textContent = `Grafik tidak bisa dimuat: ${e.message}`;
        }
    }

    $$('[data-flow-per]', root).forEach((b) => b.addEventListener('click', () => {
        per = b.dataset.flowPer;
        $$('[data-flow-per]', root).forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        slots.clear();
        load();
    }));
    $('[data-flow-live]', root).addEventListener('change', (e) => {
        live = e.target.checked;
        clearInterval(timer);
        if (live) timer = setInterval(() => { if (!document.hidden) load(); }, 10000);
        load();
    });
    window.addEventListener('resize', () => plot?.setSize({ width: Math.max(320, plotEl.clientWidth), height: 280 }));
    window.addEventListener('ant-theme', load);
    load();
}
