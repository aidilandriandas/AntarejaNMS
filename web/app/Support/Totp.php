<?php

namespace App\Support;

/**
 * TOTP (RFC 6238, HMAC-SHA1, 6 digit, langkah 30 detik) untuk verifikasi dua langkah.
 * Cocok dengan Google Authenticator, Microsoft Authenticator, Aegis, FreeOTP, dll.
 */
class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    /** Rahasia baru 160 bit dalam base32 (32 karakter). */
    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function unbase32(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $v = strpos(self::B32, $c);
            if ($v === false) {
                return '';
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** Kode 6 digit untuk langkah waktu tertentu (RFC 4226 dynamic truncation). */
    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::unbase32($secret), true);
        $o = ord($h[19]) & 0x0F;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Periksa kode dengan toleransi ±1 langkah (jam HP selisih sampai 30 detik). Langkah yang sudah pernah dipakai
     * ($lastStep) ditolak supaya kode yang tertangkap orang lain tidak bisa dipakai ulang. Kembalian: langkah yang cocok.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return null;
        }
        $t = intdiv($now ?? time(), self::PERIOD);
        foreach ([0, -1, 1] as $d) {
            $s = $t + $d;
            if ($lastStep !== null && $s <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $s), $code)) {
                return $s;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer = 'ANTAREJA NMS'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=' . self::PERIOD;
    }
}
