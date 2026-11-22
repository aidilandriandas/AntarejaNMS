// Peta lokasi ANTAREJA (Leaflet). Penanda memakai divIcon (tanpa gambar eksternal); gambar peta dari URL tile pengaturan admin.
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const STATE = { up: 'Normal', problem: 'Ada problem', down: 'Down', maintenance: 'Maintenance', idle: 'Tanpa perangkat', unknown: 'Belum diketahui' };

export function createMap(el, { csrf, onSelect }) {
    const map = L.map(el, { zoomControl: true, attributionControl: true, worldCopyJump: true });
    if (el.dataset.tiles) {
        L.tileLayer(el.dataset.tiles, { maxZoom: 19, attribution: esc(el.dataset.attr) }).addTo(map);
    } else {
        el.classList.add('no-tiles');
    }
    map.attributionControl.setPrefix('');
    map.setView([-2.5, 117], 5); // Indonesia sampai ada penanda

    const linkLayer = L.layerGroup().addTo(map);
    const siteMarkers = new Map();
    const devMarkers = new Map();
    let editing = false;
    let fitted = false;

    const save = async (type, id, lat, lng) => {
        const r = await fetch(el.dataset.posUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ type, id, lat, lng }),
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
            m = L.marker([item.lat, item.lng], { icon, draggable: editing, riseOnHover: true, zIndexOffset: type === 'site' ? 0 : 500 });
            m.on('dragend', () => {
                const p = m.getLatLng();
                save(type, item.id, +p.lat.toFixed(6), +p.lng.toFixed(6)).catch((e) => alert(`Posisi tidak tersimpan: ${e.message}`));
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
        if (!fitted && pts.length) {
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
        onClick(fn) { map.on('click', (e) => { if (editing) fn(+e.latlng.lat.toFixed(6), +e.latlng.lng.toFixed(6)); }); },
        focus(lat, lng) { map.setView([lat, lng], Math.max(map.getZoom(), 14)); },
    };
}
