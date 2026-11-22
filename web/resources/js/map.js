// Peta lokasi ANTAREJA (Leaflet). Penanda memakai divIcon (tanpa gambar eksternal); gambar peta dari URL tile pengaturan admin.
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const STATE = { up: 'Normal', problem: 'Ada problem', down: 'Down', maintenance: 'Maintenance', idle: 'Tanpa perangkat', unknown: 'Belum diketahui' };

export function createMap(el, { csrf, onSelect }) {
    // Mode denah: CRS sederhana, 1 unit = 1 piksel gambar; lat = tinggi − y, lng = x
    const plan = el.dataset.plan ? { id: +el.dataset.plan, w: +el.dataset.planW, h: +el.dataset.planH } : null;
    const map = plan
        ? L.map(el, { crs: L.CRS.Simple, minZoom: -5, maxZoom: 3, zoomSnap: 0.25, attributionControl: false })
        : L.map(el, { zoomControl: true, attributionControl: true, worldCopyJump: true });
    if (plan) {
        const bounds = [[0, 0], [plan.h, plan.w]];
        L.imageOverlay(el.dataset.planImg, bounds).addTo(map);
        map.fitBounds(bounds);
        map.setMaxBounds(L.latLngBounds(bounds).pad(0.25));
        el.classList.add('plan');
    } else {
        // Peta dasar: jalan / topografi / satelit (sesuai pengaturan admin); pilihan terakhir diingat per browser
        const layers = JSON.parse(el.dataset.layers || '[]');
        const base = {};
        layers.forEach((l) => { base[l.name] = L.tileLayer(l.url, { maxZoom: l.max || 19, attribution: esc(l.attr), subdomains: 'abc' }); base[l.name]._key = l.key; });
        let pick = null;
        try { pick = localStorage.getItem('antareja.mapLayer'); } catch { /* abaikan */ }
        const first = Object.values(base).find((t) => t._key === pick) || Object.values(base)[0];
        if (first) {
            first.addTo(map);
            if (layers.length > 1) L.control.layers(base, null, { position: 'topright', collapsed: false }).addTo(map);
            map.on('baselayerchange', (e) => { try { localStorage.setItem('antareja.mapLayer', e.layer._key); } catch { /* abaikan */ } });
        } else {
            el.classList.add('no-tiles');
        }
        map.attributionControl.setPrefix('');
        map.setView([-2.5, 117], 5); // Indonesia sampai ada penanda
    }
    const round = (v) => (plan ? +v.toFixed(1) : +v.toFixed(6));

    const linkLayer = L.layerGroup().addTo(map);
    // Daftar site: lompat ke lokasi (site tersebar di berbagai daerah)
    let allPts = [];
    const jump = L.control({ position: 'topright' });
    jump.onAdd = () => {
        const d = L.DomUtil.create('div', 'map-jump');
        L.DomEvent.disableClickPropagation(d);
        L.DomEvent.disableScrollPropagation(d);
        return d;
    };
    jump.addTo(map);
    const jumpEl = jump.getContainer();
    jumpEl.addEventListener('click', (e) => {
        const b = e.target.closest('[data-go]');
        if (!b) return;
        if (b.dataset.go === 'all') {
            if (allPts.length) allPts.length === 1 ? map.setView(allPts[0], 14) : map.fitBounds(allPts, { padding: [40, 40], maxZoom: 15 });
        } else {
            const m = siteMarkers.get(+b.dataset.go);
            if (m) { map.flyTo(m.getLatLng(), plan ? map.getZoom() : 14, { duration: 0.8 }); m.openPopup(); }
        }
    });
    const renderJump = (sites) => {
        const placed = sites.filter((x) => x.lat !== null);
        if (!placed.length) { jumpEl.hidden = true; return; }
        jumpEl.hidden = false;
        const order = { down: 0, problem: 1, maintenance: 2, up: 3, idle: 4 };
        placed.sort((a, b) => (order[a.state] - order[b.state]) || a.code.localeCompare(b.code));
        jumpEl.innerHTML = `<div class="mj-hd"><b>Site</b><button type="button" data-go="all">Semua</button></div>`
            + placed.map((x) => `<button type="button" class="mj-it" data-go="${x.id}"><i class="dot ${x.state}"></i><span>${esc(x.code)}</span>${x.down ? `<em>${x.down} down</em>` : ''}</button>`).join('');
    };
    const siteMarkers = new Map();
    const devMarkers = new Map();
    let editing = false;
    let fitted = false;

    const save = async (type, id, lat, lng) => {
        const body = plan
            ? { type, id, plan_id: plan.id, x: lat === null ? null : Math.max(0, lng), y: lat === null ? null : Math.max(0, plan.h - lat) }
            : { type, id, lat, lng };
        const r = await fetch(el.dataset.posUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body),
        });
        if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || `gagal (${r.status})`);
        await load();
    };

    const siteIcon = (s) => L.divIcon({
        className: '',
        html: `<div class="mk-site ${s.state}"><b>${esc(s.code)}</b>${s.down ? `<span>${s.down}</span>` : ''}</div>`,
        iconSize: null,
        iconAnchor: [0, 0],
    });
    const devIcon = (d) => L.divIcon({ className: '', html: `<div class="mk-dev ${d.state}" title="${esc(d.name)}"></div>`, iconSize: [14, 14], iconAnchor: [7, 7] });

    const sitePopup = (s) => `<div class="mp"><b>${esc(s.code)} — ${esc(s.name)}</b><div class="mp-st ${s.state}">${STATE[s.state]}</div>
        <div>${s.total} perangkat${s.down ? ` · <b style="color:var(--crit)">${s.down} down</b>` : ''}${s.problem ? ` · ${s.problem} problem` : ''}${s.maintenance ? ` · ${s.maintenance} maintenance` : ''}</div>
        ${s.down_list.length ? `<ul>${s.down_list.map((d) => `<li><a href="${esc(d.url)}">${esc(d.name)}</a></li>`).join('')}</ul>` : ''}
        <a href="${esc(s.url)}">Lihat perangkat site →</a></div>`;
    const devPopup = (d) => `<div class="mp"><b>${esc(d.name)}</b><div class="mono" style="font-size:12px">${esc(d.host)}${d.site ? ' · ' + esc(d.site) : ''}</div>
        <div class="mp-st ${d.state}">${STATE[d.state] || esc(d.state)}</div>${d.reason ? `<div style="font-size:12px">${esc(d.reason)}</div>` : ''}<a href="${esc(d.url)}">Detail perangkat →</a></div>`;

    const place = (store, key, item, icon, popup, type) => {
        let m = store.get(key);
        if (!m) {
            m = L.marker([item.lat, item.lng], { icon, draggable: editing, riseOnHover: true, zIndexOffset: type === 'site' ? 1000 : 0 });
            m.on('dragend', () => {
                const p = m.getLatLng();
                save(type, item.id, round(p.lat), round(p.lng)).catch((e) => alert(`Posisi tidak tersimpan: ${e.message}`));
            });
            m.on('click', () => { if (editing) onSelect?.(`${type}:${item.id}`); });
            m.addTo(map);
            store.set(key, m);
        } else {
            m.setLatLng([item.lat, item.lng]);
            m.setIcon(icon);
        }
        m.bindPopup(popup, { maxWidth: 280 });
        if (m.dragging) editing ? m.dragging.enable() : m.dragging.disable();
        return m;
    };

    async function load() {
        const r = await fetch(el.dataset.url, { headers: { Accept: 'application/json' } }).catch(() => null);
        if (!r || !r.ok) return null;
        const j = await r.json();
        const keepS = new Set();
        const keepD = new Set();
        const pts = [];
        j.sites.filter((s) => s.lat !== null).forEach((s) => { keepS.add(s.id); pts.push([s.lat, s.lng]); place(siteMarkers, s.id, s, siteIcon(s), sitePopup(s), 'site'); });
        j.devices.forEach((d) => { keepD.add(d.id); pts.push([d.lat, d.lng]); place(devMarkers, d.id, d, devIcon(d), devPopup(d), 'device'); });
        for (const [k, m] of siteMarkers) if (!keepS.has(k)) { m.remove(); siteMarkers.delete(k); }
        for (const [k, m] of devMarkers) if (!keepD.has(k)) { m.remove(); devMarkers.delete(k); }
        linkLayer.clearLayers();
        j.links.forEach((l) => {
            const color = l.down ? 'var(--crit)' : (l.util >= 90 ? 'var(--warn)' : 'var(--accent)');
            L.polyline([l.a, l.b], { color, weight: l.down ? 4 : 3, opacity: 0.85, dashArray: l.logical ? '6 6' : null, className: l.down ? 'lk-down' : '' }).addTo(linkLayer);
        });
        allPts = pts;
        renderJump(j.sites);
        if (!fitted && pts.length && !plan) {
            fitted = true;
            pts.length === 1 ? map.setView(pts[0], 14) : map.fitBounds(pts, { padding: [40, 40], maxZoom: 15 });
        }
        return j;
    }

    return {
        load,
        save,
        map,
        setEditing(on) {
            editing = on;
            el.classList.toggle('editing', on);
            for (const m of [...siteMarkers.values(), ...devMarkers.values()]) if (m.dragging) on ? m.dragging.enable() : m.dragging.disable();
            setTimeout(() => map.invalidateSize(), 50);
        },
        onClick(fn) { map.on('click', (e) => { if (editing) fn(round(e.latlng.lat), round(e.latlng.lng)); }); },
        focus(lat, lng) { map.setView([lat, lng], plan ? map.getZoom() : Math.max(map.getZoom(), 14)); },
        plan,
    };
}
