<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\ModbusPoint;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Modbus TCP (hanya baca) per perangkat: aktif/nonaktif, titik baca, uji baca, salin titik. Engineer & admin. */
class ModbusController extends Controller
{
    public function edit(Request $request, Device $device)
    {
        $points = ModbusPoint::where('device_id', $device->id)->orderBy('position')->orderBy('id')->get();

        return view('modbus.edit', [
            'device' => $device,
            'points' => $points,
            'edit' => $request->integer('ubah') ? $points->firstWhere('id', $request->integer('ubah')) : null,
            'values' => DB::table('device_sensors')->where('device_id', $device->id)->where('key', 'like', 'mb:%')->get()->keyBy('key'),
            'sources' => Device::whereKeyNot($device->id)->whereIn('id', ModbusPoint::select('device_id'))->orderBy('name')->get(['id', 'name']),
            'test' => session('modbus_test'),
        ]);
    }

    public function settings(Request $request, Device $device)
    {
        $d = $request->validate(['modbus_port' => ['nullable', 'required_if:enabled,1', 'integer', 'between:1,65535'], 'modbus_unit' => ['required', 'integer', 'between:0,255']]);
        $on = $request->boolean('enabled');
        $old = [$device->modbus_port, $device->modbus_unit];
        $device->forceFill(['modbus_port' => $on ? (int) $d['modbus_port'] : null, 'modbus_unit' => (int) $d['modbus_unit'], 'modbus_error' => null])->save();
        if (! $on) {
            DB::table('device_sensors')->where('device_id', $device->id)->where('key', 'like', 'mb:%')->delete();
        }
        Audit::log('updated', "Modbus {$device->name} " . ($on ? "aktif (port {$device->modbus_port}, unit {$device->modbus_unit})" : 'dimatikan'), $device,
            ['modbus' => [$old, [$device->modbus_port, $device->modbus_unit]]]);

        return back()->with('ok', $on ? 'Modbus aktif. Dibaca tiap menit; klik Uji baca untuk mencoba sekarang.' : 'Modbus dimatikan, sensor Modbus perangkat ini dihapus.');
    }

    public function store(Request $request, Device $device)
    {
        $p = new ModbusPoint($this->validated($request));
        $p->device_id = $device->id;
        $p->position = (int) ModbusPoint::where('device_id', $device->id)->max('position') + 1;
        $p->save();
        Audit::log('created', "Titik Modbus \"{$p->label}\" ({$device->name}, register {$p->address}) ditambahkan", $device);

        return redirect()->route('modbus.edit', $device)->with('ok', "Titik {$p->label} ditambahkan.");
    }

    public function update(Request $request, Device $device, ModbusPoint $point)
    {
        abort_unless($point->device_id === $device->id, 404);
        $point->fill($this->validated($request));
        $changes = Audit::diff($point);
        $point->save();
        Audit::log('updated', "Titik Modbus \"{$point->label}\" ({$device->name}) diubah", $device, $changes);

        return redirect()->route('modbus.edit', $device)->with('ok', "Titik {$point->label} disimpan.");
    }

    public function destroy(Device $device, ModbusPoint $point)
    {
        abort_unless($point->device_id === $device->id, 404);
        DB::table('device_sensors')->where('device_id', $device->id)->where('key', $point->sensorKey())->delete();
        $point->delete();
        Audit::log('deleted', "Titik Modbus \"{$point->label}\" ({$device->name}) dihapus", $device);

        return redirect()->route('modbus.edit', $device)->with('ok', "Titik {$point->label} dihapus.");
    }

    public function copy(Request $request, Device $device)
    {
        $src = $request->validate(['source' => ['required', 'integer', 'exists:devices,id', Rule::notIn([$device->id])]])['source'];
        $pts = ModbusPoint::where('device_id', $src)->orderBy('position')->orderBy('id')->get();
        $pos = (int) ModbusPoint::where('device_id', $device->id)->max('position');
        DB::transaction(function () use ($pts, $device, &$pos) {
            foreach ($pts as $p) {
                $n = $p->replicate();
                $n->device_id = $device->id;
                $n->position = ++$pos;
                $n->save();
            }
        });
        $srcName = Device::find($src)->name;
        Audit::log('created', "{$pts->count()} titik Modbus disalin dari {$srcName} ke {$device->name}", $device);

        return back()->with('ok', "{$pts->count()} titik disalin dari {$srcName}. Periksa alamat dan unit ID bila model perangkatnya berbeda.");
    }

    public function test(Device $device, Collector $collector)
    {
        abort_unless($device->modbus_port, 422, 'Aktifkan Modbus dulu.');
        try {
            $r = $collector->modbusTest($device->id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Collector tidak dapat dihubungi: ' . mb_substr($e->getMessage(), 0, 200));
        }
        if (! ($r['ok'] ?? false)) {
            return back()->with('error', 'Uji baca gagal: ' . mb_substr((string) ($r['error'] ?? 'tidak diketahui'), 0, 200));
        }

        return back()->with('modbus_test', $r['readings'] ?? [])->with('ok', 'Uji baca selesai, hasilnya di bawah. Belum disimpan; putaran berikutnya (tiap menit) yang menyimpan.');
    }

    private function validated(Request $r): array
    {
        $num = ['nullable', 'numeric', 'between:-1000000000,1000000000'];
        $d = $r->validate([
            'label' => ['required', 'string', 'max:80'],
            'class' => ['required', Rule::in(array_keys(ModbusPoint::CLASSES))],
            'unit' => ['nullable', 'string', 'max:12'],
            'fn' => ['required', Rule::in([3, 4])],
            'address' => ['required', 'integer', 'between:0,65535'],
            'dtype' => ['required', Rule::in(array_keys(ModbusPoint::DTYPES))],
            'scale' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'offset_value' => ['nullable', 'numeric', 'between:-1000000,1000000'],
            'warn_low' => $num, 'warn_high' => $num, 'crit_low' => $num, 'crit_high' => $num,
        ], ['scale.not_in' => 'Skala tidak boleh 0.']);
        $d['word_swap'] = $r->boolean('word_swap');
        $d['enabled'] = $r->boolean('enabled');
        $d['unit'] = (string) ($d['unit'] ?? '');
        $d['offset_value'] = (float) ($d['offset_value'] ?? 0);
        if ($d['address'] + ($d['dtype'] === 'u16' || $d['dtype'] === 's16' ? 0 : 1) > 65535) {
            throw ValidationException::withMessages(['address' => 'Register 32-bit memakai dua alamat; alamat terlalu besar.']);
        }
        if (isset($d['warn_low'], $d['crit_low']) && $d['crit_low'] > $d['warn_low']) {
            throw ValidationException::withMessages(['crit_low' => 'Batas kritis bawah harus di bawah batas warning bawah.']);
        }
        if (isset($d['warn_high'], $d['crit_high']) && $d['crit_high'] < $d['warn_high']) {
            throw ValidationException::withMessages(['crit_high' => 'Batas kritis atas harus di atas batas warning atas.']);
        }

        return $d;
    }
}
