<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** VLAN di semua switch (Q-BRIDGE-MIB / Cisco VTP) dan ringkasan STP. Hanya baca, semua peran. */
class VlanController extends Controller
{
    public const STP = [1 => 'disabled', 2 => 'blocking', 3 => 'listening', 4 => 'learning', 5 => 'forwarding', 6 => 'broken'];

    public function index(Request $request)
    {
        $rows = DB::table('device_vlans as v')->join('devices as d', 'd.id', '=', 'v.device_id')->where('d.enabled', true)
            ->when($request->integer('vlan'), fn ($q, $id) => $q->where('v.vlan_id', $id))
            ->orderBy('v.vlan_id')->orderBy('d.name')->get(['v.vlan_id', 'v.name', 'd.id as device_id', 'd.name as device']);
        $vlans = $rows->groupBy('vlan_id')->map(function ($list, $id) {
            $names = $list->pluck('name')->filter()->countBy()->sortDesc();

            return ['id' => (int) $id, 'name' => $names->keys()->first(), 'mismatch' => $names->count() > 1,
                'names' => $names, 'devices' => $list];
        })->values();
        $stp = DB::table('devices as d')->leftJoin('interfaces as i', fn ($j) => $j->on('i.device_id', '=', 'd.id')->on('i.if_index', '=', 'd.stp_root_port'))
            ->where('d.enabled', true)->whereNotNull('d.stp_top_changes')->orderBy('d.stp_root')->orderBy('d.name')
            ->get(['d.id', 'd.name', 'd.stp_root', 'd.stp_root_port', 'd.stp_top_changes', 'd.stp_last_change_at', 'i.name as root_if']);

        return view('vlans.index', ['vlans' => $vlans, 'stp' => $stp, 'roots' => $stp->pluck('stp_root')->filter()->unique()->count()]);
    }
}
