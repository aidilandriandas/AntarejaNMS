<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\ServiceSla;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * API baca-saja /api/v1 (token Bearer, lihat ApiToken). Kolom keluaran ditulis satu per satu:
 * kredensial perangkat, kamera, dan kanal tidak pernah ikut. Daftar memakai kursor ?after_id=&limit= (maks. 500).
 */
class ApiController extends Controller
{
    private const MAX = 500;

    private static function ts($v): ?string
    {
        return $v ? Carbon::parse($v)->toIso8601String() : null;
    }

    /** Kursor: urut id naik, ambil limit+1 untuk tahu ada halaman berikutnya. */
    private function page(Request $r, Builder $q, string $idCol, callable $map): JsonResponse
    {
        $limit = max(1, min(self::MAX, (int) $r->query('limit', 100)));
        if ($r->filled('after_id')) {
            $q->where($idCol, '>', (int) $r->query('after_id'));
        }
        $rows = $q->orderBy($idCol)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json(['data' => $rows->map($map)->values(), 'next_after_id' => $more ? $rows->last()->id : null]);
    }

    private function since(Request $r): ?Carbon
    {
        try {
            return $r->filled('since') ? Carbon::parse((string) $r->query('since')) : null;
        } catch (\Throwable) {
            abort(response()->json(['error' => 'since harus tanggal ISO 8601, mis. 2026-11-01T00:00:00+07:00'], 422));
        }
    }

    public function index(): JsonResponse
    {
        return response()->json(['name' => 'ANTAREJA NMS API', 'version' => 1, 'app_version' => config('antareja.version'), 'time' => now()->toIso8601String(),
            'endpoints' => ['/api/v1/sites', '/api/v1/devices', '/api/v1/devices/{id}', '/api/v1/problems', '/api/v1/incidents', '/api/v1/events', '/api/v1/anomalies', '/api/v1/services', '/api/v1/downtime',
                '/api/v1/checks', '/api/v1/sensors', '/api/v1/macs']]);
    }

    public function sites(): JsonResponse
    {
        $rows = DB::table('sites as s')->leftJoin('devices as d', fn ($j) => $j->on('d.site_id', '=', 's.id')->where('d.enabled', true))
            ->groupBy('s.id', 's.code', 's.name')->orderBy('s.code')
            ->selectRaw("s.id, s.code, s.name, count(d.id) devices, count(d.id) filter (where d.status = 'up') up, count(d.id) filter (where d.status = 'down') down")->get();

        return response()->json(['data' => $rows->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name,
            'devices' => (int) $s->devices, 'up' => (int) $s->up, 'down' => (int) $s->down])]);
    }

    private function deviceQuery(): Builder
    {
        return DB::table('devices as d')->leftJoin('sites as s', 's.id', '=', 'd.site_id')
            ->select(['d.id', 'd.name', 'd.host', 'd.category', 'd.enabled', 'd.status', 'd.status_reason', 'd.status_changed_at', 'd.last_rtt_ms',
                'd.last_loss_pct', 'd.last_ping_at', 'd.snmp_enabled', 'd.snmp_ok', 'd.sys_name', 'd.sys_descr', 'd.parent_id', 'd.lat', 'd.lng', 's.code as site']);
    }

    private static function deviceRow($d): array
    {
        return ['id' => $d->id, 'name' => $d->name, 'host' => $d->host, 'site' => $d->site, 'category' => $d->category, 'enabled' => (bool) $d->enabled,
            'status' => $d->status, 'status_reason' => $d->status_reason, 'status_changed_at' => self::ts($d->status_changed_at),
            'rtt_ms' => $d->last_rtt_ms !== null ? round((float) $d->last_rtt_ms, 2) : null, 'loss_pct' => $d->last_loss_pct !== null ? (float) $d->last_loss_pct : null,
            'last_ping_at' => self::ts($d->last_ping_at), 'snmp' => $d->snmp_enabled ? ($d->snmp_ok === null ? 'unknown' : ($d->snmp_ok ? 'ok' : 'fail')) : 'off',
            'sys_name' => $d->sys_name, 'sys_descr' => $d->sys_descr, 'parent_id' => $d->parent_id,
            'lat' => $d->lat !== null ? (float) $d->lat : null, 'lng' => $d->lng !== null ? (float) $d->lng : null];
    }

    public function devices(Request $r): JsonResponse
    {
        $q = $this->deviceQuery()
            ->when($r->filled('status'), fn ($q) => $q->where('d.status', (string) $r->query('status')))
            ->when($r->filled('site'), fn ($q) => $q->where('s.code', strtoupper((string) $r->query('site'))))
            ->when($r->filled('category'), fn ($q) => $q->where('d.category', (string) $r->query('category')));

        return $this->page($r, $q, 'd.id', fn ($d) => self::deviceRow($d));
    }

    public function device(int $id): JsonResponse
    {
        $d = $this->deviceQuery()->where('d.id', $id)->first();
        if (! $d) {
            return response()->json(['error' => 'perangkat tidak ditemukan'], 404);
        }
        $ports = DB::table('interfaces')->where('device_id', $id)->orderBy('if_index')
            ->get(['if_index', 'name', 'alias', 'speed_bps', 'admin_up', 'oper_up', 'last_change_at', 'in_bps', 'out_bps', 'err_rate', 'discard_rate']);
        $problems = DB::table('problems')->where('device_id', $id)->whereNull('resolved_at')->orderBy('id')->get(['id', 'kind', 'severity', 'title', 'started_at', 'acked_at']);

        return response()->json(['data' => self::deviceRow($d) + [
            'ports' => $ports->map(fn ($p) => ['if_index' => $p->if_index, 'name' => $p->name, 'alias' => $p->alias, 'speed_bps' => $p->speed_bps !== null ? (int) $p->speed_bps : null,
                'admin_up' => $p->admin_up, 'oper_up' => $p->oper_up, 'last_change_at' => self::ts($p->last_change_at),
                'in_bps' => $p->in_bps !== null ? (float) $p->in_bps : null, 'out_bps' => $p->out_bps !== null ? (float) $p->out_bps : null,
                'errors_per_min' => $p->err_rate !== null ? (float) $p->err_rate : null, 'discards_per_min' => $p->discard_rate !== null ? (float) $p->discard_rate : null]),
            'open_problems' => $problems->map(fn ($p) => ['id' => $p->id, 'kind' => $p->kind, 'severity' => $p->severity, 'title' => $p->title,
                'started_at' => self::ts($p->started_at), 'acked' => $p->acked_at !== null]),
        ]]);
    }

    public function problems(Request $r): JsonResponse
    {
        $status = (string) $r->query('status', 'open');
        if (! in_array($status, ['open', 'resolved', 'all'], true)) {
            return response()->json(['error' => 'status: open, resolved, atau all'], 422);
        }
        $sevs = ['info', 'warning', 'average', 'high', 'disaster']; // ringan ke berat
        $min = (string) $r->query('min_severity', $sevs[0]);
        if (! in_array($min, $sevs, true)) {
            return response()->json(['error' => 'min_severity: ' . implode(', ', $sevs)], 422);
        }
        $since = $this->since($r);
        $q = DB::table('problems as p')->join('devices as d', 'd.id', '=', 'p.device_id')->leftJoin('sites as s', 's.id', '=', 'd.site_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.acked_by')
            ->select(['p.id', 'p.device_id', 'd.name as device', 's.code as site', 'p.kind', 'p.if_index', 'p.severity', 'p.title', 'p.detail', 'p.last_value',
                'p.started_at', 'p.resolved_at', 'p.acked_at', 'u.username as acked_by', 'p.suppressed', 'p.suppressed_note'])
            ->whereIn('p.severity', array_slice($sevs, array_search($min, $sevs, true)))
            ->when($status === 'open', fn ($q) => $q->whereNull('p.resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('p.resolved_at'))
            ->when($since, fn ($q) => $q->where('p.started_at', '>=', $since))
            ->when($r->filled('site'), fn ($q) => $q->where('s.code', strtoupper((string) $r->query('site'))));

        return $this->page($r, $q, 'p.id', fn ($p) => ['id' => $p->id, 'device_id' => $p->device_id, 'device' => $p->device, 'site' => $p->site,
            'kind' => $p->kind, 'if_index' => $p->if_index, 'severity' => $p->severity, 'title' => $p->title, 'detail' => $p->detail,
            'value' => $p->last_value !== null ? (float) $p->last_value : null, 'started_at' => self::ts($p->started_at), 'resolved_at' => self::ts($p->resolved_at),
            'acked_at' => self::ts($p->acked_at), 'acked_by' => $p->acked_by, 'suppressed' => $p->suppressed, 'suppressed_note' => $p->suppressed_note]);
    }

    public function incidents(Request $r): JsonResponse
    {
        $since = $this->since($r);
        $q = DB::table('incidents as i')->leftJoin('sites as s', 's.id', '=', 'i.site_id')->leftJoin('users as u', 'u.id', '=', 'i.owner_id')
            ->select(['i.id', 'i.title', 'i.severity', 'i.status', 's.code as site', 'u.name as owner', 'i.auto', 'i.cause', 'i.action_taken',
                'i.opened_at', 'i.handled_at', 'i.recovered_at', 'i.closed_at'])
            ->selectRaw('(SELECT array_to_json(array_agg(problem_id ORDER BY problem_id)) FROM incident_problems ip WHERE ip.incident_id = i.id) AS problem_ids')
            ->when($r->filled('status'), fn ($q) => $q->whereIn('i.status', explode(',', (string) $r->query('status'))))
            ->when($since, fn ($q) => $q->where('i.opened_at', '>=', $since));

        return $this->page($r, $q, 'i.id', fn ($i) => ['id' => $i->id, 'title' => $i->title, 'severity' => $i->severity, 'status' => $i->status,
            'site' => $i->site, 'owner' => $i->owner, 'auto' => (bool) $i->auto, 'cause' => $i->cause, 'action_taken' => $i->action_taken,
            'opened_at' => self::ts($i->opened_at), 'handled_at' => self::ts($i->handled_at), 'recovered_at' => self::ts($i->recovered_at),
            'closed_at' => self::ts($i->closed_at), 'problem_ids' => json_decode($i->problem_ids ?? '[]') ?? []]);
    }

    public function events(Request $r): JsonResponse
    {
        $since = $this->since($r) ?? now()->subDay();
        $q = DB::table('events as e')->join('devices as d', 'd.id', '=', 'e.device_id')
            ->select(['e.id', 'e.device_id', 'd.name as device', 'e.kind', 'e.message', 'e.duration_s', 'e.at'])->where('e.at', '>=', $since)
            ->when($r->filled('kind'), fn ($q) => $q->whereIn('e.kind', explode(',', (string) $r->query('kind'))));

        return $this->page($r, $q, 'e.id', fn ($e) => ['id' => $e->id, 'device_id' => $e->device_id, 'device' => $e->device, 'kind' => $e->kind,
            'message' => $e->message, 'duration_s' => $e->duration_s !== null ? (int) $e->duration_s : null, 'at' => self::ts($e->at)]);
    }

    public function anomalies(Request $r): JsonResponse
    {
        $q = DB::table('anomalies as a')->join('devices as d', 'd.id', '=', 'a.device_id')
            ->select(['a.id', 'a.device_id', 'd.name as device', 'a.metric', 'a.if_index', 'a.if_name', 'a.direction', 'a.value', 'a.baseline', 'a.score',
                'a.started_at', 'a.ended_at', 'a.dismissed_by'])
            ->when($r->query('status', 'open') === 'open', fn ($q) => $q->whereNull('a.ended_at'))
            ->when($since = $this->since($r), fn ($q) => $q->where('a.started_at', '>=', $since));

        return $this->page($r, $q, 'a.id', fn ($a) => ['id' => $a->id, 'device_id' => $a->device_id, 'device' => $a->device, 'metric' => $a->metric,
            'unit' => $a->metric === 'latency' ? 'ms' : 'bps', 'if_index' => $a->if_index, 'if_name' => $a->if_name, 'direction' => $a->direction,
            'value' => (float) $a->value, 'baseline' => (float) $a->baseline, 'score' => round((float) $a->score, 2),
            'started_at' => self::ts($a->started_at), 'ended_at' => self::ts($a->ended_at), 'dismissed' => $a->dismissed_by !== null]);
    }

    public function checks(Request $r): JsonResponse
    {
        $q = DB::table('service_checks as c')->join('devices as d', 'd.id', '=', 'c.device_id')
            ->select(['c.id', 'c.name', 'c.device_id', 'd.name as device', 'd.host', 'c.type', 'c.url', 'c.port', 'c.query', 'c.status', 'c.last_ms', 'c.last_error',
                'c.last_at', 'c.status_changed_at', 'c.cert_expires_at', 'c.severity', 'c.enabled', 'c.interval_s'])
            ->when(in_array($r->query('status'), ['up', 'slow', 'down', 'unknown'], true), fn ($q) => $q->where('c.status', $r->query('status')))
            ->when($r->integer('device_id'), fn ($q, $id) => $q->where('c.device_id', $id));

        return $this->page($r, $q, 'c.id', fn ($c) => ['id' => $c->id, 'name' => $c->name, 'device_id' => $c->device_id, 'device' => $c->device,
            'type' => $c->type, 'target' => match ($c->type) {
                // kredensial di URL (user:pass@) tidak pernah dikirim
                'http' => preg_replace('#^(https?://)[^/@\s]*@#i', '$1', (string) $c->url),
                'tcp' => $c->host . ':' . $c->port, default => $c->query . ' @ ' . $c->host . ($c->port ? ':' . $c->port : ''),
            },
            'status' => $c->status, 'enabled' => (bool) $c->enabled, 'severity' => $c->severity, 'interval_s' => $c->interval_s,
            'last_ms' => $c->last_ms !== null ? round((float) $c->last_ms, 1) : null, 'last_error' => $c->last_error, 'last_at' => self::ts($c->last_at),
            'status_changed_at' => self::ts($c->status_changed_at), 'cert_expires_at' => self::ts($c->cert_expires_at)]);
    }

    /** Sensor SNMP & Modbus (tanpa kursor, maks. 2000 baris; saring untuk data besar). */
    public function sensors(Request $r): JsonResponse
    {
        $q = DB::table('device_sensors as s')->join('devices as d', 'd.id', '=', 's.device_id')->where('d.enabled', true)
            ->select(['s.device_id', 'd.name as device', 's.key', 's.class', 's.grp', 's.label', 's.unit', 's.value', 's.text', 's.status', 's.updated_at'])
            ->when($r->integer('device_id'), fn ($q, $id) => $q->where('s.device_id', $id))
            ->when($r->query('class'), fn ($q, $c) => $q->where('s.class', (string) $c))
            ->when(in_array($r->query('status'), ['ok', 'warn', 'crit'], true), fn ($q) => $q->where('s.status', $r->query('status')))
            ->when($r->query('source') === 'modbus', fn ($q) => $q->where('s.key', 'like', 'mb:%'))
            ->when($r->query('source') === 'exporter', fn ($q) => $q->where('s.key', 'like', 'px:%'))
            ->when($r->query('source') === 'snmp', fn ($q) => $q->where('s.key', 'not like', 'mb:%')->where('s.key', 'not like', 'px:%'))
            ->orderBy('s.device_id')->orderBy('s.key')->limit(2000);

        return response()->json(['data' => $q->get()->map(fn ($s) => ['device_id' => $s->device_id, 'device' => $s->device, 'key' => $s->key,
            'source' => str_starts_with($s->key, 'mb:') ? 'modbus' : (str_starts_with($s->key, 'px:') ? 'exporter' : 'snmp'), 'class' => $s->class, 'group' => $s->grp, 'label' => $s->label, 'unit' => $s->unit,
            'value' => $s->value !== null ? (float) $s->value : null, 'text' => $s->text, 'status' => $s->status, 'updated_at' => self::ts($s->updated_at)])->values()]);
    }

    /** Lokasi MAC di port switch. q = MAC atau IP (boleh sebagian); tanpa q: 500 yang terakhir terlihat. */
    public function macs(Request $r): JsonResponse
    {
        $term = trim((string) $r->query('q'));
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $term));
        $isIp = (bool) preg_match('/^\d{1,3}(\.\d{1,3}){0,3}\.?$/', $term);
        $rows = DB::table('mac_locations as m')->join('devices as d', 'd.id', '=', 'm.device_id')
            ->leftJoin('interfaces as i', fn ($j) => $j->on('i.device_id', '=', 'm.device_id')->on('i.if_index', '=', 'm.if_index'))
            ->selectRaw('m.mac, m.device_id, d.name AS switch, m.if_index, i.name AS port, m.vlan, m.first_seen, m.last_seen,
                (SELECT host(a.ip) FROM arp_entries a WHERE a.mac = m.mac ORDER BY a.last_seen DESC LIMIT 1) AS ip')
            ->when($term !== '' && $isIp, fn ($q) => $q->whereExists(fn ($e) => $e->from('arp_entries as a')->whereColumn('a.mac', 'm.mac')->whereRaw('host(a.ip) LIKE ?', [$term . '%'])))
            ->when($term !== '' && ! $isIp && strlen($hex) >= 4, fn ($q) => $q->whereRaw("replace(m.mac, ':', '') LIKE ?", ['%' . $hex . '%']))
            ->when($r->integer('device_id'), fn ($q, $id) => $q->where('m.device_id', $id))
            ->orderByDesc('m.last_seen')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($m) => ['mac' => $m->mac, 'ip' => $m->ip, 'switch_id' => $m->device_id, 'switch' => $m->switch,
            'if_index' => $m->if_index, 'port' => $m->port, 'vlan' => $m->vlan ?: null, 'since' => self::ts($m->first_seen), 'last_seen' => self::ts($m->last_seen)])->values()]);
    }

    public function services(): JsonResponse
    {
        $services = Service::with(['site:id,code', 'components.devices:id,status,created_at'])->orderBy('name')->get();
        $sla = $services->isEmpty() ? [] : (new ServiceSla)->compute($services, now()->startOfMonth(), now()->startOfMonth()->addMonthNoOverflow());

        return response()->json(['data' => $services->map(function ($sv) use ($sla) {
            [$state, $comps] = ServiceSla::liveStatus($sv);
            $x = $sla[$sv->id] ?? [];

            return ['id' => $sv->id, 'name' => $sv->name, 'site' => $sv->site?->code, 'target_pct' => $sv->target(), 'state' => $state,
                'components' => $sv->components->map(fn ($c) => ['name' => $c->name, 'min_up' => $c->min_up, 'state' => $comps[$c->id] ?? null,
                    'device_ids' => $c->devices->pluck('id')->values()])->values(),
                'month' => ['availability_pct' => $x['availability'] ?? null, 'down_s' => (int) ($x['down'] ?? 0), 'outages' => $x['count'] ?? 0]];
        })->values()]);
    }

    /** Persen down per perangkat untuk satu bulan (?month=YYYY-MM, bawaan bulan berjalan). */
    public function downtime(Request $r): JsonResponse
    {
        $month = (string) $r->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return response()->json(['error' => 'month harus YYYY-MM'], 422);
        }
        $rows = DB::table('downtime_monthly')->where('month', $month . '-01')->orderBy('device_name')
            ->get(['device_id', 'device_name', 'host', 'site', 'monitored_s', 'down_s', 'outages', 'final']);

        return response()->json(['month' => $month, 'data' => $rows->map(fn ($x) => ['device_id' => $x->device_id, 'device' => $x->device_name,
            'host' => $x->host, 'site' => $x->site, 'monitored_s' => (int) round($x->monitored_s), 'down_s' => (int) round($x->down_s),
            'down_pct' => $x->monitored_s > 0 ? round($x->down_s / $x->monitored_s * 100, 4) : null, 'outages' => $x->outages, 'final' => (bool) $x->final])->values()]);
    }
}
