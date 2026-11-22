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

        $urlTpl = route('devices.show', ['device' => '__ID__']); // dibuat sekali (route() per node mahal untuk ribuan perangkat)
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
                'url' => str_replace('__ID__', (string) $d->id, $urlTpl)];
            if (isset($positions[$d->id])) {
                [$node['x'], $node['y']] = $positions[$d->id];
            }
            $nodes[] = $node;
        }

        // Port dimuat SETELAH sambungan diketahui — hanya port yang menjadi ujung sambungan (bukan semua port semua perangkat)
        $ports = collect();
        $port = function ($dev, $idx) use (&$ports) {
            return $idx ? ($ports[$dev . ':' . $idx] ?? null) : null;
        };
        $snmp = $devices->pluck('snmp_enabled', 'id');

        $edges = [];
        $pairs = [];
        $byPair = []; // pairKey => indeks sambungan di $edges
        $addEdge = function (int $a, ?int $ai, int $b, ?int $bi, string $source, ?int $linkId = null, ?string $note = null) use (&$edges, &$pairs, &$byPair, $idSet) {
            if (! isset($idSet[$a], $idSet[$b])) {
                return;
            }
            // Arah tetap (id kecil dulu) agar LLDP dua arah tidak dobel
            if ($a > $b) {
                [$a, $ai, $b, $bi] = [$b, $bi, $a, $ai];
            }
            $key = "$a:" . ($ai ?? '?') . "-$b:" . ($bi ?? '?');
            $pairKey = "$a-$b";
            foreach ($byPair[$pairKey] ?? [] as $i) {
                $e = $edges[$i];
                // gabungkan sumber yang sama-sama menunjuk pasangan port ini (atau port tak diketahui di salah satu sisi)
                if ((($e['a_if'] ?? null) === $ai || $ai === null || ($e['a_if'] ?? null) === null)
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
            $byPair[$pairKey][] = count($edges);
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

        $unknown = DB::table('neighbors')->whereIn('device_id', $ids ?: [0])->whereNull('remote_device_id')
            ->limit(200)->get(['device_id', 'local_if_index', 'remote_name', 'remote_ip', 'remote_port', 'remote_platform', 'protocol']);
        $need = [];
        foreach ($edges as $e) {
            foreach ([[$e['from'], $e['a_if']], [$e['to'], $e['b_if']]] as [$d, $i]) {
                if ($i) {
                    $need[(int) $d][(int) $i] = true;
                }
            }
        }
        foreach ($unknown as $n) {
            if ($n->local_if_index) {
                $need[(int) $n->device_id][(int) $n->local_if_index] = true;
            }
        }
        $pairList = [];
        foreach ($need as $d => $idx) {
            foreach (array_keys($idx) as $i) {
                $pairList[] = [$d, $i];
            }
        }
        foreach (array_chunk($pairList, 5000) as $chunk) {
            $values = implode(',', array_fill(0, count($chunk), '(?::bigint, ?::int)'));
            $rows = DB::select("SELECT i.device_id, i.if_index, i.name, i.speed_bps, i.oper_up, i.util_pct, i.in_bps, i.out_bps
                FROM interfaces i JOIN (VALUES {$values}) AS v(d, x) ON i.device_id = v.d AND i.if_index = v.x", array_merge(...$chunk));
            foreach ($rows as $r) {
                $ports[$r->device_id . ':' . $r->if_index] = $r;
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
        $nodeIds = array_flip(array_map(fn ($n) => (string) $n['id'], $nodes));
        foreach ($unknown as $n) {
            $label = $n->remote_name ?: $n->remote_ip;
            if (! $label) {
                continue;
            }
            $nid = 'x:' . strtolower($label);
            if (! isset($nodeIds[$nid])) {
                $nodeIds[$nid] = true;
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
