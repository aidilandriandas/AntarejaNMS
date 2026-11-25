// Layar NOC ANTAREJA: cek perubahan tiap 5 detik, data lengkap paling lambat tiap 15 detik, tanpa muat ulang halaman.
import { createTopology, bps } from './topology.js';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const root = $('[data-noc]');
const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* mode privat */ } },
};
const esc = (s) => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
const TONE = { crit: 'var(--crit)', warn: 'var(--warn)', info: 'var(--info)', ok: 'var(--ok)', muted: 'var(--off)' };

let serverOffset = 0;
let lastOk = Date.now();
let seen = null; // id problem yang sudah tampil (untuk kilatan)
let heard = null; // id problem High/Disaster baru yang sudah dibunyikan
let sig = null; // sidik keadaan terakhir dari server
let lastFull = Date.now();
let busy = false;
const site = $('[data-site]');
site.value = store.get('ant-noc-site') || '';

function ago(iso) {
    const s = Math.max(0, Math.floor((Date.now() + serverOffset - new Date(iso).getTime()) / 1000));
    if (s < 60) return `${s} dtk`;
    if (s < 3600) return `${Math.floor(s / 60)}m`;
    if (s < 86400) return `${Math.floor(s / 3600)}j ${Math.floor((s % 3600) / 60)}m`;
    return `${Math.floor(s / 86400)}h ${Math.floor((s % 86400) / 3600)}j`;
}
const hm = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false });

// ---------- Jam & indikator live ----------
function tick() {
    const now = new Date(Date.now() + serverOffset);
    $('[data-clock]').textContent = now.toLocaleTimeString('id-ID', { hour12: false });
    $('[data-date]').textContent = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).toUpperCase();
    const age = (Date.now() - lastOk) / 1000;
    const live = $('[data-live]');
    live.className = `n-live${age > 90 ? ' lost' : age > 40 ? ' stale' : ''}`;
    $('span', live).textContent = age > 90 ? 'TERPUTUS' : age > 40 ? 'TERTUNDA' : 'LIVE';
    $$('[data-since]').forEach((el) => { el.firstChild.textContent = ago(el.dataset.since); });
}
setInterval(tick, 1000);

// ---------- Suara (WebAudio, tanpa berkas). Browser hanya mengizinkan suara setelah satu klik. ----------
let audio = null;
let soundOn = store.get('ant-noc-sound') === 'on';
const soundBtn = $('[data-sound]');
const icon = (n) => `<svg class="i" viewBox="0 0 24 24" aria-hidden="true">${n === 'on'
    ? '<path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M16.5 8.5a5 5 0 0 1 0 7"/>'
    : '<path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M17 9.5l4 5M21 9.5l-4 5"/>'}</svg>`;
function renderSound() {
    soundBtn.className = `n-btn ${soundOn ? 'on' : 'hint'}`;
    soundBtn.innerHTML = soundOn ? `${icon('on')}<span>SUARA</span>` : `${icon('off')}<span>SUARA MATI, KLIK</span>`;
}
function beep(level) {
    if (!soundOn) return;
    try {
        audio = audio || new (window.AudioContext || window.webkitAudioContext)();
        if (audio.state === 'suspended') audio.resume();
        const t = audio.currentTime;
        const tones = level === 'crit' ? [880, 660, 880, 660, 880, 660] : [740, 740];
        tones.forEach((f, i) => {
            const o = audio.createOscillator();
            const g = audio.createGain();
            o.type = 'square';
            o.frequency.value = f;
            g.gain.setValueAtTime(0.0001, t + i * 0.22);
            g.gain.exponentialRampToValueAtTime(0.16, t + i * 0.22 + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.22 + 0.2);
            o.connect(g);
            g.connect(audio.destination);
            o.start(t + i * 0.22);
            o.stop(t + i * 0.22 + 0.21);
        });
    } catch (e) { /* audio tidak tersedia */ }
}
soundBtn.addEventListener('click', () => {
    soundOn = !soundOn;
    store.set('ant-noc-sound', soundOn ? 'on' : 'off');
    renderSound();
    if (soundOn) beep('warn'); // bunyi uji sekaligus membuka izin audio
});
renderSound();

// ---------- Tampilan tengah: dinding / topologi / otomatis ----------
let mode = store.get('ant-noc-view') || 'wall';
let showing = 'wall';
let rot = null;
let topo = null;
// Peta lokasi (Leaflet) dimuat saat pertama kali ditampilkan
const geoEl = $('[data-geo]');
let geo = null;
let geoTimer = null;
async function showGeo() {
    if (!geo) {
        const { createMap } = await import('./map.js');
        geo = createMap(geoEl, { csrf: () => '' });
    }
    geo.map.invalidateSize();
    await geo.load();
    clearInterval(geoTimer);
    geoTimer = setInterval(() => { if (!document.hidden && showing === 'geo') geo.load(); }, 30000);
}
const VIEWS = ['wall', 'map', ...(geoEl ? ['geo'] : [])];
const TITLES = { wall: 'Dinding status', map: 'Topologi jaringan', geo: 'Peta lokasi' };
function show(v) {
    if (!VIEWS.includes(v)) v = 'wall';
    showing = v;
    $$('[data-v]').forEach((el) => el.classList.toggle('hidden', el.dataset.v !== v));
    $('[data-view-title]').textContent = TITLES[v];
    if (v === 'map' && topo) setTimeout(() => topo.fit(false), 80);
    if (v === 'geo') setTimeout(() => showGeo().catch(() => {}), 50);
}
function setMode(m) {
    mode = m;
    store.set('ant-noc-view', m);
    $$('[data-view]').forEach((b) => b.classList.toggle('on', b.dataset.view === m));
    clearInterval(rot);
    if (m === 'auto') {
        show('wall');
        rot = setInterval(() => show(VIEWS[(VIEWS.indexOf(showing) + 1) % VIEWS.length]), 30000);
    } else {
        show(m);
    }
}
$$('[data-view]').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.view)));

// ---------- Render data ----------
function render(d) {
    serverOffset = new Date(d.time).getTime() - Date.now();
    const al = d.alarm;
    const alarm = $('[data-alarm]');
    alarm.className = `n-alarm ${al.level}`;
    alarm.innerHTML = `<svg class="i" viewBox="0 0 24 24" aria-hidden="true">${al.level === 'ok'
        ? '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5L16 9.5"/>'
        : '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>'}</svg><span>${esc(al.text)}</span>`;
    document.title = `${al.level === 'crit' ? '⚠ ' : ''}Layar NOC | ANTAREJA NMS`;

    // Siapa yang piket: nama dan jam selesai; kosong = beri tahu supaya ruang NOC tahu tidak ada yang dihubungi
    const oc = $('[data-oncall]');
    if (oc && d.oncall) {
        oc.className = `n-oncall${d.oncall.length ? '' : ' none'}`;
        oc.innerHTML = d.oncall.length
            ? `<small>PIKET</small><b>${d.oncall.map((o) => esc(o.name)).join(', ')}</b><small>SAMPAI ${esc(d.oncall[0].until)}</small>`
            : `<small>PIKET</small><b>TIDAK ADA</b>${d.oncall_next?.length ? `<small>BERIKUTNYA ${esc(d.oncall_next[0].name)} ${esc(d.oncall_next[0].from)}</small>` : ''}`;
    }

    // Suara: problem High/Disaster baru yang belum di-ACK & tidak ditekan (dari server, tidak terbatas daftar yang tampil)
    const ids = d.problems.map((p) => p.id);
    const fresh = d.fresh || [];
    if (heard !== null && fresh.some((id) => !heard.has(id))) beep('crit');
    heard = new Set([...(heard || []), ...fresh]);

    const k = d.kpi;
    const av = k.availability;
    const ka = $('[data-k-avail]');
    $('.val', ka).innerHTML = `${av === null ? '?' : av.toFixed(av === 100 ? 0 : 2).replace('.', ',')}<small>%</small>`;
    $('.meter', ka).className = `meter ${av === null || av >= 99 ? '' : av >= 95 ? 'warn' : 'crit'}`;
    $('.meter span', ka).style.width = `${av || 0}%`;
    $('.sub', ka).textContent = `${k.up} / ${k.up + k.down} perangkat online${k.unknown ? `, ${k.unknown} menunggu` : ''}`;
    $('[data-k-up] .val').textContent = k.up;
    $('[data-k-down] .val').textContent = k.down;
    $('[data-k-down]').className = `n-panel n-kpi ${k.down ? 'crit' : 'ok'}`;

    const max = Math.max(1, ...d.severity.map((s) => s.n));
    $('[data-sev]').innerHTML = d.severity.map((s) => `<li><span>${esc(s.label)}</span><span class="bar"><span style="width:${s.n ? Math.max(5, (s.n / max) * 100) : 0}%;background:${TONE[s.tone]}"></span></span><b class="${s.n ? '' : 'zero'}">${s.n}</b></li>`).join('');

    $('[data-ports]').innerHTML = d.ports.length ? d.ports.map((p) => {
        const c = p.util >= 90 ? 'var(--crit)' : p.util >= 70 ? 'var(--warn)' : 'var(--ok)';
        return `<li><a href="${p.url}" target="_blank" rel="noopener" style="display:block;color:inherit;text-decoration:none">
            <div class="r1"><span>${esc(p.device)}, ${esc(p.port)}</span><b style="color:${c}">${p.util.toFixed(0)}%</b></div>
            <div class="bar"><span style="width:${Math.max(2, p.util)}%;background:${c}"></span></div>
            <div class="r2">masuk ${bps(p.in)}bps, keluar ${bps(p.out)}bps dari ${bps(p.speed)}bps</div></a></li>`;
    }).join('') : '<li class="empty">Belum ada data trafik port</li>';

    // Dinding status per site, perangkat bermasalah di depan
    const order = { down: 0, problem: 1, impacted: 2, maintenance: 3, unknown: 4, ok: 5 };
    const bySite = {};
    d.wall.forEach((w) => { (bySite[w.site] = bySite[w.site] || []).push(w); });
    const wall = $('[data-wall]');
    wall.classList.toggle('dense', d.wall.length > 120);
    wall.classList.toggle('xdense', d.wall.length > 300);
    // Site yang punya perangkat down di atas, supaya yang bermasalah tidak tersembunyi di bawah layar
    const downIn = (s) => bySite[s].filter((w) => w.state === 'down').length;
    wall.innerHTML = Object.keys(bySite).sort((a, b) => (downIn(b) > 0) - (downIn(a) > 0) || a.localeCompare(b)).map((s) => {
        const list = bySite[s].sort((a, b) => order[a.state] - order[b.state] || a.name.localeCompare(b.name));
        const bad = list.filter((w) => w.state === 'down').length;
        const up = list.filter((w) => ['ok', 'problem'].includes(w.state)).length;
        return `<div class="n-wsec"><h3><b>${esc(s)}</b><em>${up}/${list.length} online${bad ? `, <span style="color:var(--crit)">${bad} down</span>` : ''}</em></h3><div class="n-tiles">${
            list.map((w) => {
                const sub = w.state === 'down' ? `DOWN${w.since ? `, ${ago(w.since)}` : ''}` : w.state === 'impacted' ? 'terdampak induk' : w.state === 'maintenance' ? 'maintenance'
                    : w.rtt != null ? `${Number(w.rtt).toFixed(w.rtt >= 100 ? 0 : 1).replace('.', ',')} ms${w.loss ? `, loss ${Math.round(w.loss)}%` : ''}` : w.host;
                return `<a class="n-tile ${w.state}" href="/perangkat/${w.id}" target="_blank" rel="noopener" title="${esc(`${w.name}, ${w.host}${w.reason ? `, ${w.reason}` : ''}`)}"><span class="tn">${esc(w.name)}</span><span class="ts">${esc(sub)}</span></a>`;
            }).join('')}</div></div>`;
    }).join('') || '<div class="n-list"><div class="empty">Belum ada perangkat</div></div>';

    // Problem aktif
    $('[data-prob-n]').textContent = d.kpi.problems > d.kpi.shown ? `${d.kpi.shown} dari ${d.kpi.problems}` : (d.kpi.problems || '');
    $('[data-problems]').innerHTML = d.problems.length ? d.problems.map((p) => {
        const isNew = seen && !seen.includes(p.id);
        const status = p.suppressed ? 'DITEKAN' : p.acked ? `ACK ${esc(p.acked_by || '')}` : 'BELUM ACK';
        return `<li class="${isNew ? 'new' : ''} ${p.acked || p.suppressed ? 'dim' : ''}"><a href="${p.url}" target="_blank" rel="noopener">
            <span class="sbar" style="background:${TONE[p.tone]}"></span>
            <span style="min-width:0"><div class="t">${esc(p.title)}</div><div class="m">${esc(p.sev_label.toUpperCase())}${p.site ? `, ${esc(p.site)}` : ''}${p.suppressed ? `, ${esc(p.suppressed)}` : ''}</div></span>
            <span class="age" data-since="${p.since}">${ago(p.since)}<small class="${p.acked || p.suppressed ? '' : 'nack'}">${status}</small></span></a></li>`;
    }).join('') : '<li class="empty">TIDAK ADA PROBLEM AKTIF</li>';
    seen = ids;

    $('[data-ev-n]').textContent = d.events.length || '';
    $('[data-events]').innerHTML = d.events.length ? d.events.map((e) => `<li><div class="row"><span class="sbar" style="background:${TONE[e.tone]}"></span>
        <span style="min-width:0"><div class="t" style="font-weight:400">${esc(e.message)}</div><div class="m">${esc(e.label)}</div></span><span class="age">${hm(e.at)}</span></div></li>`).join('')
        : '<li class="empty">TIDAK ADA KEJADIAN 1 JAM TERAKHIR</li>';
}

async function refresh() {
    try {
        const r = await fetch(`${root.dataset.url}?site=${site.value}`, { headers: { Accept: 'application/json' } });
        if (r.status === 401 || r.status === 419 || r.redirected) { $('[data-overlay]').hidden = false; return; }
        if (!r.ok) return;
        const d = await r.json();
        render(d);
        sig = d.sig;
        lastOk = Date.now();
        lastFull = Date.now();
        $('[data-overlay]').hidden = true;
    } catch (e) { /* jaringan terputus: indikator LIVE berubah sendiri */ }
}

topo = createTopology($('[data-map]'), {
    dataUrl: () => `${root.dataset.topoUrl}?site=${site.value}`,
    liveUrl: root.dataset.liveUrl,
    live: true,
    onSelect: (n) => { if (n?.url) window.open(n.url, '_blank', 'noopener'); },
});
topo.load().then(() => topo.startLive(5)).catch(() => {});
topo.every(30);

site.addEventListener('change', () => {
    store.set('ant-noc-site', site.value);
    seen = null;
    heard = null;
    refresh();
    topo.load().then(() => topo.fit()).catch(() => {});
});
const fs = () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen().catch(() => {}));
$('[data-fs]').addEventListener('click', fs);
document.addEventListener('keydown', (e) => { if ((e.key === 'f' || e.key === 'F') && e.target === document.body) fs(); });

setMode(mode);
tick();
refresh();
// Tiap 5 detik cek sidik keadaan (ringan); data lengkap dimuat begitu ada problem baru, ACK, pulih, atau status
// perangkat berubah, dan paling lambat tiap 15 detik untuk angka RTT dan trafik.
setInterval(async () => {
    if (document.hidden || busy) return;
    busy = true;
    try {
        if (Date.now() - lastFull >= 15000) { await refresh(); return; }
        const r = await fetch(`${root.dataset.stateUrl}?site=${site.value}`, { headers: { Accept: 'application/json' } });
        if (r.status === 401 || r.status === 419 || r.redirected) { $('[data-overlay]').hidden = false; return; }
        if (!r.ok) return;
        lastOk = Date.now();
        if ((await r.json()).sig !== sig) await refresh();
    } catch (e) { /* jaringan terputus: indikator LIVE berubah sendiri */ } finally { busy = false; }
}, 5000);

// Dinding status lebih panjang dari layar (ratusan perangkat): gulir sendiri pelan, jeda di atas dan bawah,
// berhenti saat kursor di atasnya. Layar TV tidak ada yang menggulir.
{
    const wall = $('[data-wall]');
    let hold = Date.now() + 5000;
    let hover = false;
    wall.addEventListener('mouseenter', () => { hover = true; });
    wall.addEventListener('mouseleave', () => { hover = false; hold = Date.now() + 3000; });
    setInterval(() => {
        if (hover || document.hidden || Date.now() < hold || showing !== 'wall') return;
        if (wall.scrollHeight - wall.clientHeight < 8) return;
        if (wall.scrollTop + wall.clientHeight >= wall.scrollHeight - 2) {
            hold = Date.now() + 5000;
            setTimeout(() => { wall.scrollTop = 0; }, 4000);
            return;
        }
        wall.scrollTop += 1;
    }, 40);
}
