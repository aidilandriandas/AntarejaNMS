<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/** Kamera CCTV (RTSP). Password terenkripsi; URL RTSP hanya dibentuk di server untuk go2rtc. */
class Camera extends Model
{
    protected $fillable = ['name', 'device_id', 'site_id', 'host', 'rtsp_port', 'channel', 'path_main', 'path_sub', 'username', 'enabled', 'sort'];

    protected $hidden = ['password_enc'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function setPassword(?string $plain): void
    {
        if ($plain !== null && $plain !== '') {
            $this->password_enc = Crypt::encryptString($plain);
        }
    }

    public function streamName(string $q): string
    {
        return "cam{$this->id}_" . ($q === 'main' ? 'main' : 'sub');
    }

    /** URL RTSP lengkap dengan kredensial — HANYA untuk dikirim ke go2rtc di jaringan internal. */
    public function rtspUrl(string $q): string
    {
        $path = str_replace('{ch}', (string) $this->channel, $q === 'main' ? $this->path_main : $this->path_sub);
        $auth = '';
        if ($this->username) {
            $pass = $this->password_enc ? Crypt::decryptString($this->password_enc) : '';
            $auth = rawurlencode($this->username) . ':' . rawurlencode($pass) . '@';
        }

        return 'rtsp://' . $auth . $this->host . ':' . $this->rtsp_port . '/' . ltrim($path, '/');
    }
}
