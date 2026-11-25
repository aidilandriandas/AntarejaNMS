<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** VLAN di semua switch (Q-BRIDGE-MIB / Cisco VTP) dan ringkasan STP. Hanya baca, semua peran. */
class VlanController extends Controller
{
    public const STP = [1 => 'disabled', 2 => 'blocking', 3 => 'listening', 4 => 'learning', 5 => 'forwarding', 6 => 'broken'];

    /** Nomor VLAN dari nama interface: "vlan20", "Vlan20", "vlan-20-cctv", "Gi0/0.20", "ether2.30". Null bila tidak ada. */
    public static function idFromName(?string $name): ?int
    {
        $n = (string) $name;
        if (preg_match('/vlan[\s_-]?(\d{1,4})(?!\d)/i', $n, $m) || preg_match('/\.(\d{1,4})$/', $n, $m)) {
            $id = (int) $m[1];

            return $id >= 1 && $id <= 4094 ? $id : null;
        }

        return null;
    }

    /** Interface VLAN di router/switch (tipe l2vlan 135, l3ipvlan 136, atau namanya berpola VLAN / subinterface). */
    public static function vlanInterfaces()
    {
        return DB::table('interfaces as i')->join('devices as d', 'd.id', '=', 'i.device_id')->where('d.enabled', true)
            ->where(fn ($q) => $q->whereIn('i.if_type', [135, 136])->orWhere('i.name', '~*', '^vlan[ _-]?[0-9]{1,4}')->orWhere('i.name', '~', '\.[0-9]{1,4}$'))
            ->orderBy('d.name')->orderBy('i.if_index')
            ->get(['i.device_id', 'i.if_index', 'i.name', 'i.alias', 'i.oper_up', 'i.admin_up', 'i.if_type', 'd.name as device'])
            ->map(function ($i) {
                $i->vlan_id = self::idFromName($i->name) ?? self::idFromName($i->alias);

                return $i;
            });
    }

    public function index(Request $request)
    {
        $rows = DB::table('device_vlans as v')->join('devices as d', 'd.id', '=', 'v.device_id')->where('d.enabled', true)
            ->when($request->integer('vlan'), fn ($q, $id) => $q->where('v.vlan_id', $id))
            ->orderBy('v.vlan_id')->orderBy('d.name')->get(['v.vlan_id', 'v.name', 'd.id as device_id', 'd.name as device']);
        $ifs = self::vlanInterfaces();
        $byId = $ifs->whereNotNull('vlan_id')->when($request->integer('vlan'), fn ($c, $id) => $c->where('vlan_id', $id))->groupBy('vlan_id');
        $vlans = $rows->groupBy('vlan_id')->union($byId->map(fn () => collect()))->map(function ($list, $id) use ($byId) {
            $names = $list->pluck('name')->filter()->countBy()->sortDesc();

            return ['id' => (int) $id, 'name' => $names->keys()->first() ?? ($byId[$id] ?? collect())->pluck('alias')->filter()->first(), 'mismatch' => $names->count() > 1,
                'names' => $names, 'devices' => $list, 'ifs' => $byId[$id] ?? collect()];
        })->sortKeys()->values();
        $stp = DB::table('devices as d')->leftJoin('interfaces as i', fn ($j) => $j->on('i.device_id', '=', 'd.id')->on('i.if_index', '=', 'd.stp_root_port'))
            ->where('d.enabled', true)->whereNotNull('d.stp_top_changes')->orderBy('d.stp_root')->orderBy('d.name')
            ->get(['d.id', 'd.name', 'd.stp_root', 'd.stp_root_port', 'd.stp_top_changes', 'd.stp_last_change_at', 'i.name as root_if']);

        // perangkat SNMP yang sudah terbaca interfacenya tetapi tanpa VLAN sama sekali: bantu menelusuri
        $withVlan = $rows->pluck('device_id')->merge($ifs->pluck('device_id'))->unique();
        $noVlan = DB::table('devices')->where('enabled', true)->where('snmp_enabled', true)->where('snmp_ok', true)
            ->whereIn('category', ['core', 'distribution', 'access', 'firewall'])->whereNotIn('id', $withVlan->all() ?: [0])->orderBy('name')->get(['id', 'name', 'category']);

        return view('vlans.index', ['vlans' => $vlans, 'stp' => $stp, 'roots' => $stp->pluck('stp_root')->filter()->unique()->count(),
            'unnumbered' => $ifs->whereNull('vlan_id')->values(), 'noVlan' => $noVlan]);
    }
}
