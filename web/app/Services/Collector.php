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
}
