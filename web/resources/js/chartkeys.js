// Penanda warna legenda uPlot: uPlot menulis warna seri sebagai style inline. Di sini warna itu dicocokkan ke token
// (aksen, info, kritis, peringatan, ok) lalu diganti atribut data-k, sehingga warna datang dari CSS token.
const TOKENS = ['accent', 'info', 'crit', 'warn', 'ok', 'muted'];

const rgb = (c) => {
    const el = document.createElement('i');
    el.style.color = c;
    document.body.append(el);
    const v = getComputedStyle(el).color;
    el.remove();
    const m = v.match(/[\d.]+/g) || [];
    return { r: +m[0], g: +m[1], b: +m[2], a: m[3] === undefined ? 1 : +m[3] };
};

export function tokenKeys(plot) {
    const ref = TOKENS.map((t) => [t, rgb(`var(--color-${t})`)]);
    plot.root.querySelectorAll('.u-series .u-marker').forEach((m) => {
        const c = rgb(m.style.borderColor || m.style.background || 'transparent');
        const hit = ref.find(([, v]) => Math.abs(v.r - c.r) + Math.abs(v.g - c.g) + Math.abs(v.b - c.b) < 12);
        if (!hit) return;
        m.removeAttribute('style');
        m.dataset.k = hit[0] + (c.a < 0.6 ? '-soft' : '');
    });
}
