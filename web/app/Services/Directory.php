<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Login lewat LDAP / Active Directory. Akun layanan (bind DN) mencari pengguna, lalu password pengguna diuji dengan
 * bind sebagai DN-nya. Peran ditentukan keanggotaan grup (atribut memberOf, atau atribut member di objek grup untuk
 * OpenLDAP). Nilai pencarian di-escape (ldap_escape) supaya tidak bisa menyuntik filter.
 */
class Directory
{
    /** @return array{dn:string,name:string,email:?string,role:?string}|null null = username/password salah */
    public function authenticate(string $username, string $password, bool $evenIfDisabled = false): ?array
    {
        $c = self::config();
        if (! $evenIfDisabled && ! ($c['enabled'] ?? false)) {
            return null;
        }
        if ($password === '' || ! function_exists('ldap_connect')) {
            return null;
        }
        $ldap = $this->connect($c);
        try {
            if (! @ldap_bind($ldap, $c['bind_dn'] ?? null, self::bindPassword())) {
                throw new RuntimeException('akun layanan LDAP ditolak: ' . ldap_error($ldap));
            }
            $filter = str_replace('{username}', ldap_escape($username, '', LDAP_ESCAPE_FILTER), $c['user_filter'] ?: '(&(objectClass=user)(sAMAccountName={username}))');
            $attrs = ['dn', 'cn', $c['name_attr'] ?: 'displayName', $c['mail_attr'] ?: 'mail', 'memberOf'];
            $res = @ldap_search($ldap, $c['base_dn'], $filter, $attrs, 0, 2, 10);
            $entries = $res ? ldap_get_entries($ldap, $res) : ['count' => 0];
            if (($entries['count'] ?? 0) !== 1) {
                return null; // tidak ada, atau ambigu (lebih dari satu)
            }
            $e = $entries[0];
            $dn = $e['dn'];
            if (! @ldap_bind($ldap, $dn, $password)) {
                return null;
            }
            // kembali sebagai akun layanan untuk membaca grup
            @ldap_bind($ldap, $c['bind_dn'] ?? null, self::bindPassword());
            $member = array_map('strtolower', array_slice((array) ($e['memberof'] ?? []), 1));
            $in = function (?string $group) use ($ldap, $dn, $member) {
                if (! $group) {
                    return false;
                }
                if (in_array(strtolower($group), $member, true)) {
                    return true;
                }
                $r = @ldap_read($ldap, $group, '(|(member=' . ldap_escape($dn, '', LDAP_ESCAPE_FILTER) . ')(uniqueMember=' . ldap_escape($dn, '', LDAP_ESCAPE_FILTER) . '))', ['dn']);

                return $r && ldap_count_entries($ldap, $r) > 0;
            };
            $role = match (true) {
                $in($c['admin_group'] ?? null) => 'admin',
                $in($c['engineer_group'] ?? null) => 'engineer',
                empty($c['viewer_group']) || $in($c['viewer_group']) => 'viewer',
                default => null,
            };
            $nameAttr = strtolower($c['name_attr'] ?: 'displayName');
            $mailAttr = strtolower($c['mail_attr'] ?: 'mail');

            return ['dn' => $dn, 'name' => $e[$nameAttr][0] ?? $e['cn'][0] ?? $username, 'email' => $e[$mailAttr][0] ?? null, 'role' => $role];
        } finally {
            @ldap_unbind($ldap);
        }
    }

    /** Uji setelan: bind akun layanan dan hitung pengguna yang cocok dengan filter (tanpa username). */
    public function test(): string
    {
        $c = self::config();
        if (! function_exists('ldap_connect')) {
            throw new RuntimeException('ekstensi PHP ldap tidak tersedia');
        }
        $ldap = $this->connect($c);
        try {
            if (! @ldap_bind($ldap, $c['bind_dn'] ?? null, self::bindPassword())) {
                throw new RuntimeException('akun layanan ditolak: ' . ldap_error($ldap));
            }
            $filter = str_replace('{username}', '*', $c['user_filter'] ?: '(&(objectClass=user)(sAMAccountName={username}))');
            $res = @ldap_search($ldap, $c['base_dn'], $filter, ['dn'], 0, 1000, 10);
            if (! $res) {
                throw new RuntimeException('pencarian gagal: ' . ldap_error($ldap));
            }

            return 'terhubung, ' . ldap_count_entries($ldap, $res) . ' akun cocok dengan filter di ' . $c['base_dn'];
        } finally {
            @ldap_unbind($ldap);
        }
    }

    private function connect(array $c)
    {
        $enc = $c['encryption'] ?? 'starttls';
        $scheme = $enc === 'ldaps' ? 'ldaps' : 'ldap';
        $port = (int) ($c['port'] ?? 0) ?: ($enc === 'ldaps' ? 636 : 389);
        if (! ($c['verify_cert'] ?? true)) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }
        $ldap = @ldap_connect("{$scheme}://{$c['host']}:{$port}");
        if (! $ldap) {
            throw new RuntimeException('alamat server LDAP tidak valid');
        }
        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);
        if ($enc === 'starttls' && ! @ldap_start_tls($ldap)) {
            throw new RuntimeException('StartTLS gagal: ' . ldap_error($ldap));
        }

        return $ldap;
    }

    public static function config(): array
    {
        return (array) Setting::get('auth.ldap', ['enabled' => false]);
    }

    private static function bindPassword(): ?string
    {
        $enc = Setting::get('auth.ldap_bind_password');
        try {
            return is_string($enc) && $enc !== '' ? Crypt::decryptString($enc) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
