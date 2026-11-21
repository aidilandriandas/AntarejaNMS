<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Menyusun graf topologi: node = perangkat, garis = tetangga LLDP/CDP (otomatis), sambungan manual,
 * dan hubungan perangkat induk (garis putus-putus bila tidak ada sambungan fisik yang diketahui).
 * Tetangga LLDP/CDP yang belum terdaftar ditampilkan sebagai node abu-abu agar mudah ditambahkan.
 */
class Topology
{
    public function graph(?int $siteId = null): array
    {
        $devices = Device::query()->where('enabled', true)->with('site:id,code')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->get(['id', 'name', 'host', 'site_id', 'category', 'status', 'parent_id', 'last_rtt_ms', 'snmp_enabled', 'status_reason']);
        $ids = $devices->pluck('id')->all();
        $idSet = array_flip($ids);
        $positions = (array) Setting::get('topology.positions', []);

        $problemDevices = DB::table('problems')->whereNull('resolved_at')->whereNull('suppressed')->whereIn('device_id', $ids ?: [0])
            ->pluck('device_id')->flip();
        $maint = DB::table('problems')->whereNull('resolved_at')->where('suppressed', 'maintenance')->whereIn('device_id', $ids ?: [0])
            ->pluck('device_id')->flip();

        $nodes = [];
        foreach ($devices as $d) {
            $state = $d->status === 'down' ? 'down' : ($d->status === 'unknown' ? 'unknown' : 'up');
            if ($state === 'up' && isset($problemDevices[$d->id])) {
                $state = 'problem';
            }
            if (isset($maint[$d->id])) {
                $state = 'maintenance';
            }
            $node = ['id' => $d->id, 'label' => $d->name, 'host' => $d->host, 'site' => $d->site?->code, 'category' => $d->category,
                'state' => $state, 'rtt' => $d->last_rtt_ms, 'reason' => $d->status === 'down' ? $d->status_reason : null,
                'url' => route('devices.show', $d->id)];
            if (isset($positions[$d->id])) {
                [$node['x'], $node['y']] = $positions[$d->id];
            }
            $nodes[] = $node;
        }

        $ports = DB::table('interfaces')->whereIn('device_id', $ids ?: [0])
            ->get(['device_id', 'if_index', 'name', 'speed_bps', 'oper_up', 'util_pct', 'in_bps', 'out_bps'])
            ->keyBy(fn ($p) => $p->device_id . ':' . $p->if_index);
        $port = fn ($dev, $idx) => $idx ? ($ports[$dev . ':' . $idx] ?? null) : null;
        $snmp = $devices->pluck('snmp_enabled', 'id');

        $edges = [];
        $pairs = [];
        $addEdge = function (int $a, ?int $ai, int $b, ?int $bi, string $source, ?int $linkId = null, ?string $note = null) use (&$edges, &$pairs, $port, $snmp, $idSet) {
            if (! isset($idSet[$a], $idSet[$b])) {
                return;
            }
            // Arah tetap (id kecil dulu) agar LLDP dua arah tidak dobel
            if ($a > $b) {
                [$a, $ai, $b, $bi] = [$b, $bi, $a, $ai];
            }
            $key = "$a:" . ($ai ?? '?') . "-$b:" . ($bi ?? '?');
            $pairKey = "$a-$b";
            foreach ($edges as $i => $e) {
                // gabungkan sumber yang sama-sama menunjuk pasangan port ini (atau port tak diketahui di salah satu sisi)
                if ($e['pair'] === $pairKey && (($e['a_if'] ?? null) === $ai || $ai === null || ($e['a_if'] ?? null) === null)
                    && (($e['b_if'] ?? null) === $bi || $bi === null || ($e['b_if'] ?? null) === null)) {
                    $edges[$i]['a_if'] ??= $ai;
                    $edges[$i]['b_if'] ??= $bi;
                    $edges[$i]['sources'][] = $source;
                    $edges[$i]['sources'] = array_values(array_unique($edges[$i]['sources']));
                    $edges[$i]['link_id'] ??= $linkId;

                    return;
                }
            }
            $pairs[$pairKey] = true;
            $edges[] = ['id' => $key, 'pair' => $pairKey, 'from' => $a, 'to' => $b, 'a_if' => $ai, 'b_if' => $bi, 'sources' => [$source], 'link_id' => $linkId, 'note' => $note];
        };

        foreach (DB::table('neighbors')->whereIn('device_id', $ids ?: [0])->whereNotNull('remote_device_id')->get() as $n) {
            $addEdge($n->device_id, $n->local_if_index, $n->remote_device_id, $n->remote_if_index, $n->protocol);
        }
        foreach (DB::table('device_links')->get() as $l) {
            $addEdge($l->a_device_id, $l->a_if_index, $l->b_device_id, $l->b_if_index, 'manual', $l->id, $l->note);
        }
        foreach ($devices as $d) {
            if ($d->parent_id && ! isset($pairs[min($d->id, $d->parent_id) . '-' . max($d->id, $d->parent_id)])) {
                $addEdge($d->id, null, $d->parent_id, null, 'induk');
            }
        }

        $state = $devices->pluck('status', 'id');
        foreach ($edges as &$e) {
            // Sisi yang dipakai untuk membaca trafik: sisi ber-SNMP yang port-nya diketahui
            $side = null;
            foreach ([['from', 'a_if'], ['to', 'b_if']] as [$dk, $ik]) {
                if ($e[$ik] && ($snmp[$e[$dk]] ?? false) && $port($e[$dk], $e[$ik])) {
                    $side = [$e[$dk], $e[$ik]];
                    break;
                }
            }
            $pa = $port($e['from'], $e['a_if']);
            $pb = $port($e['to'], $e['b_if']);
            $sp = $side ? $port($side[0], $side[1]) : null;
            $e['label_a'] = $pa?->name;
            $e['label_b'] = $pb?->name;
            $e['traffic'] = $side ? ['device_id' => $side[0], 'if_index' => $side[1], 'speed' => (int) ($sp->speed_bps ?? 0),
                'in' => $sp->in_bps, 'out' => $sp->out_bps, 'util' => $sp->util_pct] : null;
            $e['down'] = ($state[$e['from']] ?? '') === 'down' || ($state[$e['to']] ?? '') === 'down'
                || ($pa && $pa->oper_up === false) || ($pb && $pb->oper_up === false);
            $e['logical'] = $e['sources'] === ['induk'];
            unset($e['pair']);
        }
        unset($e);

        // Tetangga yang belum terdaftar → node abu-abu
        $unknown = DB::table('neighbors')->whereIn('device_id', $ids ?: [0])->whereNull('remote_device_id')
            ->limit(200)->get(['device_id', 'local_if_index', 'remote_name', 'remote_ip', 'remote_port', 'remote_platform', 'protocol']);
        foreach ($unknown as $n) {
            $label = $n->remote_name ?: $n->remote_ip;
            if (! $label) {
                continue;
            }
            $nid = 'x:' . strtolower($label);
            if (! collect($nodes)->contains('id', $nid)) {
                $nodes[] = ['id' => $nid, 'label' => $label, 'host' => $n->remote_ip, 'state' => 'external', 'category' => 'other',
                    'platform' => $n->remote_platform, 'url' => null];
            }
            $pa = $port($n->device_id, $n->local_if_index);
            $edges[] = ['id' => "x{$n->device_id}:{$n->local_if_index}-{$nid}", 'from' => $n->device_id, 'to' => $nid, 'a_if' => $n->local_if_index,
                'b_if' => null, 'sources' => [$n->protocol], 'label_a' => $pa?->name, 'label_b' => $n->remote_port, 'traffic' => null,
                'down' => false, 'logical' => false, 'external' => true, 'link_id' => null, 'note' => null];
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => [
                'devices' => $devices->count(),
                'links' => collect($edges)->where('logical', false)->where('external', '!=', true)->count(),
                'down' => $devices->where('status', 'down')->count(),
                'unknown_neighbors' => collect($nodes)->where('state', 'external')->count(),
            ],
        ];
    }
}
