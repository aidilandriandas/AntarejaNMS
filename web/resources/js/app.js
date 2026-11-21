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

// Konfirmasi sebelum aksi berbahaya
$$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
}));

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
                    { label: 'Loss', scale: 'y2', stroke: css('--crit'), fill: `${css('--crit')}33`, width: 1, paths: uPlot.paths.stepped({ align: 1 }), value: (u, v) => (v == null ? '–' : `${Math.round(v)}%`) }],
            legend: { live: true },
            cursor: { drag: { x: false, y: false } },
        };
        const cols = isPort ? [data.t, s.in, s.out] : [data.t, s.rtt, s.loss];
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
    $$('[data-port]').forEach((row) => {
        const pick = () => {
            const same = port === row.dataset.port;
            port = same ? null : row.dataset.port;
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
