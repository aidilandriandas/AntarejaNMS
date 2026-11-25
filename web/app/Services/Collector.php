<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien API internal collector (jaringan Docker internal). Token = HMAC-SHA256 dari kunci aplikasi,
 * sama dengan perhitungan di collector, jadi tidak ada rahasia tambahan.
 */
class Collector
{
    private function token(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return hash_hmac('sha256', 'antareja-collector-api', $key);
    }

    private function http()
    {
        $url = (string) config('antareja.collector_url');
        if ($url === '') {
            throw new RuntimeException('ANTAREJA_COLLECTOR_URL belum diatur');
        }

        return Http::baseUrl($url)->withToken($this->token())->acceptJson()->connectTimeout(3);
    }

    /** Uji ping/SNMP tanpa menyimpan. $snmp = null bila SNMP tidak diuji. */
    public function test(string $host, bool $ping, ?array $snmp): array
    {
        $res = $this->http()->timeout(40)->post('/v1/test', array_filter(['host' => $host, 'ping' => $ping, 'snmp' => $snmp], fn ($v) => $v !== null));
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Kirim pesan uji ke kanal notifikasi. @return array{ok: bool, error?: string} */
    public function testChannel(int $channelId): array
    {
        $res = $this->http()->timeout(40)->post("/v1/channels/{$channelId}/test");
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Pratinjau laporan terjadwal (periode penuh terakhir). @return array{ok: bool, subject?: string, html?: string, error?: string} */
    public function reportPreview(int $scheduleId): array
    {
        $res = $this->http()->timeout(40)->get("/v1/reports/{$scheduleId}/preview");
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Counter oktet port saat ini (untuk trafik live). @param array<int, array{device_id:int, if_index:int}> $ports */
    public function live(array $ports): array
    {
        $res = $this->http()->timeout(15)->post('/v1/live', ['ports' => array_values($ports)]);

        return $res->successful() ? ($res->json('samples') ?? []) : [];
    }

    /** Minta collector memeriksa satu perangkat sekarang (tidak menunggu hasil). */
    public function pollNow(int $deviceId): bool
    {
        try {
            return $this->http()->timeout(5)->post("/v1/devices/{$deviceId}/poll")->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Sinkron topologi sekarang: putaran SNMP penuh di collector. 'started', 'busy', atau 'down'. */
    public function syncTopology(): string
    {
        try {
            $res = $this->http()->timeout(5)->post('/v1/topology/sync');
        } catch (\Throwable) {
            return 'down';
        }

        return match (true) {
            $res->status() === 409 => 'busy',
            $res->successful() => 'started',
            default => 'down',
        };
    }

    /** Berkas diagnosa SNMP satu perangkat (hanya baca, tanpa kredensial). */
    public function diagnose(int $deviceId): array
    {
        $res = $this->http()->timeout(60)->get("/v1/devices/{$deviceId}/diagnose");
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Alat diagnosa ke host perangkat: ping, traceroute, atau cek port TCP. Menunggu hasil (maks. ±40 detik). */
    public function tool(int $deviceId, string $tool, ?int $port = null): array
    {
        $res = $this->http()->timeout(50)->post("/v1/devices/{$deviceId}/tools", array_filter(['tool' => $tool, 'port' => $port]));
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Baca semua titik Modbus satu perangkat sekarang (tanpa menyimpan). */
    public function modbusTest(int $deviceId): array
    {
        $res = $this->http()->timeout(40)->post("/v1/devices/{$deviceId}/modbus/test");
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Laju flow 10 detik (5 menit terakhir) untuk satu port; sekaligus menandai port ditonton di collector. */
    public function flowLive(int $deviceId, int $ifIndex, string $dir, string $by): array
    {
        $res = $this->http()->timeout(5)->get('/v1/flow/live', ['device' => $deviceId, 'if' => $ifIndex, 'dir' => $dir, 'by' => $by]);
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Scrape exporter satu perangkat sekarang (URL dari database). */
    public function exporterTest(int $deviceId): array
    {
        $res = $this->http()->timeout(30)->post("/v1/devices/{$deviceId}/exporter/test");
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Jalankan satu cek layanan sekarang. @return array{ok: bool, ms: float, error: string, status: int} */
    public function runCheck(int $checkId): array
    {
        $res = $this->http()->timeout(75)->post("/v1/checks/{$checkId}/run");
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /** Uji tujuan salinan backup (tulis, daftar, hapus berkas kecil). */
    public function offsiteTest(): array
    {
        $res = $this->http()->timeout(60)->post('/v1/backup/offsite/test');
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error') ?? ('collector menjawab ' . $res->status()));
        }

        return $res->json();
    }

    /**
     * Teruskan kiriman collector site ke collector pusat. @return array{0:int,1:array} status HTTP dan isi jawaban.
     * Galat 4xx dari collector diteruskan (kiriman ditolak permanen); galat jaringan dilempar.
     */
    public function siteIngest(int $collectorId, string $body, array $headers): array
    {
        $res = $this->http()->timeout(300)->withHeaders(array_filter($headers, fn ($v) => $v !== ''))
            ->withBody($body, 'application/json')->post("/v1/site-collectors/{$collectorId}/ingest");
        $status = $res->status();
        if ($status >= 500) {
            $status = 503;
        }

        return [$status, (array) ($res->json() ?? ['error' => 'jawaban collector tidak terbaca'])];
    }
}
