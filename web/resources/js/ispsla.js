// Grafik laporan SLA link ISP. Satu ukuran per grafik (tanpa sumbu ganda): ketersediaan, latensi, jitter, loss
// harian sebagai batang/garis kecil berdampingan, lalu deret detail dengan pita gangguan. Garis putus-putus = target.
import uPlot from 'uplot';

const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const num = (v, d = 2) => (v == null ? '' : Number(v).toFixed(d).replace('.', ','));
const two = (n) => String(n).padStart(2, '0');
const DAYS = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

function thresholdLines(lines) {
    return (u) => {
        const { ctx } = u;
        ctx.save();
        lines.forEach(([v, color]) => {
            if (v == null) return;
            const y = Math.round(u.valToPos(v, 'y', true));
            if (y < u.bbox.top || y > u.bbox.top + u.bbox.height) return;
            ctx.strokeStyle = color;
            ctx.lineWidth = 1.5 * devicePixelRatio;
            ctx.setLineDash([6 * devicePixelRatio, 4 * devicePixelRatio]);
            ctx.beginPath();
            ctx.moveTo(u.bbox.left, y);
            ctx.lineTo(u.bbox.left + u.bbox.width, y);
            ctx.stroke();
        });
        ctx.restore();
    };
}

function badBands(t, bad, color) {
    return (u) => {
        const { ctx } = u;
        ctx.save();
        ctx.fillStyle = color;
        const step = t.length > 1 ? t[1] - t[0] : 60;
        bad.forEach((b, i) => {
            if (!b) return;
            const x0 = u.valToPos(t[i] - step, 'x', true);
            const x1 = u.valToPos(t[i], 'x', true);
            ctx.fillRect(x0, u.bbox.top, Math.max(3 * devicePixelRatio, x1 - x0), u.bbox.height);
        });
        ctx.restore();
    };
}

export function initIspSla() {
    const root = document.querySelector('[data-isp-report]');
    if (!root) return;
    const R = JSON.parse(root.dataset.ispReport);
    // tampilkan jam menurut zona waktu server (WIB/WITA), bukan zona waktu browser
    const tzDate = (ts) => uPlot.tzDate(new Date(ts * 1000), R.tz || 'UTC');
    const parts = (v) => tzDate(v);
    const plots = [];
    const width = (el) => Math.max(280, el.clientWidth);
    const axis = (fmt) => ({ stroke: css('--muted'), grid: { stroke: css('--line'), width: 1 }, ticks: { show: false }, values: (u, vals) => vals.map(fmt), size: 52 });
    const accent = css('--accent');
    const target = css('--ink-2');

    const dayT = R.days.map((d) => Math.floor(new Date(`${d.date}T12:00:00`).getTime() / 1000));
    const dayLabel = (v) => { const d = parts(v); return `${DAYS[d.getDay()]} ${d.getDate()}`; };

    function make(el, opts, data) {
        const p = new uPlot({ width: width(el), height: 190, cursor: { drag: { x: false, y: false } }, legend: { live: true }, ...opts }, data, el);
        plots.push([p, el]);
    }

    function daily(key, el, unit, tgt, digits, bars = true) {
        const vals = R.days.map((d) => d[key]);
        const max = Math.max(tgt ?? 0, ...vals.filter((v) => v != null));
        make(el, {
            scales: { x: { time: false, range: () => [dayT[0] - 43200, dayT[dayT.length - 1] + 43200] },
                y: { range: key === 'availability' ? () => [Math.min(95, ...vals.filter((v) => v != null).map((v) => Math.floor(v - 1))), 100.5] : () => [0, max > 0 ? max * 1.15 : 1] } },
            axes: [{ ...axis(dayLabel), size: 30, splits: () => dayT }, axis((v) => num(v, digits))],
            series: [{ label: 'Hari', value: (u, v) => (v == null ? '' : dayLabel(v)) },
                { label: unit, stroke: accent, fill: bars ? accent : undefined, width: bars ? 0 : 2,
                    paths: bars ? uPlot.paths.bars({ size: [0.55, 48], radius: 0.15 }) : undefined, points: { show: !bars, size: 8, fill: accent },
                    value: (u, v, si, idx) => (idx == null ? '' : v == null ? 'tanpa data' : `${num(v, digits)} ${unit}`) }],
            hooks: { draw: [thresholdLines(key === 'availability' ? [[R.target, target], [R.warn, css('--crit')]] : [[tgt, target]])] },
        }, [dayT, vals]);
    }

    function detail(key, el, unit, tgt, digits) {
        const t = R.detail.t;
        const vals = R.detail[key];
        const max = Math.max(tgt ?? 0, ...vals.filter((v) => v != null));
        const fmtT = (v) => { const d = parts(v); return `${two(d.getDate())}/${two(d.getMonth() + 1)} ${two(d.getHours())}.${two(d.getMinutes())}`; };
        const tick = (v) => { const d = parts(v); return d.getHours() === 0 && d.getMinutes() === 0 ? `${DAYS[d.getDay()]} ${d.getDate()}` : `${two(d.getHours())}.${two(d.getMinutes())}`; };
        make(el, {
            tzDate,
            scales: { x: { time: true }, y: { range: () => [0, max > 0 ? max * 1.1 : 1] } },
            axes: [{ ...axis(tick), size: 30 }, axis((v) => num(v, digits))],
            series: [{ label: 'Waktu', value: (u, v) => (v == null ? '' : fmtT(v)) },
                { label: unit, stroke: accent, width: 1.5, points: { show: false }, value: (u, v, si, idx) => (idx == null ? '' : v == null ? 'tanpa data' : `${num(v, digits)} ${unit}`) }],
            hooks: { drawClear: [badBands(t, R.detail.bad, css('--crit-soft'))], draw: [thresholdLines([[tgt, target]])] },
        }, [t, vals]);
    }

    const at = (k) => root.querySelector(`[data-isp-plot="${k}"]`);
    if (R.days.length) {
        daily('availability', at('sla'), '%', null, 2, false);
        daily('latency', at('d-latency'), 'ms', R.latency, 1);
        daily('jitter', at('d-jitter'), 'ms', R.jitter, 1);
        daily('loss', at('d-loss'), '%', R.loss, 2);
    }
    if (R.detail.t.length) {
        detail('loss', at('loss'), '%', R.lossDown, 1);
        detail('rtt', at('latency'), 'ms', R.latency, 0);
        detail('jitter', at('jitter'), 'ms', R.jitter, 1);
    }
    window.addEventListener('resize', () => plots.forEach(([p, el]) => p.setSize({ width: width(el), height: 190 })));
    window.addEventListener('ant-theme', () => window.location.reload());
}
