// Peta topologi ANTAREJA (vis-network, node digambar sebagai kartu). Dipakai halaman Topologi dan Layar NOC.
import { Network } from 'vis-network/standalone';
import { DataSet } from 'vis-data/standalone';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

export const bps = (v) => {
    if (v == null || Number.isNaN(v)) return '';
    const u = [['G', 1e9], ['M', 1e6], ['K', 1e3]].find(([, d]) => v >= d);
    return u ? `${(v / u[1]).toFixed(v / u[1] >= 100 ? 0 : 1).replace('.', ',')}${u[0]}` : `${Math.round(v)}`;
};

export const STATE_LABEL = { up: 'Online', problem: 'Ada problem', down: 'Down', unknown: 'Menunggu', maintenance: 'Maintenance', external: 'Belum terdaftar (dari LLDP/CDP)' };
// Penanda kedua selain warna (tidak bergantung pada warna saja)
const STATE_TAG = { problem: 'PROBLEM', down: 'DOWN', maintenance: 'MAINT', unknown: 'MENUNGGU', external: 'BARU' };

// Ikon garis 24×24, sama dengan komponen <x-icon> di aplikasi
const R = (x, y, w, h, r) => ({ rect: [x, y, w, h, r] });
const C = (x, y, r) => ({ circle: [x, y, r] });
const ICON = {
    router: [R(3, 12, 18, 7, 1.5), 'M7 15.5h.01M11 15.5h.01', 'M8 12V8M16 12V5'],
    switch: [R(2, 8, 20, 8, 1.5), 'M6 12h1M9.5 12h1M13 12h1M16.5 12h1'],
    server: [R(3, 4, 18, 7, 1.5), R(3, 13, 18, 7, 1.5), 'M7 7.5h.01M7 16.5h.01'],
    wifi: ['M2.5 9a14 14 0 0 1 19 0', 'M6 12.5a9 9 0 0 1 12 0', 'M9.5 16a4 4 0 0 1 5 0', 'M12 19.5h.01'],
    antenna: ['M12 10v11M8 21h8', 'M8.5 6.5a5 5 0 0 0 0 7M15.5 6.5a5 5 0 0 1 0 7', 'M5.5 3.5a9 9 0 0 0 0 13M18.5 3.5a9 9 0 0 1 0 13', C(12, 10, 1.3)],
    camera: ['M3 8h12v9H3z', 'M15 11l6-3v9l-6-3'],
    nvr: [R(3, 6, 18, 12, 1.5), 'M7 14h.01M10 14h7', C(12, 10, 1.5)],
    shield: ['M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z'],
    battery: [R(3, 7, 16, 10, 1.5), 'M21 10.5v3', 'M7 10v4M10.5 10v4'],
    box: ['M3 7l9-4 9 4v10l-9 4-9-4z', 'M3 7l9 4 9-4M12 11v10'],
};
const CAT_ICON = { core: 'router', distribution: 'switch', access: 'switch', wireless: 'wifi', ptp: 'antenna', firewall: 'shield',
    server: 'server', nvr: 'nvr', cctv: 'camera', ups: 'battery', other: 'box' };
const iconPaths = {};
const iconPath = (name) => {
    if (iconPaths[name]) return iconPaths[name];
    const p = new Path2D();
    (ICON[name] || ICON.box).forEach((s) => {
        if (typeof s === 'string') p.addPath(new Path2D(s));
        else if (s.rect) { const [x, y, w, h, r] = s.rect; p.roundRect(x, y, w, h, r); }
        else if (s.circle) { const [x, y, r] = s.circle; p.moveTo(x + r, y); p.arc(x, y, r, 0, Math.PI * 2); }
    });
    return (iconPaths[name] = p);
};

const CARD_W = 200;
const CARD_H = 48;

/**
 * @param {HTMLElement} el
 * @param {{dataUrl: () => string, liveUrl?: string, positionsUrl?: string, dark?: boolean, live?: boolean,
 *          onStats?: (s, g) => void, onSelect?: (node) => void, onData?: (g) => void}} opt
 */
// Pita beban link: <10, 10-30, 30-50, 50-70, 70-90, >=90 persen -> indeks warna --load-1..5 (di bawah 10 memakai pita 1)
export function loadBand(util) {
    return util >= 90 ? 4 : util >= 70 ? 3 : util >= 50 ? 2 : util >= 30 ? 1 : 0;
}
const pct = (b, speed) => (b == null || !speed ? '?' : `${Math.round(Math.min(100, b / speed * 100))}%`);

export function createTopology(el, opt) {
    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const dark = () => opt.dark || document.documentElement.dataset.theme === 'dark' || !css('--surface');
    const palette = () => {
        const d = dark();
        return {
            up: css('--ok') || (d ? '#45c991' : '#0c7a52'), problem: css('--warn') || (d ? '#e7b24c' : '#8a5a00'),
            down: css('--crit') || (d ? '#ff7a83' : '#c4232f'), unknown: css('--off') || '#8593a0',
            maintenance: css('--info') || (d ? '#79acf2' : '#1f5aa6'), external: css('--off') || '#8593a0',
            accent: css('--accent') || (d ? '#4fc7b9' : '#0b6b63'),
            ink: css('--ink') || (d ? '#e6ecf1' : '#121c25'), ink2: css('--ink-2') || (d ? '#bcc7d1' : '#3f4c58'),
            muted: css('--muted') || (d ? '#93a2b0' : '#5d6a76'), line: css('--line-strong') || (d ? '#34434f' : '#b9c3cc'),
            rule: css('--line') || (d ? '#1f2b36' : '#dde3e8'), bg: css('--surface') || (d ? '#131c24' : '#ffffff'),
            well: css('--surface-2') || (d ? '#1c2832' : '#eef1f3'),
        };
    };
    let P = palette();
    const loadColors = () => [1, 2, 3, 4, 5].map((i) => css(`--load-${i}`) || P.accent);
    let LC = loadColors();
    const nodes = new DataSet();
    const edges = new DataSet();
    let graph = { nodes: [], edges: [] };
    const byId = new Map();
    let pulse = false;
    let freeLayout = false;
    const live = new Map(); // edgeId -> {in, out, at, inBps, outBps}

    el.tabIndex = el.tabIndex >= 0 ? el.tabIndex : 0; // fokus keyboard: panah menggeser, +/- zoom
    const network = new Network(el, { nodes, edges }, {
        autoResize: true,
        interaction: { hover: true, tooltipDelay: 150, multiselect: false, navigationButtons: false,
            keyboard: { enabled: true, bindToWindow: false, speed: { x: 12, y: 12, zoom: 0.03 } } },
        physics: { solver: 'forceAtlas2Based', forceAtlas2Based: { gravitationalConstant: -160, springLength: 260, springConstant: 0.04, avoidOverlap: 1 },
            stabilization: { iterations: 300 } },
        // size = setengah lebar kartu: fisika (hindari tumpang tindih) memakai radius ini sebelum kartu pertama kali digambar
        nodes: { shape: 'custom', ctxRenderer: drawCard, size: CARD_W / 2 },
        edges: { smooth: { enabled: true, type: 'continuous', roundness: 0.1 },
            font: { face: 'IBM Plex Mono', size: 11, strokeWidth: 4, align: 'horizontal' }, selectionWidth: 1.5, hoverWidth: 0.5 },
    });

    const short = (ctx, s, max) => {
        if (ctx.measureText(s).width <= max) return s;
        let t = s;
        while (t.length > 1 && ctx.measureText(`${t}…`).width > max) t = t.slice(0, -1);
        return `${t}…`;
    };

    function drawCard({ ctx, id, x, y, state: { selected, hover } }) {
        const n = byId.get(id);
        return {
            drawNode() {
                if (!n) return;
                const st = n.state || 'unknown';
                const c = P[st] || P.unknown;
                const ext = st === 'external';
                const left = x - CARD_W / 2;
                const top = y - CARD_H / 2;
                ctx.save();
                // kartu
                ctx.beginPath();
                ctx.roundRect(left, top, CARD_W, CARD_H, 6);
                ctx.fillStyle = P.bg;
                ctx.fill();
                ctx.setLineDash(ext ? [4, 3] : []);
                ctx.lineWidth = st === 'down' ? (pulse ? 3.5 : 2) : (selected ? 2 : 1);
                ctx.strokeStyle = st === 'down' ? P.down : (selected || hover ? P.ink2 : P.line);
                ctx.stroke();
                ctx.setLineDash([]);
                // pita status kiri
                if (!ext) {
                    ctx.beginPath();
                    ctx.roundRect(left, top, 4, CARD_H, [6, 0, 0, 6]);
                    ctx.fillStyle = c;
                    ctx.fill();
                }
                // petak ikon
                const ix = left + 12;
                const iy = y - 15;
                ctx.beginPath();
                ctx.roundRect(ix, iy, 30, 30, 5);
                ctx.fillStyle = P.well;
                ctx.fill();
                ctx.save();
                ctx.translate(ix + 5, iy + 5);
                ctx.scale(20 / 24, 20 / 24);
                ctx.lineWidth = 1.8;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = st === 'down' ? P.down : P.ink2;
                ctx.stroke(iconPath(ext ? 'box' : (CAT_ICON[n.category] || 'box')));
                ctx.restore();
                // teks: nama selebar kartu; status (teks untuk selain online) sebaris dengan alamat
                const tx = ix + 40;
                const right = left + CARD_W - 10;
                const tag = STATE_TAG[st];
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = ext ? P.muted : P.ink;
                ctx.font = '600 12.5px "IBM Plex Sans", sans-serif';
                ctx.fillText(short(ctx, n.label || '', right - tx - (tag ? 0 : 12)), tx, y - 2);
                let hostMax = right - tx;
                if (tag) {
                    ctx.font = '600 9.5px "IBM Plex Sans", sans-serif';
                    const w = ctx.measureText(tag).width;
                    hostMax -= w + 8;
                    ctx.fillStyle = st === 'down' ? P.down : st === 'problem' ? P.problem : st === 'maintenance' ? P.maintenance : P.muted;
                    ctx.fillText(tag, right - w, y + 13);
                } else {
                    ctx.beginPath();
                    ctx.arc(right - 2, top + 11, 3.5, 0, Math.PI * 2);
                    ctx.fillStyle = c;
                    ctx.fill();
                }
                ctx.fillStyle = P.muted;
                ctx.font = '400 11px "IBM Plex Mono", monospace';
                ctx.fillText(short(ctx, n.host || '', hostMax), tx, y + 13);
                ctx.restore();
            },
            nodeDimensions: { width: CARD_W, height: CARD_H },
        };
    }

    const nodeStyle = (n) => ({
        id: n.id,
        label: n.label,
        title: [n.label, n.host, n.site ? `Site ${n.site}` : null, STATE_LABEL[n.state], n.reason,
            n.rtt != null ? `RTT ${Number(n.rtt).toFixed(1)} ms` : null, n.platform].filter(Boolean).join('\n'),
        ...(n.x != null && !freeLayout && !crowded ? { x: n.x, y: n.y, physics: false } : { physics: true }),
    });

    const edgeInfo = (e) => {
        const l = live.get(e.id);
        const t = e.traffic;
        const inB = l?.inBps ?? t?.in;
        const outB = l?.outBps ?? t?.out;
        const util = t && t.speed ? Math.min(100, Math.max(inB || 0, outB || 0) / t.speed * 100) : null;
        const state = e.down ? 'putus' : e.external ? 'belum terdaftar' : e.logical ? 'hubungan induk' : util != null && util >= 90 ? 'penuh' : util != null && util >= 70 ? 'padat' : 'normal';
        return { inB, outB, util, state };
    };

    const edgeStyle = (e) => {
        const { inB, outB, util } = edgeInfo(e);
        let color = P.line;
        let width = 1.5;
        if (e.down) { color = P.down; width = 2; }
        else if (e.logical || e.external) color = P.muted;
        else if (util != null && opt.loadMode) {
            color = LC[loadBand(util)];
            width = 2.5 + Math.min(6, util / 14);
        } else if (util != null) {
            color = util >= 90 ? P.down : util >= 70 ? P.problem : P.accent;
            width = 2 + Math.min(5, util / 18);
        } else if (e.radio && e.radio.signal != null) {
            // Link radio: warna dari sinyal terlemah kedua arah (ambang sama dengan sensor sinyal)
            color = e.radio.signal <= -78 ? P.down : e.radio.signal <= -70 ? P.problem : P.accent;
        } else if (inB != null || e.sources.includes('lldp') || e.sources.includes('cdp') || e.sources.includes('wifi')) color = P.accent;
        const ports = [e.label_a, e.label_b].filter(Boolean).join(' ke ');
        return {
            id: e.id, from: e.from, to: e.to,
            color: { color, highlight: P.ink, hover: P.ink, opacity: e.logical || e.external ? 0.7 : 1 },
            width,
            dashes: e.down ? [8, 5] : e.logical ? [5, 5] : e.external ? [2, 4] : false,
            label: opt.loadMode && util != null && !e.down && t(e).speed
                ? `${pct(inB, t(e).speed)} / ${pct(outB, t(e).speed)}`
                : opt.live && (inB != null || outB != null) && !e.down ? `masuk ${bps(inB)} keluar ${bps(outB)}bps`
                    : e.radio && e.radio.signal != null && !e.down ? `${Math.round(e.radio.signal)} dBm` : undefined,
            font: { color: P.ink2, strokeColor: P.bg, background: undefined },
            title: [ports || (e.logical ? 'Hubungan induk (tanpa data port)' : 'Port tidak diketahui'),
                `Sumber: ${e.sources.join(', ').replace('induk', 'perangkat induk').replace('wifi', 'link radio')}`,
                util != null ? `Pemakaian ${util.toFixed(1)}% dari ${bps(t(e).speed)}bps` : null,
                inB != null ? `Masuk ${bps(inB)}bps, keluar ${bps(outB)}bps` : null,
                e.radio ? [`Radio ${e.radio.kind}`, e.radio.signal != null ? `sinyal terlemah ${Math.round(e.radio.signal)} dBm` : null,
                    e.radio.ccq != null ? `CCQ ${Math.round(e.radio.ccq)}%` : null, e.radio.distance_km ? `${String(e.radio.distance_km).replace('.', ',')} km` : null]
                    .filter(Boolean).join(', ') : null, e.note, e.down ? 'Putus: salah satu ujung down atau port down' : null]
                .filter(Boolean).join('\n'),
        };
    };
    const t = (e) => e.traffic || {};

    // Label port di dekat setiap ujung garis, tepat di luar kartu
    network.on('afterDrawing', (ctx) => {
        if (network.getScale() < 0.55) return;
        const pos = network.getPositions();
        ctx.save();
        ctx.font = '400 10.5px "IBM Plex Mono", monospace';
        ctx.textBaseline = 'middle';
        graph.edges.forEach((e) => {
            const a = pos[e.from];
            const b = pos[e.to];
            if (!a || !b) return;
            [[e.label_a, a, b], [e.label_b, b, a]].forEach(([txt, p, q]) => {
                if (!txt) return;
                const dx = q.x - p.x;
                const dy = q.y - p.y;
                const len = Math.hypot(dx, dy) || 1;
                // jarak dari pusat ke tepi kartu searah garis, lalu sedikit keluar
                const tEdge = Math.min(Math.abs((CARD_W / 2) / (dx || 1e-6)), Math.abs((CARD_H / 2) / (dy || 1e-6))) * len;
                const d = Math.min(tEdge + 26, len / 2 - 4);
                if (d <= tEdge) return; // kartu terlalu dekat: tidak ada ruang untuk label
                const lx = p.x + (dx / len) * d;
                const ly = p.y + (dy / len) * d;
                const label = txt.length > 18 ? `${txt.slice(0, 17)}…` : txt;
                const w = ctx.measureText(label).width + 10;
                ctx.beginPath();
                ctx.roundRect(lx - w / 2, ly - 8, w, 16, 4);
                ctx.fillStyle = P.bg;
                ctx.fill();
                ctx.lineWidth = 1;
                ctx.strokeStyle = e.down ? P.down : P.rule;
                ctx.stroke();
                ctx.fillStyle = e.down ? P.down : P.ink2;
                ctx.fillText(label, lx - w / 2 + 5, ly + 0.5);
            });
        });
        ctx.restore();
    });

    // Posisi tersimpan dari tata letak lama (node lingkaran kecil) membuat kartu bertumpuk. Bila begitu, semua posisi
    // tersimpan diabaikan dan peta disusun ulang (mengunci sebagian node membuat sisanya tidak bisa menghindar).
    let crowded = false;
    const findCrowded = () => {
        const fixed = graph.nodes.filter((n) => n.x != null);
        for (let i = 0; i < fixed.length; i++) {
            for (let j = i + 1; j < fixed.length; j++) {
                if (Math.abs(fixed[i].x - fixed[j].x) < CARD_W + 12 && Math.abs(fixed[i].y - fixed[j].y) < CARD_H + 12) return true;
            }
        }
        return false;
    };

    const render = () => {
        const was = crowded;
        crowded = graph.nodes.length <= 1500 && findCrowded();
        if (crowded !== was) opt.onCrowded?.(crowded);
        byId.clear();
        graph.nodes.forEach((n) => byId.set(n.id, n));
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
        opt.onData?.(graph);
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
            if (prev && s.at > prev.at && s.in >= prev.in && s.out >= prev.out && (s.in > prev.in || s.out > prev.out || s.at - prev.at >= 10)) {
                const dt = s.at - prev.at;
                cur.inBps = (s.in - prev.in) * 8 / dt;
                cur.outBps = (s.out - prev.out) * 8 / dt;
            } else if (prev) {
                // counter belum diperbarui agen SNMP: pertahankan sampel & laju sebelumnya
                live.set(e.id, prev);
                return;
            }
            live.set(e.id, cur);
        });
        edges.update(graph.edges.map(edgeStyle));
        opt.onData?.(graph);
    }

    // Denyut tepi kartu untuk perangkat down
    setInterval(() => {
        if (document.hidden || !graph.nodes.some((n) => n.state === 'down')) return;
        pulse = !pulse;
        network.redraw();
    }, 800);

    network.on('click', (p) => {
        if (p.nodes.length) opt.onSelect?.(byId.get(p.nodes[0]));
    });
    window.addEventListener('ant-theme', () => { P = palette(); LC = loadColors(); render(); network.redraw(); });

    function fit(animate = true) {
        network.fit({ animation: animate ? { duration: 400 } : false, maxZoomLevel: 1.4, minZoomLevel: 0.05 });
    }

    let liveTimer = null;
    return {
        network,
        load,
        fit,
        get graph() { return graph; },
        edgeInfo,
        focus(id) { network.selectNodes([id]); network.focus(id, { scale: 1.1, animation: { duration: 400 } }); opt.onSelect?.(byId.get(id)); },
        every(sec) { setInterval(() => { if (!document.hidden) load().catch(() => {}); }, sec * 1000); },
        startLive(sec = 5) {
            opt.live = true;
            clearInterval(liveTimer);
            pollLive().catch(() => {});
            liveTimer = setInterval(() => { if (!document.hidden) pollLive().catch(() => {}); }, sec * 1000);
        },
        stopLive() { opt.live = false; clearInterval(liveTimer); live.clear(); render(); },
        setLoadMode(on) { opt.loadMode = on; edges.update(graph.edges.map(edgeStyle)); },
        setPhysics(on) { freeLayout = on; nodes.update(graph.nodes.map(nodeStyle)); if (on) network.stabilize(200); },
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
