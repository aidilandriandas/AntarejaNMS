// ANTAREJA NMS — perilaku halaman (tanpa framework).
import uPlot from 'uplot';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const csrf = () => $('meta[name="csrf-token"]')?.content;

// Tema terang/gelap (disimpan per browser)
$$('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('ant-theme', next); } catch (e) { /* mode privat */ }
    window.dispatchEvent(new Event('ant-theme'));
}));

// Menu di layar kecil
$$('[data-menu]').forEach((b) => b.addEventListener('click', () => document.body.classList.toggle('nav-open')));

// Kirim form saat pilihan berubah (filter) & tombol cetak — pengganti atribut onchange/onclick (CSP tanpa skrip inline)
$$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.submit()));
$$('[data-print]').forEach((el) => el.addEventListener('click', () => window.print()));

// Muat ulang halaman berkala (mis. Ringkasan), ditunda selama panel pengaturan terbuka atau tab tersembunyi
const autoReload = $('[data-autoreload]');
if (autoReload) {
    setTimeout(function again() {
        if ($('[data-dash-panel]:not([hidden])')) { setTimeout(again, 15000); return; }
        if (!document.hidden) location.reload();
        else document.addEventListener('visibilitychange', () => location.reload(), { once: true });
    }, (+autoReload.dataset.autoreload || 60) * 1000);
}

// CCTV kelola: pilih perangkat NVR/kamera mengisi host, site, dan nama
$('[data-cam-device]')?.addEventListener('change', (e) => {
    const o = e.target.selectedOptions[0];
    const host = document.getElementById('host');
    if (o?.dataset.host && !host.value) host.value = o.dataset.host;
    const site = $('[data-cam-site]');
    if (site) site.value = o?.dataset.site || '';
    const name = document.getElementById('name');
    if (o?.value && !name.value) name.value = o.textContent.replace(/\s*\(.*\)$/, '');
});

// Konfirmasi sebelum aksi berbahaya
$$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
}));

// Klik tombol di dalam baris port tidak ikut memilih grafik port
$$('form[data-stop]').forEach((f) => f.addEventListener('click', (e) => e.stopPropagation()));

// Form maintenance: tampilkan isian sesuai cakupan
const scope = $('[data-scope]');
if (scope) {
    const syncScope = () => $$('[data-scope-show]').forEach((el) => { el.hidden = el.dataset.scopeShow !== scope.value; });
    scope.addEventListener('change', syncScope);
    syncScope();
}

// ---------- Form perangkat ----------
const form = $('[data-device-form]');
if (form) {
    const snmpOn = $('[data-toggle="snmp"]', form);
    const version = $('[data-toggle="v3"]', form);
    const sync = () => {
        $('[data-section="snmp"]', form).hidden = !snmpOn.checked;
        $('[data-section="v3"]', form).hidden = version.value !== '3';
        $('[data-section="v2"]', form).hidden = version.value === '3';
    };
    snmpOn.addEventListener('change', sync);
    version.addEventListener('change', sync);
    sync();

    const btn = $('[data-test-url]', form);
    const out = $('[data-test-result]', form);
    const line = (ok, title, detail) => {
        const d = document.createElement('div');
        d.innerHTML = `<span class="${ok ? 'ok' : 'bad'}">${ok ? '✓' : '✕'}</span><span><b></b><small></small></span>`;
        d.querySelector('b').textContent = title;
        d.querySelector('small').textContent = detail || '';
        out.appendChild(d);
    };
    btn.addEventListener('click', async () => {
        const fd = new FormData(form);
        const body = {
            host: fd.get('host'), device_id: fd.get('device_id') || null,
            ping_enabled: fd.get('ping_enabled') === '1', snmp_enabled: fd.get('snmp_enabled') === '1',
        };
        ['snmp_version', 'snmp_port', 'snmp_community', 'snmp_v3_user', 'snmp_v3_level', 'snmp_v3_auth_proto', 'snmp_v3_auth_pass', 'snmp_v3_priv_proto', 'snmp_v3_priv_pass']
            .forEach((k) => { body[k] = fd.get(k) || null; });
        if (!body.host) { out.textContent = 'Isi alamat IP / nama DNS dulu.'; return; }
        if (!body.ping_enabled && !body.snmp_enabled) { out.textContent = 'Aktifkan ping atau SNMP untuk diuji.'; return; }
        btn.disabled = true;
        out.textContent = 'Menguji… (SNMP bisa sampai 10 detik bila perangkat tidak menjawab)';
        try {
            const r = await fetch(btn.dataset.testUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify(body),
            });
            const j = await r.json();
            out.textContent = '';
            if (!r.ok) {
                line(false, 'Uji gagal', j.error || j.message || `HTTP ${r.status}`);
                return;
            }
            if (j.ping) {
                const p = j.ping;
                if (p.error) line(false, 'Ping gagal', p.error);
                else if (p.ok) line(true, `Ping ${p.recv}/${p.sent} balasan`, `rata-rata ${p.avg_ms.toFixed(2).replace('.', ',')} ms · loss ${Math.round(p.loss_pct)}%`);
                else line(false, 'Ping tidak dibalas', 'Perangkat mati, rute salah, atau ICMP diblok firewall. Bila ICMP memang diblok, matikan ping dan andalkan SNMP.');
            }
            if (j.snmp) {
                const s = j.snmp;
                if (s.ok) {
                    line(true, `SNMP terbaca: ${s.sys_name || '(tanpa sysName)'}`, `uptime ${s.uptime || '–'} · ${s.interfaces} port (${s.interfaces_up} up)`);
                    if (s.sys_descr) line(true, 'Deskripsi', s.sys_descr.split(/\r?\n/)[0].slice(0, 160));
                    const name = $('#name', form);
                    if (name && !name.value && s.sys_name) name.value = s.sys_name;
                } else {
                    line(false, 'SNMP gagal', s.error);
                }
            }
        } catch (e) {
            out.textContent = '';
            line(false, 'Tidak dapat menghubungi server', String(e));
        } finally {
            btn.disabled = false;
        }
    });
}

// ---------- Grafik perangkat ----------
const card = $('[data-chart-card]');
if (card) {
    const el = $('[data-chart]', card);
    const title = $('[data-chart-title]', card);
    let range = '24h';
    let port = null;
    let sensor = null; // {key, label, unit}
    let plot = null;
    let last = null;

    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const bps = (v) => {
        if (v == null) return '–';
        const u = [['Gbps', 1e9], ['Mbps', 1e6], ['Kbps', 1e3]].find(([, d]) => v >= d);
        return u ? `${(v / u[1]).toFixed(v / u[1] >= 100 ? 0 : 1)} ${u[0]}` : `${Math.round(v)} bps`;
    };

    // Format waktu Indonesia: 24 jam, tanggal dd/MM
    const two = (n) => String(n).padStart(2, '0');
    const hm = (d) => `${two(d.getHours())}.${two(d.getMinutes())}`;
    const dm = (d) => `${two(d.getDate())}/${two(d.getMonth() + 1)}`;
    const xValues = (u, splits, ax, space, incr) => splits.map((ts) => {
        const d = new Date(ts * 1000);
        return incr >= 86400 || (d.getHours() === 0 && d.getMinutes() === 0) ? dm(d) : hm(d);
    });
    const xLegend = (u, ts) => (ts == null ? '–' : `${dm(new Date(ts * 1000))} ${hm(new Date(ts * 1000))}`);

    const draw = (data) => {
        last = data;
        if (plot) plot.destroy();
        el.querySelector('.empty')?.remove();
        const axis = { stroke: css('--muted'), grid: { stroke: css('--line'), width: 1 }, ticks: { stroke: css('--line') } };
        const isPort = port !== null;
        const s = data.series;
        if (sensor) {
            const unit = sensor.unit ? ` ${sensor.unit}` : '';
            plot = new uPlot({
                width: el.clientWidth, height: 240, scales: { x: { time: true } },
                axes: [{ ...axis, values: xValues }, { ...axis, values: (u, vals) => vals.map((v) => `${v}${unit}`), size: 70 }],
                series: [{ label: 'Waktu', value: xLegend }, { label: sensor.label, stroke: css('--accent'), width: 1.6, spanGaps: true, points: { show: true, size: 4, fill: css('--accent') }, value: (u, v) => (v == null ? '–' : `${String(v).replace('.', ',')}${unit}`) }],
                legend: { live: true }, cursor: { drag: { x: false, y: false } },
            }, [data.t, s.value], el);
            if (s.value.every((v) => v == null)) { const n = document.createElement('div'); n.className = 'empty'; n.textContent = 'Belum ada data untuk rentang ini.'; el.prepend(n); }
            return;
        }
        const opts = {
            width: el.clientWidth, height: 240,
            scales: { x: { time: true }, y2: { range: [0, 100] } },
            axes: isPort
                ? [{ ...axis, values: xValues }, { ...axis, values: (u, vals) => vals.map(bps), size: 70 }]
                : [{ ...axis, values: xValues }, { ...axis, values: (u, vals) => vals.map((v) => `${v} ms`), size: 60 }, { ...axis, scale: 'y2', side: 1, values: (u, vals) => vals.map((v) => `${v}%`), grid: { show: false } }],
            series: isPort
                ? [{ label: 'Waktu', value: xLegend }, { label: 'Masuk', stroke: css('--accent'), fill: `${css('--accent')}22`, width: 1.5, value: (u, v) => bps(v) },
                    { label: 'Keluar', stroke: css('--info'), width: 1.5, value: (u, v) => bps(v) }]
                : [{ label: 'Waktu', value: xLegend }, { label: 'RTT', stroke: css('--accent'), width: 1.5, value: (u, v) => (v == null ? '–' : `${v.toFixed(2)} ms`) },
                    { label: 'Loss', scale: 'y2', stroke: css('--crit'), fill: `${css('--crit')}33`, width: 1, paths: uPlot.paths.stepped({ align: 1 }), value: (u, v) => (v == null ? '–' : `${Math.round(v)}%`) },
                    { label: 'Min', stroke: `${css('--accent')}55`, width: 1, value: (u, v) => (v == null ? '–' : `${v.toFixed(2)} ms`) },
                    { label: 'Maks', stroke: `${css('--accent')}55`, width: 1, value: (u, v) => (v == null ? '–' : `${v.toFixed(2)} ms`) }],
            // pita min–maks (sebaran latensi, gaya SmokePing)
            bands: isPort ? [] : [{ series: [4, 3], fill: `${css('--accent')}22` }],
            legend: { live: true },
            cursor: { drag: { x: false, y: false } },
        };
        const cols = isPort ? [data.t, s.in, s.out] : [data.t, s.rtt, s.loss, s.min || s.rtt.map(() => null), s.max || s.rtt.map(() => null)];
        plot = new uPlot(opts, cols, el);
        if (cols.slice(1).every((c) => c.every((v) => v == null))) {
            const n = document.createElement('div');
            n.className = 'empty';
            n.textContent = 'Belum ada data untuk rentang ini.';
            el.prepend(n);
        }
    };

    const load = async () => {
        const u = new URL(card.dataset.url, location.origin);
        u.searchParams.set('range', range);
        if (port !== null) { u.searchParams.set('type', 'port'); u.searchParams.set('if', port); }
        if (sensor) { u.searchParams.set('type', 'sensor'); u.searchParams.set('key', sensor.key); }
        el.style.opacity = '.5';
        try {
            const r = await fetch(u, { headers: { Accept: 'application/json' } });
            if (r.ok) draw(await r.json());
        } finally {
            el.style.opacity = '';
        }
    };

    $$('[data-range]', card).forEach((b) => b.addEventListener('click', () => {
        range = b.dataset.range;
        $$('[data-range]', card).forEach((x) => x.classList.toggle('on', x === b));
        load();
    }));
    $$('[data-sensor]').forEach((row) => {
        const pick = () => {
            const same = sensor?.key === row.dataset.sensor;
            sensor = same ? null : { key: row.dataset.sensor, label: row.dataset.sensorLabel, unit: row.dataset.sensorUnit };
            port = null;
            $$('[data-port]').forEach((r) => r.classList.remove('sel'));
            $$('[data-sensor]').forEach((r) => r.classList.toggle('sel', !same && r === row));
            title.textContent = same ? 'Latensi & packet loss' : row.dataset.sensorLabel;
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            load();
        };
        row.addEventListener('click', pick);
        row.addEventListener('keydown', (e) => { if (e.key === 'Enter') pick(); });
    });
    $$('[data-port]').forEach((row) => {
        const pick = () => {
            const same = port === row.dataset.port;
            port = same ? null : row.dataset.port;
            sensor = null;
            $$('[data-sensor]').forEach((r) => r.classList.remove('sel'));
            $$('[data-port]').forEach((r) => r.classList.toggle('sel', !same && r === row));
            title.textContent = same ? 'Latensi & packet loss' : `Trafik ${row.dataset.portName}`;
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            load();
        };
        row.addEventListener('click', pick);
        row.addEventListener('keydown', (e) => { if (e.key === 'Enter') pick(); });
    });
    window.addEventListener('resize', () => plot && plot.setSize({ width: el.clientWidth, height: 240 }));
    window.addEventListener('ant-theme', () => last && draw(last));
    load();
    setInterval(() => { if (!document.hidden) load(); }, 60000);
}

// ---------- Halaman topologi ----------
const topoRoot = $('[data-topology]');
if (topoRoot) {
    import('./topology.js').then(({ createTopology, bps }) => {
        const siteSel = $('[data-topo-site]', topoRoot);
        try { siteSel.value = localStorage.getItem('ant-topo-site') || ''; } catch (e) { /* abaikan */ }
        const info = $('[data-topo-info]', topoRoot);
        const topo = createTopology($('[data-topo-canvas]', topoRoot), {
            dataUrl: () => `${topoRoot.dataset.url}?site=${siteSel.value}`,
            liveUrl: topoRoot.dataset.liveUrl,
            positionsUrl: topoRoot.dataset.positionsUrl,
            live: true,
            onStats: (s) => { $('[data-topo-stats]', topoRoot).textContent = `${s.devices} perangkat · ${s.links} sambungan · ${s.down} down${s.unknown_neighbors ? ` · ${s.unknown_neighbors} tetangga belum terdaftar` : ''}`; },
            onSelect: (n) => {
                if (!n) return;
                const rows = [['Alamat', n.host], ['Site', n.site], ['Status', { up: 'Online', problem: 'Ada problem', down: 'DOWN', unknown: 'Menunggu', maintenance: 'Maintenance', external: 'Belum terdaftar' }[n.state]],
                    ['Penyebab', n.reason], ['RTT', n.rtt != null ? `${Number(n.rtt).toFixed(2).replace('.', ',')} ms` : null], ['Platform', n.platform]].filter(([, v]) => v);
                info.innerHTML = '';
                const h = document.createElement('div'); h.style.cssText = 'font-weight:600;margin-bottom:8px'; h.textContent = n.label; info.appendChild(h);
                const dl = document.createElement('dl'); dl.className = 'facts';
                rows.forEach(([k, v]) => { const dt = document.createElement('dt'); dt.textContent = k; const dd = document.createElement('dd'); dd.textContent = v; dl.append(dt, dd); });
                info.appendChild(dl);
                if (n.url) { const a = document.createElement('a'); a.href = n.url; a.className = 'btn sm'; a.style.marginTop = '12px'; a.textContent = 'Buka detail perangkat'; info.appendChild(a); }
                else { const p = document.createElement('p'); p.className = 'muted'; p.style.fontSize = '12.5px'; p.textContent = 'Terlihat lewat LLDP/CDP tetapi belum didaftarkan. Tambahkan lewat menu Perangkat agar dipantau.'; info.appendChild(p); }
            },
        });
        topo.load().then(() => topo.startLive(5)).catch(() => {});
        topo.every(30);
        siteSel.addEventListener('change', () => { try { localStorage.setItem('ant-topo-site', siteSel.value); } catch (e) { /* abaikan */ } topo.load().then(topo.fit); });
        $('[data-topo-live]', topoRoot).addEventListener('change', (e) => (e.target.checked ? topo.startLive(5) : topo.stopLive()));
        $('[data-topo-fit]', topoRoot).addEventListener('click', topo.fit);
        $('[data-topo-unlock]', topoRoot)?.addEventListener('click', () => topo.setPhysics(true));
        const save = $('[data-topo-save]', topoRoot);
        save?.addEventListener('click', async () => {
            save.disabled = true;
            try { await topo.savePositions(); save.textContent = 'Tersimpan ✓'; } catch (e) { save.textContent = 'Gagal menyimpan'; }
            setTimeout(() => { save.textContent = 'Simpan tata letak'; save.disabled = false; }, 2000);
        });
        void bps;
    });

    // Form sambungan manual: muat daftar port perangkat terpilih
    const lf = $('[data-link-form]');
    $$('[data-link-device]', lf || document).forEach((sel) => sel.addEventListener('change', async () => {
        const portSel = $(`[data-link-port="${sel.dataset.linkDevice}"]`, lf);
        portSel.innerHTML = '<option value="">(tidak diketahui)</option>';
        if (!sel.value) return;
        const r = await fetch(`${lf.dataset.portsUrl}/${sel.value}`, { headers: { Accept: 'application/json' } });
        if (!r.ok) return;
        (await r.json()).forEach((p) => { const o = document.createElement('option'); o.value = p.if_index; o.textContent = p.label; portSel.appendChild(o); });
    }));
}

// ---------- CCTV ----------
const cctv = document.querySelector('[data-cctv]');
if (cctv) {
    import('./cctv.js').then(({ play }) => {
        // Snapshot tiap 10 detik (gambar dari sub-stream lewat /live/frame.jpeg)
        const refresh = () => {
            if (document.hidden) return;
            $$('img[data-snap]', cctv).forEach((img) => {
                const next = new Image();
                next.onload = () => { img.src = next.src; img.closest('.cam').classList.remove('nosignal'); };
                next.onerror = () => img.closest('.cam').classList.add('nosignal');
                next.src = `/live/frame.jpeg?src=${img.dataset.snap}&t=${Date.now()}`;
            });
        };
        refresh();
        setInterval(refresh, 10000);

        const modal = $('[data-cam-modal]');
        const video = $('video', modal);
        const status = $('[data-cam-status]', modal);
        let stop = null;
        let current = null;
        const open = async (camId, name, q) => {
            stop?.();
            current = { camId, name, q };
            $('[data-cam-title]', modal).textContent = name;
            $$('[data-q]', modal).forEach((b) => b.classList.toggle('primary', b.dataset.q === q));
            modal.hidden = false;
            status.textContent = 'Menyiapkan aliran…';
            const r = await fetch(`/cctv/${camId}/live?q=${q}`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) { status.textContent = j.error || 'Gagal menyiapkan aliran'; return; }
            stop = play(video, j.src, (s) => { status.textContent = s; });
        };
        const close = () => { stop?.(); stop = null; modal.hidden = true; };
        $$('[data-cam]', cctv).forEach((el) => el.addEventListener('click', () => open(el.dataset.cam, el.dataset.camName, 'sub')));
        $$('[data-q]', modal).forEach((b) => b.addEventListener('click', () => current && open(current.camId, current.name, b.dataset.q)));
        $('[data-cam-close]', modal).addEventListener('click', close);
        modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) close(); });
    });
}

// Halaman Log: muat ulang otomatis tiap 10 detik bila "Live" dicentang (hanya di halaman terbaru)
const logLive = $('[data-log-live]');
if (logLive) {
    const KEY = 'antareja.logLive';
    const first = !new URLSearchParams(location.search).has('before');
    try { logLive.checked = first && localStorage.getItem(KEY) === '1'; } catch { /* penyimpanan tidak tersedia */ }
    logLive.disabled = !first;
    let timer = null;
    const arm = () => {
        clearInterval(timer);
        if (logLive.checked) timer = setInterval(() => { if (!document.hidden) location.reload(); }, 10000);
    };
    logLive.addEventListener('change', () => {
        try { localStorage.setItem(KEY, logLive.checked ? '1' : '0'); } catch { /* abaikan */ }
        arm();
    });
    arm();
}

// Discovery: progres pemindaian & pilihan hasil
const disc = $('[data-disc]');
if (disc) {
    const tick = async () => {
        const r = await fetch(disc.dataset.disc, { headers: { Accept: 'application/json' } }).catch(() => null);
        if (!r || !r.ok) return;
        const s = await r.json();
        if (!['queued', 'running'].includes(s.status)) { location.reload(); return; }
        const pct = s.total ? Math.round((s.progress / s.total) * 100) : 2;
        $('[data-disc-bar]').style.width = `${Math.max(pct, 2)}%`;
        $('[data-disc-text]').textContent = `${s.progress} / ${s.total || '…'} alamat · ${s.found} menjawab`;
        $('[data-disc-status]').textContent = s.status === 'running' ? 'Berjalan' : 'Antre';
    };
    setInterval(() => { if (!document.hidden) tick(); }, 2000);
}
const discForm = $('[data-disc-form]');
if (discForm) {
    const boxes = $$('input[name="pick[]"]', discForm);
    const all = $('[data-disc-all]', discForm);
    const sync = () => {
        const n = boxes.filter((b) => b.checked).length;
        const cnt = $('[data-disc-n]', discForm);
        if (cnt) cnt.textContent = n;
        $$('[data-disc-add], [data-disc-ign]', discForm).forEach((b) => { b.disabled = n === 0; });
        if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
    };
    boxes.forEach((b) => b.addEventListener('change', sync));
    all?.addEventListener('change', () => { boxes.forEach((b) => { b.checked = all.checked; }); sync(); });
    sync();
}

// Laporan terjadwal: pratinjau dalam iframe sandbox
const pvModal = $('[data-preview-modal]');
if (pvModal) {
    const frame = $('[data-preview-frame]', pvModal);
    const close = () => { pvModal.hidden = true; frame.removeAttribute('src'); };
    $$('[data-preview]').forEach((b) => b.addEventListener('click', () => {
        $('[data-preview-title]', pvModal).textContent = `Pratinjau — ${b.dataset.title}`;
        frame.src = b.dataset.preview;
        pvModal.hidden = false;
    }));
    $('[data-preview-close]', pvModal).addEventListener('click', close);
    pvModal.addEventListener('click', (e) => { if (e.target === pvModal) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !pvModal.hidden) close(); });
}

// Peta lokasi
const mapEl = $('[data-map]');
if (mapEl) {
    import('./map.js').then(({ createMap }) => {
        const panel = $('[data-map-panel]');
        const sel = $('[data-map-target]');
        const msg = $('[data-map-msg]');
        const clearBtn = $('[data-map-clear]');
        const say = (t, bad = false) => { if (msg) { msg.textContent = t; msg.style.color = bad ? 'var(--crit)' : ''; } };
        const target = () => { const [type, id] = (sel?.value || '').split(':'); return type ? { type, id: +id } : null; };
        const m = createMap(mapEl, { csrf, onSelect: (v) => { if (sel) { sel.value = v; sel.dispatchEvent(new Event('change')); } } });
        const at = $('[data-map-at]');
        const refresh = async () => { const j = await m.load(); if (j && at) at.textContent = `Diperbarui ${j.at}`; };
        refresh();
        setInterval(() => { if (!document.hidden) refresh(); }, 30000);
        const put = async (lat, lng) => {
            const t = target();
            if (!t) { say('Pilih site atau perangkat dulu.', true); return; }
            try {
                await m.save(t.type, t.id, lat, lng);
                const o = sel.selectedOptions[0];
                o.dataset.lat = lat ?? ''; o.dataset.lng = lng ?? '';
                o.textContent = o.textContent.replace(/ · (belum|✓)$/, '') + (lat === null ? (t.type === 'site' ? ' · belum' : '') : (t.type === 'device' ? ' · ✓' : ''));
                clearBtn.disabled = lat === null;
                say(lat === null ? 'Posisi dihapus.' : (m.plan ? `Tersimpan di denah (x ${Math.round(lng)}, y ${Math.round(m.plan.h - lat)})` : `Tersimpan: ${lat}, ${lng}`));
            } catch (e) { say(`Gagal menyimpan: ${e.message}`, true); }
        };
        m.onClick((lat, lng) => put(lat, lng));
        if (panel && !panel.hidden) { // dibuka otomatis bila ada galat validasi (mis. unggah denah)
            $('[data-map-edit]')?.classList.add('primary');
            m.setEditing(true);
        }
        $('[data-map-edit]')?.addEventListener('click', (e) => {
            const on = panel.hidden;
            panel.hidden = !on;
            e.currentTarget.classList.toggle('primary', on);
            m.setEditing(on);
        });
        sel?.addEventListener('change', () => {
            const o = sel.selectedOptions[0];
            const has = o && o.dataset.lat !== '' && o.dataset.lat !== undefined;
            clearBtn.disabled = !has;
            if (has) m.focus(+o.dataset.lat, +o.dataset.lng);
            say(sel.value ? 'Klik lokasinya di peta, atau geser penandanya.' : '');
        });
        $('[data-map-apply]')?.addEventListener('click', () => {
            const v = $('[data-map-coord]').value.trim().match(/^(-?\d+(?:\.\d+)?)\s*[,; ]\s*(-?\d+(?:\.\d+)?)$/);
            if (!v || Math.abs(+v[1]) > 90 || Math.abs(+v[2]) > 180) { say('Format koordinat: lintang, bujur — mis. -2.123456, 115.654321', true); return; }
            put(+(+v[1]).toFixed(6), +(+v[2]).toFixed(6)).then(() => m.focus(+v[1], +v[2]));
        });
        clearBtn?.addEventListener('click', () => { if (confirm('Hapus posisi ini dari peta?')) put(null, null); });
    });
}

// Ringkasan: atur tampilan (urutan, tampil/sembunyi, lebar) per pengguna
const dashPanel = $('[data-dash-panel]');
if (dashPanel) {
    const btn = $('[data-dash-edit]');
    const list = $('[data-dash-list]', dashPanel);
    const toggle = (open) => {
        dashPanel.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
        btn.classList.toggle('primary', open);
        if (open) dashPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    btn.addEventListener('click', () => toggle(dashPanel.hidden));
    $('[data-dash-cancel]', dashPanel).addEventListener('click', () => toggle(false));
    list.addEventListener('click', (e) => {
        const b = e.target.closest('[data-up], [data-down]');
        if (!b) return;
        const li = b.closest('li');
        if (b.hasAttribute('data-up') && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
        if (b.hasAttribute('data-down') && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
        b.focus();
    });
}

// Tombol salin (mis. tautan status baru)
$$('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
    const src = $('[data-copy-src]');
    src.select();
    try { await navigator.clipboard.writeText(src.value); } catch { document.execCommand?.('copy'); }
    b.textContent = 'Tersalin ✓';
    setTimeout(() => { b.textContent = 'Salin'; }, 2000);
}));

// Kotak centang yang menampilkan/menyembunyikan bagian lain (mis. frasa sandi ekspor)
$$('[data-toggle-target]').forEach((cb) => {
    const t = $(cb.dataset.toggleTarget);
    const sync = () => { if (t) t.hidden = !cb.checked; };
    cb.addEventListener('change', sync);
    sync();
});
