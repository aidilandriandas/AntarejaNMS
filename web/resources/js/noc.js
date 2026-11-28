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
let prevState = null; // status tiap perangkat di dinding pada render sebelumnya (untuk deteksi baru down/pulih)
const freshDown = new Map(); // id perangkat yang baru down, kapan, untuk efek gelombang di kotaknya
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
    $('[data-date]').textContent = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const age = (Date.now() - lastOk) / 1000;
    const live = $('[data-live]');
    live.className = `n-live${age > 90 ? ' lost' : age > 40 ? ' stale' : ''}`;
    $('span', live).textContent = age > 90 ? 'Terputus' : age > 40 ? 'Tertunda' : 'Live';
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
    soundBtn.innerHTML = soundOn ? `${icon('on')}<span>Suara aktif</span>` : `${icon('off')}<span>Suara mati, klik</span>`;
}
// Semua bunyi lewat kompresor supaya bisa keras tanpa pecah di speaker TV.
let out = null;
function ctx() {
    audio = audio || new (window.AudioContext || window.webkitAudioContext)();
    if (audio.state === 'suspended') audio.resume();
    if (!out) {
        const comp = audio.createDynamicsCompressor();
        comp.threshold.value = -10;
        comp.knee.value = 6;
        comp.ratio.value = 8;
        comp.attack.value = 0.003;
        comp.release.value = 0.2;
        out = audio.createGain();
        out.gain.value = 1;
        out.connect(comp);
        comp.connect(audio.destination);
    }
    return audio;
}
function tone(t, f1, f2, dur, type, vol) {
    const o = audio.createOscillator();
    const g = audio.createGain();
    o.type = type;
    o.frequency.setValueAtTime(f1, t);
    if (f2 !== f1) o.frequency.linearRampToValueAtTime(f2, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol, t + 0.02);
    g.gain.setValueAtTime(vol, t + dur - 0.04);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g);
    g.connect(out);
    o.start(t);
    o.stop(t + dur + 0.02);
}
// level: down = sirene naik turun keras (perangkat down), crit = problem High/Disaster baru, up = pulih, warn = uji
function beep(level) {
    if (!soundOn) return 0;
    try {
        ctx();
        const t = audio.currentTime + 0.05;
        if (level === 'down') {
            // tiga siklus sirene dua lapis (sawtooth + square) supaya terdengar dari ujung ruang kontrol
            for (let i = 0; i < 3; i++) {
                const s0 = t + i * 0.9;
                tone(s0, 620, 1250, 0.45, 'sawtooth', 0.55);
                tone(s0 + 0.45, 1250, 620, 0.45, 'sawtooth', 0.55);
                tone(s0, 310, 625, 0.45, 'square', 0.25);
                tone(s0 + 0.45, 625, 310, 0.45, 'square', 0.25);
            }
            return 2800;
        }
        if (level === 'crit') {
            [988, 740, 988, 740, 988, 740].forEach((f, i) => tone(t + i * 0.2, f, f, 0.18, 'square', 0.45));
            return 1300;
        }
        if (level === 'up') {
            [523, 659, 784].forEach((f, i) => tone(t + i * 0.13, f, f, 0.22, 'triangle', 0.45));
            return 600;
        }
        [740, 988].forEach((f, i) => tone(t + i * 0.18, f, f, 0.16, 'square', 0.35));
        return 400;
    } catch (e) { return 0; /* audio tidak tersedia */ }
}
// Pengumuman suara bahasa Indonesia setelah sirene, kalau browser punya suaranya
function say(text, delay) {
    if (!soundOn || !('speechSynthesis' in window)) return;
    setTimeout(() => {
        try {
            const u = new SpeechSynthesisUtterance(text);
            u.lang = 'id-ID';
            const v = speechSynthesis.getVoices().find((x) => x.lang && x.lang.toLowerCase().startsWith('id'));
            if (v) u.voice = v;
            u.volume = 1;
            u.rate = 0.95;
            speechSynthesis.cancel();
            speechSynthesis.speak(u);
        } catch (e) { /* tidak ada suara */ }
    }, delay);
}
// Browser menahan audio sampai ada interaksi; klik atau tombol apa pun di layar membukanya lagi setelah dimuat ulang
['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, () => { if (soundOn) { try { ctx(); } catch (e) { /* */ } } }, { once: true }));
soundBtn.addEventListener('click', () => {
    soundOn = !soundOn;
    store.set('ant-noc-sound', soundOn ? 'on' : 'off');
    renderSound();
    if (soundOn) say('Suara layar NOC aktif.', beep('warn')); // bunyi uji sekaligus membuka izin audio
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

// ---------- Peringatan besar saat perangkat down / pulih ----------
const flash = $('[data-flash]');
let flashT = null;
function showFlash(kind, list) {
    const one = list[0];
    const more = list.length > 1 ? ` dan ${list.length - 1} perangkat lain` : '';
    flash.className = `n-flash${kind === 'up' ? ' up' : ''}`;
    $('[data-flash-k]', flash).textContent = kind === 'up' ? 'Perangkat pulih' : 'Perangkat down';
    $('[data-flash-h]', flash).textContent = one.name;
    $('[data-flash-s]', flash).textContent = `${one.site}, ${one.host}${more}`;
    flash.hidden = false;
    document.body.classList.toggle('alert-on', kind !== 'up');
    clearTimeout(flashT);
    flashT = setTimeout(() => { flash.hidden = true; document.body.classList.remove('alert-on'); }, kind === 'up' ? 3500 : 7000);
}
// Klik peringatan menutupnya lebih cepat
flash.style.pointerEvents = 'auto';
flash.addEventListener('click', () => { flash.hidden = true; document.body.classList.remove('alert-on'); });

// Angka naik/turun halus ke nilai baru
function count(el, to, fmt = (v) => String(Math.round(v))) {
    const from = Number(el.dataset.n ?? to);
    el.dataset.n = to;
    if (from === to || matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = fmt(to); return; }
    const t0 = performance.now();
    const step = (now) => {
        const k = Math.min(1, (now - t0) / 700);
        el.textContent = fmt(from + (to - from) * (1 - (1 - k) ** 3));
        if (k < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
    const box = el.closest('.n-kpi');
    if (box) { box.classList.remove('bump'); void box.offsetWidth; box.classList.add('bump'); }
}

// ---------- Render data ----------
function render(d) {
    serverOffset = new Date(d.time).getTime() - Date.now();
    const al = d.alarm;
    const alarm = $('[data-alarm]');
    alarm.className = `n-alarm ${al.level}`;
    alarm.innerHTML = `<svg class="i" viewBox="0 0 24 24" aria-hidden="true">${al.level === 'ok'
        ? '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5L16 9.5"/>'
        : '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>'}</svg><span>${esc(al.text)}</span>`;
    document.title = `${al.level === 'crit' ? '(!) ' : ''}Layar NOC | ANTAREJA NMS`;

    // Siapa yang piket: nama dan jam selesai; kosong = beri tahu supaya ruang NOC tahu tidak ada yang dihubungi
    const oc = $('[data-oncall]');
    if (oc && d.oncall) {
        oc.className = `n-oncall${d.oncall.length ? '' : ' none'}`;
        oc.innerHTML = d.oncall.length
            ? `<small>Piket</small><b>${d.oncall.map((o) => esc(o.name)).join(', ')}</b><small>sampai ${esc(d.oncall[0].until)}</small>`
            : `<small>Piket</small><b>Tidak ada</b>${d.oncall_next?.length ? `<small>berikutnya ${esc(d.oncall_next[0].name)} ${esc(d.oncall_next[0].from)}</small>` : ''}`;
    }

    // Suara: problem High/Disaster baru yang belum di-ACK & tidak ditekan (dari server, tidak terbatas daftar yang tampil)
    const ids = d.problems.map((p) => p.id);
    const fresh = d.fresh || [];
    const newCrit = heard !== null && fresh.some((id) => !heard.has(id));
    heard = new Set([...(heard || []), ...fresh]);

    // Perangkat yang baru down / baru pulih sejak render sebelumnya (dari status dinding, bukan daftar problem)
    const now = Date.now();
    const wentDown = prevState ? d.wall.filter((w) => w.state === 'down' && prevState.has(w.id) && prevState.get(w.id) !== 'down') : [];
    const cameUp = prevState ? d.wall.filter((w) => w.state === 'ok' && prevState.get(w.id) === 'down') : [];
    prevState = new Map(d.wall.map((w) => [w.id, w.state]));
    wentDown.forEach((w) => freshDown.set(w.id, now));
    freshDown.forEach((t, id) => { if (now - t > 60000) freshDown.delete(id); });
    if (wentDown.length) {
        showFlash('down', wentDown);
        const ms = beep('down');
        const w = wentDown[0];
        say(wentDown.length > 1 ? `Perhatian. ${wentDown.length} perangkat down. ${w.name} dan lainnya.` : `Perhatian. Perangkat ${w.name} di ${w.site} down.`, ms);
    } else if (newCrit) {
        beep('crit');
    } else if (cameUp.length) {
        showFlash('up', cameUp);
        beep('up');
    }

    const k = d.kpi;
    const av = k.availability;
    const ka = $('[data-k-avail]');
    $('.val', ka).innerHTML = `${av === null ? '?' : av.toFixed(av === 100 ? 0 : 2).replace('.', ',')}<small>%</small>`;
    const ring = $('[data-ring]');
    ring.className = `n-ring ${av === null || av >= 99 ? '' : av >= 95 ? 'warn' : 'crit'}`;
    $('.arc', ring).style.strokeDashoffset = String(301.6 * (1 - (av || 0) / 100));
    $('.meter', ka).className = `meter ${av === null || av >= 99 ? '' : av >= 95 ? 'warn' : 'crit'}`;
    $('.meter span', ka).style.width = `${av || 0}%`;
    $('.sub', ka).textContent = `${k.up} / ${k.up + k.down} perangkat online${k.unknown ? `, ${k.unknown} menunggu` : ''}`;
    count($('[data-k-up] .val'), k.up);
    count($('[data-k-down] .val'), k.down);
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
                const sub = w.state === 'down' ? `Down${w.since ? `, ${ago(w.since)}` : ''}` : w.state === 'impacted' ? 'terdampak induk' : w.state === 'maintenance' ? 'maintenance'
                    : w.rtt != null ? `${Number(w.rtt).toFixed(w.rtt >= 100 ? 0 : 1).replace('.', ',')} ms${w.loss ? `, loss ${Math.round(w.loss)}%` : ''}` : w.host;
                return `<a class="n-tile ${w.state}${freshDown.has(w.id) ? ' fresh' : ''}" href="/perangkat/${w.id}" target="_blank" rel="noopener" title="${esc(`${w.name}, ${w.host}${w.reason ? `, ${w.reason}` : ''}`)}"><span class="tn">${esc(w.name)}</span><span class="ts">${esc(sub)}</span></a>`;
            }).join('')}</div></div>`;
    }).join('') || '<div class="n-list"><div class="empty">Belum ada perangkat</div></div>';

    // Problem aktif
    $('[data-prob-n]').textContent = d.kpi.problems > d.kpi.shown ? `${d.kpi.shown} dari ${d.kpi.problems}` : (d.kpi.problems || '');
    $('[data-problems]').innerHTML = d.problems.length ? d.problems.map((p) => {
        const isNew = seen && !seen.includes(p.id);
        const status = p.suppressed ? 'Ditekan' : p.acked ? `ACK ${esc(p.acked_by || '')}` : 'Belum ACK';
        return `<li class="${isNew ? 'new' : ''} ${p.acked || p.suppressed ? 'dim' : ''}"><a href="${p.url}" target="_blank" rel="noopener">
            <span class="sbar" style="background:${TONE[p.tone]}"></span>
            <span style="min-width:0"><div class="t">${esc(p.title)}</div><div class="m">${esc(p.sev_label)}${p.site ? `, ${esc(p.site)}` : ''}${p.suppressed ? `, ${esc(p.suppressed)}` : ''}</div></span>
            <span class="age" data-since="${p.since}">${ago(p.since)}<small class="${p.acked || p.suppressed ? '' : 'nack'}">${status}</small></span></a></li>`;
    }).join('') : '<li class="empty">Tidak ada problem aktif</li>';
    seen = ids;

    $('[data-ev-n]').textContent = d.events.length || '';
    $('[data-events]').innerHTML = d.events.length ? d.events.map((e) => `<li><div class="row"><span class="sbar" style="background:${TONE[e.tone]}"></span>
        <span style="min-width:0"><div class="t" style="font-weight:400">${esc(e.message)}</div><div class="m">${esc(e.label)}</div></span><span class="age">${hm(e.at)}</span></div></li>`).join('')
        : '<li class="empty">Tidak ada kejadian 1 jam terakhir</li>';
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
    prevState = null;
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
