<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Peta dampak: bila perangkat tertentu mati, apa saja yang ikut tidak terjangkau atau terganggu.
 *
 * Dasarnya relasi perangkat induk (uplink) yang diisi engineer: semua perangkat di bawahnya ikut tidak terjangkau.
 * Dari himpunan itu dihitung kamera yang terkait perangkatnya, layanan yang jatuh di bawah syarat minimal hidup
 * komponennya, dan site yang seluruh perangkatnya ikut putus. Hanya perangkat aktif yang dihitung.
 * Peta topologi LLDP tidak dipakai karena arahnya (mana yang uplink) tidak diketahui.
 */
class Impact
{
    /** @var array<int, object> id => perangkat aktif */
    private array $devices = [];

    /** @var array<int, int[]> induk => anak */
    private array $children = [];

    private ?Collection $services = null;

    private ?Collection $cameras = null;

    public function __construct()
    {
        $rows = DB::table('devices as d')->leftJoin('sites as s', 's.id', '=', 'd.site_id')->where('d.enabled', true)
            ->get(['d.id', 'd.name', 'd.host', 'd.category', 'd.status', 'd.parent_id', 'd.site_id', 's.code as site_code', 's.name as site_name']);
        foreach ($rows as $r) {
            $this->devices[$r->id] = $r;
        }
        foreach ($this->devices as $d) {
            if ($d->parent_id && isset($this->devices[$d->parent_id])) {
                $this->children[$d->parent_id][] = $d->id;
            }
        }
    }

    public function device(int $id): ?object
    {
        return $this->devices[$id] ?? null;
    }

    /** Semua perangkat di bawah $ids (tidak termasuk $ids sendiri). Aman dari rantai melingkar. */
    public function descendants(array $ids): array
    {
        $seen = array_fill_keys($ids, true);
        $out = [];
        $queue = $ids;
        while ($queue) {
            $id = array_shift($queue);
            foreach ($this->children[$id] ?? [] as $c) {
                if (! isset($seen[$c])) {
                    $seen[$c] = true;
                    $out[] = $c;
                    $queue[] = $c;
                }
            }
        }

        return $out;
    }

    /**
     * Dampak bila perangkat $roots mati.
     *
     * @return array{roots: object[], devices: object[], categories: array<string,int>, cameras: Collection,
     *               services: array<int, array{service: Service, before: string, after: string}>, sites: array<int, array>}
     */
    public function of(array $roots): array
    {
        $roots = array_values(array_filter(array_map('intval', $roots), fn ($id) => isset($this->devices[$id])));
        $below = $this->descendants($roots);
        $out = array_fill_keys(array_merge($roots, $below), true);

        $devices = array_map(fn ($id) => $this->devices[$id], $below);
        usort($devices, fn ($a, $b) => [$a->site_code ?? '~', $a->name] <=> [$b->site_code ?? '~', $b->name]);
        $categories = [];
        foreach ($devices as $d) {
            $categories[$d->category] = ($categories[$d->category] ?? 0) + 1;
        }
        arsort($categories);

        $cameras = $this->cameras()->filter(fn ($c) => $c->device_id && isset($out[$c->device_id]))->values();

        // Layanan: status sekarang dibanding bila perangkat terdampak ikut down
        $services = [];
        foreach ($this->services() as $sv) {
            [$before] = ServiceSla::liveStatus($sv);
            [$after] = self::serviceState($sv, $out);
            $touches = $sv->components->contains(fn ($c) => $c->devices->contains(fn ($d) => isset($out[$d->id])));
            if ($touches && $after !== $before) {
                $services[] = ['service' => $sv, 'before' => $before, 'after' => $after];
            }
        }
        usort($services, fn ($a, $b) => [$a['after'] !== 'down', $a['service']->name] <=> [$b['after'] !== 'down', $b['service']->name]);

        // Site: berapa perangkat site itu yang ikut putus
        $total = [];
        $hit = [];
        foreach ($this->devices as $d) {
            if ($d->site_id) {
                $total[$d->site_id] = ($total[$d->site_id] ?? 0) + 1;
                if (isset($out[$d->id])) {
                    $hit[$d->site_id] = ($hit[$d->site_id] ?? 0) + 1;
                }
            }
        }
        $sites = [];
        foreach ($hit as $sid => $n) {
            $any = collect($this->devices)->firstWhere('site_id', $sid);
            $sites[] = ['id' => $sid, 'code' => $any->site_code, 'name' => $any->site_name, 'hit' => $n, 'total' => $total[$sid], 'all' => $n === $total[$sid]];
        }
        usort($sites, fn ($a, $b) => [! $a['all'], -$a['hit']] <=> [! $b['all'], -$b['hit']]);

        return [
            'roots' => array_map(fn ($id) => $this->devices[$id], $roots),
            'devices' => $devices, 'categories' => $categories, 'cameras' => $cameras, 'services' => $services, 'sites' => $sites,
        ];
    }

    /** Ringkasan satu baris, mis. "12 perangkat, 4 kamera, 1 layanan terganggu". Kosong bila tidak berdampak. */
    public static function line(array $r): string
    {
        $parts = [];
        if ($n = count($r['devices'])) {
            $parts[] = "{$n} perangkat";
        }
        if ($n = $r['cameras']->count()) {
            $parts[] = "{$n} kamera";
        }
        $down = count(array_filter($r['services'], fn ($s) => $s['after'] === 'down'));
        $deg = count($r['services']) - $down;
        if ($down) {
            $parts[] = "{$down} layanan terganggu";
        }
        if ($deg) {
            $parts[] = "{$deg} layanan tanpa cadangan";
        }
        if ($n = count(array_filter($r['sites'], fn ($s) => $s['all']))) {
            $parts[] = "{$n} site putus total";
        }

        return implode(', ', $parts);
    }

    /**
     * Perangkat down sekarang yang menjadi sumber gangguan: tidak ada induknya (sampai ke atas) yang juga down.
     *
     * @return int[]
     */
    public function downRoots(): array
    {
        $roots = [];
        foreach ($this->devices as $d) {
            if ($d->status !== 'down') {
                continue;
            }
            $p = $d->parent_id;
            $covered = false;
            for ($i = 0; $p && $i < 32; $i++) {
                if (($this->devices[$p]->status ?? null) === 'down') {
                    $covered = true;
                    break;
                }
                $p = $this->devices[$p]->parent_id ?? null;
            }
            if (! $covered) {
                $roots[] = $d->id;
            }
        }

        return $roots;
    }

    /** Perangkat yang punya sesuatu di bawahnya atau terkait kamera/layanan (pilihan simulasi). */
    public function candidates(): array
    {
        $cam = array_flip($this->cameras()->pluck('device_id')->filter()->all());
        $svc = [];
        foreach ($this->services() as $sv) {
            foreach ($sv->components as $c) {
                foreach ($c->devices as $d) {
                    $svc[$d->id] = true;
                }
            }
        }
        $out = array_filter($this->devices, fn ($d) => isset($this->children[$d->id]) || isset($cam[$d->id]) || isset($svc[$d->id]));
        usort($out, fn ($a, $b) => strcmp($a->name, $b->name));

        return $out;
    }

    /** Status layanan bila perangkat $out dianggap down (ditambah yang memang down sekarang). */
    private static function serviceState(Service $sv, array $out): array
    {
        $state = $sv->components->isEmpty() ? 'empty' : 'up';
        foreach ($sv->components as $c) {
            $n = $c->devices->count();
            $alive = $c->devices->filter(fn ($d) => $d->status !== 'down' && ! isset($out[$d->id]))->count();
            $st = $alive < $c->min_up ? 'down' : ($alive < $n ? 'degraded' : 'up');
            if ($st === 'down') {
                $state = 'down';
            } elseif ($st === 'degraded' && $state !== 'down') {
                $state = 'degraded';
            }
        }

        return [$state];
    }

    private function services(): Collection
    {
        return $this->services ??= Service::with('components.devices:id,name,status')->orderBy('name')->get();
    }

    private function cameras(): Collection
    {
        return $this->cameras ??= Camera::orderBy('name')->get(['id', 'name', 'device_id', 'site_id']);
    }
}
