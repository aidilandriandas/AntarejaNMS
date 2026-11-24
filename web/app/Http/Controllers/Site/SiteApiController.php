<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Collector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * API untuk collector site (bukan untuk manusia). Konfigurasi berisi kredensial SNMP perangkat milik collector
 * itu saja, dikirim lewat HTTPS dan hanya disimpan di memori site. Hasil pengukuran diteruskan apa adanya ke
 * collector pusat, yang memeriksa bahwa setiap sampel memang milik perangkat collector ini.
 */
class SiteApiController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $c = $request->attributes->get('site_collector');
        $open = function (?string $enc): ?string {
            if (! $enc) {
                return null;
            }
            try {
                return Crypt::decryptString($enc);
            } catch (\Throwable) {
                return null;
            }
        };
        $devices = Device::where('collector_id', $c->id)->where('enabled', true)->orderBy('id')->get()->map(fn (Device $d) => [
            'id' => $d->id, 'name' => $d->name, 'host' => $d->host, 'category' => $d->category,
            'ping_enabled' => (bool) $d->ping_enabled, 'snmp_enabled' => (bool) $d->snmp_enabled,
            'thresholds' => $d->thresholds ?: null,
            'snmp' => $d->snmp_enabled ? array_filter([
                'version' => $d->snmp_version, 'port' => (int) $d->snmp_port,
                'community' => $d->snmp_version === '3' ? null : $open($d->snmp_community_enc),
                'user' => $d->snmp_v3_user, 'level' => $d->snmp_v3_level, 'auth_proto' => $d->snmp_v3_auth_proto,
                'auth_pass' => $d->snmp_version === '3' ? $open($d->snmp_v3_auth_enc) : null,
                'priv_proto' => $d->snmp_v3_priv_proto, 'priv_pass' => $d->snmp_version === '3' ? $open($d->snmp_v3_priv_enc) : null,
            ], fn ($v) => $v !== null && $v !== '') : null,
        ])->values();

        $checks = \App\Models\ServiceCheck::with('device:id,host')->where('enabled', true)
            ->whereHas('device', fn ($q) => $q->where('collector_id', $c->id)->where('enabled', true))->get()
            ->map(fn ($k) => array_filter([
                'id' => $k->id, 'type' => $k->type, 'host' => $k->device->host, 'url' => $k->url, 'port' => $k->port, 'query' => $k->query,
                'expect_status' => $k->expect_status, 'expect_text' => $k->expect_text, 'expect_answer' => $k->expect_answer,
                'verify_tls' => (bool) $k->verify_tls, 'timeout_s' => $k->timeout_s, 'interval_s' => $k->interval_s,
            ], fn ($v) => $v !== null))->values();
        $modbus = Device::where('collector_id', $c->id)->where('enabled', true)->whereNotNull('modbus_port')->get(['id', 'host', 'modbus_port', 'modbus_unit'])
            ->map(fn ($d) => ['device_id' => $d->id, 'host' => $d->host, 'port' => $d->modbus_port, 'unit' => $d->modbus_unit,
                'points' => \App\Models\ModbusPoint::where('device_id', $d->id)->where('enabled', true)->orderBy('position')->orderBy('id')->get()
                    ->map(fn ($p) => ['id' => $p->id, 'label' => $p->label, 'class' => $p->class, 'unit' => $p->unit, 'dtype' => $p->dtype, 'fn' => $p->fn,
                        'address' => $p->address, 'word_swap' => $p->word_swap, 'scale' => $p->scale, 'offset' => $p->offset_value,
                        'warn_low' => $p->warn_low, 'warn_high' => $p->warn_high, 'crit_low' => $p->crit_low, 'crit_high' => $p->crit_high])->values()])
            ->filter(fn ($m) => $m['points']->isNotEmpty())->values();

        $exporters = Device::where('collector_id', $c->id)->where('enabled', true)->whereNotNull('exporter_url')->get(['id', 'exporter_url', 'exporter_services'])
            ->map(fn ($d) => ['device_id' => $d->id, 'url' => $d->exporter_url,
                'services' => collect(explode(',', (string) $d->exporter_services))->map(fn ($s) => trim($s))->filter()->values()])->values();

        return response()->json(['collector' => ['id' => $c->id, 'name' => $c->name], 'devices' => $devices, 'checks' => $checks, 'modbus' => $modbus,
            'exporters' => $exporters]);
    }

    public function results(Request $request, Collector $collector): JsonResponse
    {
        $c = $request->attributes->get('site_collector');
        $body = $request->getContent();
        if ($body === '' || strlen($body) > 16 * 1024 * 1024) {
            return response()->json(['error' => 'kiriman kosong atau terlalu besar'], $body === '' ? 400 : 413);
        }
        try {
            [$status, $json] = $collector->siteIngest((int) $c->id, $body, [
                'Content-Encoding' => (string) $request->header('Content-Encoding', ''),
                'X-Antareja-Sent-At' => (string) $request->header('X-Antareja-Sent-At', ''),
                'X-Antareja-Queued' => (string) $request->header('X-Antareja-Queued', ''),
                'X-Antareja-Version' => mb_substr((string) $request->header('X-Antareja-Version', ''), 0, 32),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'collector pusat tidak bisa dihubungi, kirim ulang nanti'], 503);
        }

        return response()->json($json, $status);
    }
}
