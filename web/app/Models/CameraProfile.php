<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/** Profil login kamera: user & password RTSP yang dipakai banyak kamera. Password tidak pernah dikirim ke browser. */
class CameraProfile extends Model
{
    protected $fillable = ['name', 'username', 'rtsp_port', 'path_main', 'path_sub'];

    protected $hidden = ['password_enc'];

    public function cameras(): HasMany
    {
        return $this->hasMany(Camera::class, 'profile_id');
    }

    /** null = biarkan password lama; '' = tetap (form kosong); isi = ganti. */
    public function setPassword(?string $plain): void
    {
        if ($plain !== null && $plain !== '') {
            $this->password_enc = Crypt::encryptString($plain);
        }
    }
}
