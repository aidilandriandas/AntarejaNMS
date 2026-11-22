<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\Http;

/** Mendaftarkan aliran kamera ke go2rtc (API internal, tidak dapat diakses browser). */
class Go2rtc
{
    private function http()
    {
        return Http::baseUrl((string) config('antareja.go2rtc_url'))->timeout(5)->connectTimeout(2);
    }

    /** Pastikan aliran terdaftar (idempoten). */
    public function ensure(Camera $cam, string $q): bool
    {
        try {
            // go2rtc mendaftarkan aliran di memori lalu mencoba menyimpannya ke berkas konfigurasi. Berkas itu sengaja
            // read-only (agar URL RTSP berpassword tidak pernah tertulis ke disk), sehingga PUT bisa menjawab 400
            // walau aliran sudah aktif. Karena itu keberhasilan dicek dari keberadaan aliran, bukan kode status PUT.
            $this->http()->put('/api/streams?' . http_build_query(['name' => $cam->streamName($q), 'src' => $cam->rtspUrl($q)]));

            return $this->http()->get('/api/streams', ['src' => $cam->streamName($q)])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function forget(Camera $cam): void
    {
        foreach (['main', 'sub'] as $q) {
            try {
                $this->http()->delete('/api/streams?' . http_build_query(['src' => $cam->streamName($q)]));
            } catch (\Throwable) {
                // go2rtc mati: aliran hilang sendiri saat restart
            }
        }
    }
}
