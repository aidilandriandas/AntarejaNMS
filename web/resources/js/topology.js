// Peta topologi ANTAREJA (vis-network). Dipakai halaman Topologi dan Layar NOC.
import { Network } from 'vis-network/standalone';
import { DataSet } from 'vis-data/standalone';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

export const bps = (v) => {
    if (v == null || Number.isNaN(v)) return '';
    const u = [['G', 1e9], ['M', 1e6], ['K', 1e3]].find(([, d]) => v >= d);
    return u ? `${(v / u[1]).toFixed(v / u[1] >= 100 ? 0 : 1).replace('.', ',')}${u[0]}` : `${Math.round(v)}`;
};

const SIZE = { core: 26, distribution: 22, firewall: 22, ptp: 18, wireless: 16, access: 18, server: 18, nvr: 16, ups: 14, cctv: 12, other: 14 };
const STATE_LABEL = { up: 'Online', problem: 'Ada problem', down: 'DOWN', unknown: 'Menunggu', maintenance: 'Maintenance', external: 'Belum terdaftar (dari LLDP/CDP)' };

/**
 * @param {HTMLElement} el
 * @param {{dataUrl: () => string, liveUrl?: string, positionsUrl?: string, dark?: boolean, live?: boolean,
 *          onStats?: (s) => void, onSelect?: (node) => void}} opt
 */
export function createTopology(el, opt) {
    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const palette = () => ({
        up: css('--ok') || '#0f9f6e', problem: css('--warn') || '#b7791f', down: css('--crit') || '#d92d3a',
        unknown: css('--off') || '#8a96a3', maintenance: css('--info') || '#2463b5', external: css('--off') || '#8a96a3',
        ink: css('--ink') || '#14202b', muted: css('--muted') || '#6b7885', line: css('--line-strong') || '#cbd3db', bg: css('--surface') || '#fff',
    });
    let P = palette();
    const nodes = new DataSet();
    const edges = new DataSet();
    let graph = { nodes: [], edges: [] };
    let pulse = false;
    let freeLayout = false; // "Susun otomatis": semua node ikut simulasi lagi
    const live = new Map(); // edgeId -> {in, out, at, inBps, outBps}

    const network = new Network(el, { nodes, edges }, {
        autoResize: true,
        interaction: { hover: true, tooltipDelay: 120, multiselect: false, navigationButtons: false, keyboard: false },
        physics: { solver: 'forceAtlas2Based', forceAtlas2Based: { gravitationalConstant: -60, springLength: 120, avoidOverlap: 0.6 },
            stabilization: { iterations: 250 } },
        nodes: { shape: 'dot', borderWidth: 3, font: { face: 'IBM Plex Sans', size: 13 } },
        edges: { smooth: { type: 'continuous' }, font: { face: 'IBM Plex Mono', size: 11, strokeWidth: 3, align: 'top' }, selectionWidth: 2 },
    });

    const nodeStyle = (n) => {
        const c = P[n.state] || P.unknown;
        const ext = n.state === 'external';
        const big = pulse && n.state === 'down';
        return {
            id: n.id,
            label: n.label,
            size: (SIZE[n.category] || 14) + (big ? 6 : 0),
            color: { background: ext ? P.bg : c, border: ext ? P.muted : c, highlight: { background: c, border: P.ink }, hover: { background: c, border: P.ink } },
            borderWidth: n.state === 'down' ? (big ? 8 : 4) : 3,
            shapeProperties: { borderDashes: ext ? [4, 3] : false },
            font: { color: n.state === 'down' ? P.down : (ext ? P.muted : P.ink), face: 'IBM Plex Sans', size: 13, strokeWidth: 4, strokeColor: P.bg },
            title: [n.label, n.host, n.site ? `Site ${n.site}` : null, STATE_LABEL[n.state], n.reason, n.rtt != null ? `RTT ${Number(n.rtt).toFixed(1)} ms` : null, n.platform]
                .filter(Boolean).join('\n'),
            // posisi tersimpan: diam di tempat; node baru tetap disusun otomatis di sekitarnya
            ...(n.x != null && !freeLayout ? { x: n.x, y: n.y, physics: false } : { physics: true }),
        };
    };

    const edgeStyle = (e) => {
        const l = live.get(e.id);
        const t = e.traffic;
        const inB = l?.inBps ?? t?.in;
        const outB = l?.outBps ?? t?.out;
        const util = t && t.speed ? Math.min(100, Math.max(inB || 0, outB || 0) / t.speed * 100) : null;
        let color = P.line;
        let width = 2;
        if (e.down) color = P.down;
        else if (util != null) {
            color = util >= 90 ? P.down : util >= 70 ? P.problem : P.up;
            width = 2 + Math.min(6, util / 15);
        }
        const ports = [e.label_a, e.label_b].filter(Boolean).join(' ↔ ');
        return {
            id: e.id, from: e.from, to: e.to,
            color: { color, highlight: P.ink, hover: P.ink, opacity: e.logical || e.external ? 0.6 : 1 },
            width,
            dashes: e.down || e.logical ? [6, 6] : (e.external ? [2, 4] : false),
            label: opt.live && (inB != null || outB != null) && !e.down ? `↓${bps(inB)} ↑${bps(outB)}bps` : undefined,
            font: { color: P.muted, strokeColor: P.bg },
            title: [ports || (e.logical ? 'Hubungan induk (tanpa data port)' : 'Port tidak diketahui'),
                `Sumber: ${e.sources.join(', ').replace('induk', 'perangkat induk').replace('manual', 'manual')}`,
                util != null ? `Pemakaian ${util.toFixed(1)}% dari ${bps(t.speed)}bps` : null,
                inB != null ? `Masuk ${bps(inB)}bps, keluar ${bps(outB)}bps` : null, e.note, e.down ? 'PUTUS / salah satu ujung down' : null]
                .filter(Boolean).join('\n'),
        };
    };

    const render = () => {
        nodes.update(graph.nodes.map(nodeStyle));
        edges.update(graph.edges.map(edgeStyle));
    };

    let first = true;
    async function load() {
        const r = await fetch(opt.dataUrl(), { headers: { Accept: 'application/json' } });
        if (r.status === 401 || r.status === 419) throw new Error('sesi');
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        graph = await r.json();
        const nIds = new Set(graph.nodes.map((n) => n.id));
        const eIds = new Set(graph.edges.map((e) => e.id));
        nodes.remove(nodes.getIds().filter((id) => !nIds.has(id)));
        edges.remove(edges.getIds().filter((id) => !eIds.has(id)));
        render();
        if (first) {
            first = false;
            network.once('stabilized', () => fit(false));
            setTimeout(() => fit(false), 500);
        }
        opt.onStats?.(graph.stats, graph);
        return graph;
    }

    async function pollLive() {
        if (!opt.live || !opt.liveUrl) return;
        const ports = graph.edges.filter((e) => e.traffic && !e.down).slice(0, 60);
        if (!ports.length) return;
        const r = await fetch(opt.liveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ ports: ports.map((e) => ({ device_id: e.traffic.device_id, if_index: e.traffic.if_index })) }),
        });
        if (!r.ok) return;
        const { samples } = await r.json();
        const byPort = new Map(samples.map((s) => [`${s.device_id}:${s.if_index}`, s]));
        ports.forEach((e) => {
            const s = byPort.get(`${e.traffic.device_id}:${e.traffic.if_index}`);
            if (!s) return;
            const prev = live.get(e.id);
            const cur = { in: s.in, out: s.out, at: s.at };
            if (prev && s.at > prev.at && s.in >= prev.in && s.out >= prev.out) {
                const dt = s.at - prev.at;
                cur.inBps = (s.in - prev.in) * 8 / dt;
                cur.outBps = (s.out - prev.out) * 8 / dt;
            } else if (prev) {
                cur.inBps = prev.inBps;
                cur.outBps = prev.outBps;
            }
            live.set(e.id, cur);
        });
        edges.update(graph.edges.map(edgeStyle));
    }

    // Denyut untuk perangkat down
    setInterval(() => {
        if (document.hidden || !graph.nodes.some((n) => n.state === 'down')) return;
        pulse = !pulse;
        nodes.update(graph.nodes.filter((n) => n.state === 'down').map(nodeStyle));
    }, 800);

    network.on('click', (p) => {
        if (p.nodes.length) opt.onSelect?.(graph.nodes.find((n) => n.id === p.nodes[0]));
    });
    window.addEventListener('ant-theme', () => { P = palette(); render(); });

    function fit(animate = true) {
        network.fit({ animation: animate ? { duration: 400 } : false, maxZoomLevel: 1.6, minZoomLevel: 0.05 });
    }

    let liveTimer = null;
    return {
        network,
        load,
        fit,
        every(sec) { setInterval(() => { if (!document.hidden) load().catch(() => {}); }, sec * 1000); },
        startLive(sec = 5) {
            opt.live = true;
            clearInterval(liveTimer);
            pollLive().catch(() => {});
            liveTimer = setInterval(() => { if (!document.hidden) pollLive().catch(() => {}); }, sec * 1000);
        },
        stopLive() { opt.live = false; clearInterval(liveTimer); live.clear(); render(); },
        setPhysics(on) { freeLayout = on; nodes.update(graph.nodes.map(nodeStyle)); },
        async savePositions() {
            const pos = network.getPositions();
            const out = {};
            Object.entries(pos).forEach(([id, p]) => { if (/^\d+$/.test(id)) out[id] = [p.x, p.y]; });
            const r = await fetch(opt.positionsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ positions: out }),
            });
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            graph.nodes.forEach((n) => { const p = pos[n.id]; if (p && /^\d+$/.test(String(n.id))) { n.x = p.x; n.y = p.y; } });
            freeLayout = false;
            nodes.update(graph.nodes.map(nodeStyle));
        },
    };
}
