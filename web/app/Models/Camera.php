<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/** Kamera CCTV (RTSP). Password terenkripsi; URL RTSP hanya dibentuk di server untuk go2rtc. */
class Camera extends Model
{
    protected $fillable = ['name', 'device_id', 'site_id', 'host', 'rtsp_port', 'channel', 'path_main', 'path_sub', 'username', 'enabled', 'sort', 'profile_id'];

    protected $hidden = ['password_enc'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'health_at' => 'datetime', 'health_since' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CameraProfile::class, 'profile_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public const HEALTH = [
        'ok' => ['Normal', 'ok'], 'dark' => ['Gelap', 'warn'], 'covered' => ['Tertutup / buram', 'warn'],
        'frozen' => ['Beku', 'warn'], 'nosignal' => ['Tanpa sinyal', 'crit'],
    ];

    public function healthMeta(): ?array
    {
        return $this->health ? (self::HEALTH[$this->health] ?? null) : null;
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
        // kamera berprofil: user & password dari profil (ganti password cukup di profil)
        [$user, $enc] = $this->profile_id && $this->profile ? [$this->profile->username, $this->profile->password_enc] : [$this->username, $this->password_enc];
        if ($user) {
            $pass = $enc ? Crypt::decryptString($enc) : '';
            $auth = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        }

        return 'rtsp://' . $auth . $this->host . ':' . $this->rtsp_port . '/' . ltrim($path, '/');
    }
}
