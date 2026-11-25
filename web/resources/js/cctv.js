// Pemutar live view CCTV: MSE lewat WebSocket go2rtc (diproksikan web ANTAREJA di /live/ws, wajib login).
// Ditulis untuk ANTAREJA berdasarkan protokol WebSocket go2rtc: kirim daftar codec yang didukung,
// terima mime type, lalu potongan fMP4 biner untuk SourceBuffer.

const CODECS = ['avc1.640029', 'avc1.64002A', 'avc1.640033', 'avc1.4D401F', 'avc1.42E01E', 'hvc1.1.6.L153.B0', 'mp4a.40.2', 'mp4a.40.5', 'flac', 'opus'];

export function play(video, src, onStatus) {
    const MS = window.ManagedMediaSource || window.MediaSource;
    if (!MS) { onStatus?.('Browser tidak mendukung MSE'); return () => {}; }
    const supported = CODECS.filter((c) => MS.isTypeSupported(`video/mp4; codecs="${c}"`)).join(',');
    const ms = new MS();
    video.disableRemotePlayback = true;
    video.src = URL.createObjectURL(ms);
    const proto = location.protocol === 'https:' ? 'wss' : 'ws';
    const ws = new WebSocket(`${proto}://${location.host}/live/ws?src=${encodeURIComponent(src)}`);
    ws.binaryType = 'arraybuffer';
    let sb = null;
    const queue = [];
    let closed = false;

    const pump = () => {
        if (!sb || sb.updating || !queue.length) return;
        try {
            sb.appendBuffer(queue.shift());
        } catch (e) {
            // buffer penuh: buang data lama lalu coba lagi
            if (sb.buffered.length) sb.remove(sb.buffered.start(0), Math.max(sb.buffered.start(0), video.currentTime - 5));
        }
    };

    ms.addEventListener('sourceopen', () => {
        onStatus?.('Menghubungkan');
        ws.onopen = () => ws.send(JSON.stringify({ type: 'mse', value: supported }));
        if (ws.readyState === WebSocket.OPEN) ws.onopen();
    }, { once: true });

    ws.onmessage = (ev) => {
        if (typeof ev.data === 'string') {
            const msg = JSON.parse(ev.data);
            if (msg.type === 'mse') {
                sb = ms.addSourceBuffer(msg.value);
                sb.mode = 'segments';
                sb.addEventListener('updateend', () => {
                    // tetap dekat "live": lompat ke ujung bila tertinggal > 3 detik
                    if (sb.buffered.length) {
                        const end = sb.buffered.end(sb.buffered.length - 1);
                        if (end - video.currentTime > 3) video.currentTime = end - 0.5;
                        if (video.currentTime - sb.buffered.start(0) > 20 && !sb.updating) sb.remove(sb.buffered.start(0), video.currentTime - 10);
                    }
                    pump();
                });
                video.play().catch(() => {});
                onStatus?.('');
            } else if (msg.type === 'error') {
                onStatus?.(/401|Unauthorized|wrong user\/pass/i.test(msg.value) ? 'Kamera menolak login (user/password RTSP salah)'
                    : msg.value.includes('timeout') || msg.value.includes('i/o') ? 'Kamera tidak menjawab (cek IP, port 554, jaringan)' : `Gagal: ${msg.value}`);
            }
        } else {
            queue.push(ev.data);
            pump();
        }
    };
    ws.onclose = () => { if (!closed) onStatus?.('Koneksi video terputus'); };
    ws.onerror = () => onStatus?.('Koneksi video gagal');

    return () => {
        closed = true;
        ws.close();
        try { if (ms.readyState === 'open') ms.endOfStream(); } catch (e) { /* abaikan */ }
        URL.revokeObjectURL(video.src);
        video.removeAttribute('src');
    };
}
