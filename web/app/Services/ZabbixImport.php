<?php

namespace App\Services;

/**
 * Mengubah ekspor host Zabbix (Data collection, Hosts, Export, JSON) menjadi baris impor perangkat ANTAREJA.
 * Hanya membaca berkas; tidak menghubungi server Zabbix. Yang diambil: nama, IP/DNS interface pertama, status,
 * SNMP (versi, port, community; makro {$SNMP_COMMUNITY} dari makro host atau nilai yang diisi pengguna), grup &
 * deskripsi sebagai catatan. Kategori ditebak dari templat dan awalan nama.
 */
class ZabbixImport
{
    public const MAX_HOSTS = 2000;

    public static function looksLike(string $raw): bool
    {
        return str_starts_with(ltrim($raw), '{') && str_contains(substr($raw, 0, 400), 'zabbix_export');
    }

    /** @return array<int, array> baris dengan kunci sama seperti impor CSV, plus enabled, port, notes, source */
    public static function rows(string $raw, string $globalCommunity = ''): array
    {
        $d = json_decode($raw, true, 64);
        $hosts = $d['zabbix_export']['hosts'] ?? null;
        if (! is_array($hosts)) {
            throw new \RuntimeException('Bukan ekspor host Zabbix (tidak ada zabbix_export.hosts).');
        }
        if (count($hosts) > self::MAX_HOSTS) {
            throw new \RuntimeException('Maksimal ' . self::MAX_HOSTS . ' host sekali impor.');
        }
        $out = [];
        foreach (array_values($hosts) as $i => $h) {
            if (! is_array($h)) {
                continue;
            }
            $name = trim(preg_replace('/\s+/', ' ', (string) ($h['name'] ?? $h['host'] ?? '')));
            $if = is_array($h['interfaces'][0] ?? null) ? $h['interfaces'][0] : [];
            $useDns = ($if['useip'] ?? 'YES') === 'NO';
            $host = trim((string) ($useDns ? ($if['dns'] ?? '') : ($if['ip'] ?? '')));
            if ($host === '' && ! empty($if['dns'])) {
                $host = trim((string) $if['dns']);
            }
            $templates = array_map(fn ($t) => (string) ($t['name'] ?? ''), (array) ($h['templates'] ?? []));
            $groups = array_map(fn ($g) => (string) ($g['name'] ?? ''), (array) ($h['groups'] ?? []));
            $snmpTpl = array_filter($templates, fn ($t) => stripos($t, 'snmp') !== false || stripos($t, 'airos') !== false || stripos($t, 'unifi usw') !== false
                || stripos($t, 'power beam') !== false);
            $snmp = '';
            $community = '';
            $port = 161;
            $note = [];
            if (($if['type'] ?? '') === 'SNMP' && $snmpTpl) {
                $ver = strtoupper((string) ($if['details']['version'] ?? 'SNMPV2'));
                $snmp = $ver === 'SNMPV1' ? '1' : ($ver === 'SNMPV3' ? '3' : '2c');
                $port = (int) ($if['port'] ?? 161) ?: 161;
                $community = (string) ($if['details']['community'] ?? '');
                if (preg_match('/^\{\$[A-Z0-9_.]+\}$/', $community)) {
                    $macro = $community;
                    $community = '';
                    foreach ((array) ($h['macros'] ?? []) as $m) {
                        if (($m['macro'] ?? '') === $macro && isset($m['value'])) {
                            $community = (string) $m['value'];
                        }
                    }
                    if ($community === '' && $macro === '{$SNMP_COMMUNITY}') {
                        $community = $globalCommunity;
                    }
                    if ($community === '') {
                        $note[] = "community memakai makro {$macro} yang nilainya tidak ada di berkas: SNMP dimatikan, isi nilainya lalu pratinjau ulang";
                        $snmp = '';
                    }
                }
                if ($snmp === '3') {
                    $note[] = 'SNMPv3 diimpor sebagai ping saja; isi kredensial v3 lewat form perangkat';
                    $snmp = '';
                    $community = '';
                }
            }
            $out[] = [
                'line' => $i + 1, 'nama' => mb_substr($name, 0, 128), 'host' => $host, 'site' => '', 'kategori' => self::category($name, $templates),
                'snmp' => $snmp, 'community' => $community, 'induk' => '', 'port' => $port,
                'enabled' => ($h['status'] ?? 'ENABLED') !== 'DISABLED',
                'notes' => mb_substr(trim(implode("\n", array_filter([
                    $h['description'] ?? null,
                    $groups ? 'Grup Zabbix: ' . implode(', ', $groups) : null,
                    $templates ? 'Templat Zabbix: ' . implode(', ', $templates) : null,
                ]))), 0, 2000),
                'warnings' => $note,
                'source' => 'zabbix',
            ];
        }

        return $out;
    }

    /** Kategori ANTAREJA dari templat dan awalan nama host (pola penamaan umum di tambang). */
    public static function category(string $name, array $templates): string
    {
        $n = strtoupper($name);
        $t = strtoupper(implode(' ', $templates));
        return match (true) {
            str_contains($t, 'FORTIGATE') || str_starts_with($n, 'FORTIGATE') => 'firewall',
            (bool) preg_match('/^(CCTV|CAM)\b/', $n) => 'cctv',
            (bool) preg_match('/\b(M?DVR|NVR)\b/', $n) => 'nvr',
            str_contains($t, 'VMWARE') || str_contains($t, 'LINUX') || str_contains($t, 'ZABBIX SERVER') || (bool) preg_match('/^(SERVER|VM\d|SAP\b|ZABBIX)/', $n) => 'server',
            (bool) preg_match('/^(CCR|RB\d|GATEWAY|ROUTER)/', $n) || str_contains($t, 'CISCO IOS') => 'core',
            (bool) preg_match('/^CRS\d/', $n) => 'distribution',
            (bool) preg_match('/^(USW|SW\b|SW\.|SWITCH)/', $n) || str_contains($t, 'UNIFI USW') => 'access',
            (bool) preg_match('/\b(PTP|PTMP|LTU|STATION|RPT)\b/', $n) || (bool) preg_match('/^(EXCA|MT\s?\d|PONDOK)/', $n) => 'ptp',
            (bool) preg_match('/^(AP|WAP|UNIFI)\b/', $n) || str_contains($t, 'AIROS') => 'wireless',
            default => 'other',
        };
    }
}
