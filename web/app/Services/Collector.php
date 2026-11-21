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

    /** Minta collector memeriksa satu perangkat sekarang (tidak menunggu hasil). */
    public function pollNow(int $deviceId): bool
    {
        try {
            return $this->http()->timeout(5)->post("/v1/devices/{$deviceId}/poll")->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
