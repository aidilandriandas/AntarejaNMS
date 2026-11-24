<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceTemplate;
use App\Models\ServiceCheck;
use Illuminate\Support\Facades\DB;

/**
 * Memasang isi templat ke perangkat: cek layanan dibuat/diperbarui/dihapus per perangkat, URL exporter diisi
 * (hanya bila perangkat tidak punya URL sendiri). Ambang tidak disalin: collector menggabungkannya saat membaca.
 *
 * Makro: {HOST}, {NAME}, {SITE} dari perangkat; {$NAMA} dari makro perangkat, lalu makro templat.
 */
class TemplateSync
{
    public const MACRO_RE = '/^\{\$[A-Z0-9_]{1,40}\}$/';

    /** @return array<string, string> makro berlaku untuk perangkat */
    public static function macros(Device $d, ?DeviceTemplate $t): array
    {
        $m = ['{HOST}' => (string) $d->host, '{NAME}' => (string) $d->name, '{SITE}' => (string) ($d->site?->code ?? '')];
        foreach (array_merge($t?->macros ?? [], $d->macros ?? []) as $k => $v) {
            $m[$k] = (string) $v;
        }

        return $m;
    }

    public static function resolve(?string $s, array $macros): ?string
    {
        return $s === null ? null : strtr($s, $macros);
    }

    /** Makro yang masih tersisa setelah diganti (belum diisi). */
    public static function unresolved(?string $s): array
    {
        preg_match_all('/\{\$[A-Z0-9_]+\}|\{(HOST|NAME|SITE)\}/', (string) $s, $m);

        return array_values(array_unique($m[0]));
    }

    /** Sinkronkan satu perangkat dengan templatnya. Kembalian: pesan peringatan (makro belum diisi). */
    public static function apply(Device $d): array
    {
        $d->loadMissing('site');
        $t = $d->template_id ? DeviceTemplate::with('checks')->find($d->template_id) : null;
        $macros = self::macros($d, $t);
        $warn = [];
        DB::transaction(function () use ($d, $t, $macros, &$warn) {
            $keep = [];
            foreach ($t?->checks ?? [] as $c) {
                if (! $c->enabled) {
                    continue;
                }
                $row = ['name' => mb_substr(self::resolve($c->name, $macros), 0, 128), 'type' => $c->type, 'severity' => $c->severity,
                    'url' => self::resolve($c->url, $macros), 'query' => self::resolve($c->query, $macros),
                    'expect_status' => $c->expect_status, 'expect_text' => self::resolve($c->expect_text, $macros), 'expect_answer' => self::resolve($c->expect_answer, $macros),
                    'verify_tls' => $c->verify_tls, 'interval_s' => $c->interval_s, 'timeout_s' => $c->timeout_s, 'slow_ms' => $c->slow_ms,
                    'cert_warn_days' => $c->cert_warn_days, 'enabled' => true];
                $port = self::resolve($c->port, $macros);
                $row['port'] = is_numeric($port) && $port >= 1 && $port <= 65535 ? (int) $port : null;
                $left = array_merge(self::unresolved($row['url']), self::unresolved($row['query']), self::unresolved($port));
                if ($left || ($c->type === 'tcp' && ! $row['port']) || ($c->type === 'http' && ! preg_match('#^https?://[^\s{}]+$#i', (string) $row['url']))) {
                    $warn[] = "Cek \"{$c->name}\" tidak dipasang ke {$d->name}: " . ($left ? 'makro ' . implode(', ', $left) . ' belum diisi' : 'alamat atau port tidak sah');

                    continue;
                }
                $keep[] = $c->id;
                $sc = ServiceCheck::where('device_id', $d->id)->where('template_check_id', $c->id)->first() ?? new ServiceCheck(['device_id' => $d->id]);
                $sc->fill($row);
                $sc->template_check_id = $c->id;
                $sc->save();
            }
            ServiceCheck::where('device_id', $d->id)->whereNotNull('template_check_id')->when($keep, fn ($q) => $q->whereNotIn('template_check_id', $keep))->delete();

            // exporter: hanya diisi bila perangkat belum punya URL sendiri, atau URL-nya memang dari templat
            if ($t?->exporter_url && (! $d->exporter_url || $d->exporter_from_template)) {
                $url = self::resolve($t->exporter_url, $macros);
                if (self::unresolved($url)) {
                    $warn[] = "URL exporter {$d->name}: makro " . implode(', ', self::unresolved($url)) . ' belum diisi';
                } else {
                    $d->forceFill(['exporter_url' => $url, 'exporter_services' => $t->exporter_services, 'exporter_from_template' => true])->saveQuietly();
                }
            } elseif ($d->exporter_from_template) {
                $d->forceFill(['exporter_url' => null, 'exporter_services' => null, 'exporter_from_template' => false])->saveQuietly();
                DB::table('device_sensors')->where('device_id', $d->id)->where('key', 'like', 'px:%')->delete();
            }
        });

        return $warn;
    }

    /** Sinkronkan semua perangkat bertemplat ini. */
    public static function applyTemplate(DeviceTemplate $t): array
    {
        $warn = [];
        foreach (Device::where('template_id', $t->id)->get() as $d) {
            $warn = array_merge($warn, self::apply($d));
        }

        return $warn;
    }

    /** "KEY=nilai" per baris menjadi ['{$KEY}' => 'nilai']; galat berisi baris yang salah. */
    public static function parseMacros(?string $text): array
    {
        $out = [];
        $bad = [];
        foreach (preg_split('/\R/', (string) $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $line, 2), 2, null);
            $k = strtoupper(trim((string) $k));
            $k = preg_match('/^\{\$([A-Z0-9_]+)\}$/', $k, $mm) ? $mm[1] : $k;
            if ($v === null || ! preg_match('/^[A-Z0-9_]{1,40}$/', $k) || mb_strlen(trim($v)) > 200) {
                $bad[] = trim($line);

                continue;
            }
            $out['{$' . $k . '}'] = trim($v);
        }

        return [$out, $bad];
    }

    public static function macroText(?array $m): string
    {
        return collect($m ?? [])->map(fn ($v, $k) => trim($k, '{}$') . '=' . $v)->implode("\n");
    }
}
