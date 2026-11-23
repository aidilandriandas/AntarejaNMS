<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ubah banyak perangkat sekaligus dari daftar Perangkat. Hanya mengubah catatan di ANTAREJA (site, induk, kategori,
 * kredensial SNMP, status pantau), tidak pernah mengirim konfigurasi ke perangkat. Setiap perangkat dicatat di audit.
 */
class DeviceBulkController extends Controller
{
    public const ACTIONS = [
        'site' => 'Pindahkan ke site',
        'parent' => 'Atur perangkat induk',
        'category' => 'Ubah kategori',
        'snmp' => 'Atur kredensial SNMP',
        'enable' => 'Aktifkan pemantauan',
        'disable' => 'Nonaktifkan pemantauan',
        'delete' => 'Hapus',
    ];

    public function apply(Request $request)
    {
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'parent_id' => ['nullable', 'integer', 'exists:devices,id'],
            'category' => ['required_if:action,category', 'nullable', Rule::in(array_keys(Device::CATEGORIES))],
            'snmp_version' => ['required_if:action,snmp', 'nullable', Rule::in(['1', '2c', '3'])],
            'snmp_port' => ['nullable', 'integer', 'between:1,65535'],
            'snmp_community' => ['nullable', 'string', 'max:128'],
            'snmp_v3_user' => ['nullable', 'string', 'max:64'],
            'snmp_v3_level' => ['nullable', Rule::in(['noAuthNoPriv', 'authNoPriv', 'authPriv'])],
            'snmp_v3_auth_proto' => ['nullable', Rule::in(['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'])],
            'snmp_v3_auth_pass' => ['nullable', 'string', 'min:8', 'max:128'],
            'snmp_v3_priv_proto' => ['nullable', Rule::in(['DES', 'AES', 'AES192', 'AES256'])],
            'snmp_v3_priv_pass' => ['nullable', 'string', 'min:8', 'max:128'],
            'confirm' => ['nullable', 'string', 'max:16'],
        ], [
            'ids.required' => 'Centang dulu perangkat yang mau diubah.',
            'snmp_v3_auth_pass.min' => 'Password SNMPv3 minimal 8 karakter (aturan SNMPv3).',
            'snmp_v3_priv_pass.min' => 'Password SNMPv3 minimal 8 karakter (aturan SNMPv3).',
        ]);
        $action = $d['action'];
        $devices = Device::whereIn('id', $d['ids'])->orderBy('name')->get();
        abort_if($devices->isEmpty(), 404);

        if ($action === 'delete') {
            if (trim((string) ($d['confirm'] ?? '')) !== 'HAPUS') {
                throw ValidationException::withMessages(['confirm' => 'Ketik HAPUS untuk mengonfirmasi penghapusan ' . $devices->count() . ' perangkat.']);
            }
            DB::transaction(function () use ($devices) {
                foreach ($devices as $dev) {
                    $dev->delete();
                    Audit::log('deleted', "Perangkat {$dev->name} ({$dev->host}) dihapus lewat ubah massal", $dev);
                }
            });

            return redirect()->route('devices.index')->with('ok', $devices->count() . ' perangkat dihapus.');
        }

        if ($action === 'snmp') {
            $this->checkSnmp($d);
        }
        $changed = 0;
        $skipped = [];
        DB::transaction(function () use ($devices, $d, $action, &$changed, &$skipped) {
            foreach ($devices as $dev) {
                switch ($action) {
                    case 'site':
                        $dev->site_id = $d['site_id'] ?? null;
                        break;
                    case 'parent':
                        $p = isset($d['parent_id']) ? (int) $d['parent_id'] : null;
                        if ($p && ($p === $dev->id || DeviceController::wouldLoop($dev->id, $p))) {
                            $skipped[] = $dev->name;
                            continue 2;
                        }
                        $dev->parent_id = $p;
                        break;
                    case 'category':
                        $dev->category = $d['category'];
                        break;
                    case 'enable':
                    case 'disable':
                        $dev->enabled = $action === 'enable';
                        break;
                    case 'snmp':
                        $dev->fill(['snmp_enabled' => true, 'snmp_version' => $d['snmp_version'], 'snmp_port' => (int) ($d['snmp_port'] ?? 0) ?: 161]);
                        if ($d['snmp_version'] === '3') {
                            $dev->fill(['snmp_v3_user' => $d['snmp_v3_user'], 'snmp_v3_level' => $d['snmp_v3_level'] ?? 'authPriv',
                                'snmp_v3_auth_proto' => $d['snmp_v3_auth_proto'] ?? 'SHA', 'snmp_v3_priv_proto' => $d['snmp_v3_priv_proto'] ?? 'AES']);
                            $dev->setSecret('snmp_v3_auth_enc', $d['snmp_v3_auth_pass'] ?? null);
                            $dev->setSecret('snmp_v3_priv_enc', $d['snmp_v3_priv_pass'] ?? null);
                        } else {
                            $dev->setSecret('snmp_community_enc', $d['snmp_community'] ?? null);
                        }
                        if ($dev->isDirty()) {
                            $dev->snmp_ok = null; // kredensial baru: hasil SNMP lama tidak berlaku
                        }
                        break;
                }
                if (! $dev->isDirty()) {
                    continue;
                }
                $changes = Audit::diff($dev);
                $dev->save();
                Audit::log('updated', "Perangkat {$dev->name} diubah lewat ubah massal (" . self::ACTIONS[$action] . ')', $dev, $changes);
                $changed++;
            }
        });

        $msg = self::ACTIONS[$action] . ": {$changed} dari {$devices->count()} perangkat berubah.";
        if ($skipped) {
            $msg .= ' Dilewati karena akan membuat rantai induk melingkar: ' . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ', dan lainnya' : '') . '.';
        }
        if ($action === 'snmp' && $changed) {
            $msg .= ' Hasil SNMP muncul setelah putaran pemeriksaan berikutnya.';
        }

        return back()->with('ok', $msg);
    }

    /** Kredensial wajib lengkap, karena diterapkan ke perangkat yang belum tentu punya kredensial lama. */
    private function checkSnmp(array $d): void
    {
        if ($d['snmp_version'] !== '3') {
            if (($d['snmp_community'] ?? '') === '') {
                throw ValidationException::withMessages(['snmp_community' => 'Isi community SNMP.']);
            }

            return;
        }
        $level = $d['snmp_v3_level'] ?? 'authPriv';
        $err = [];
        if (($d['snmp_v3_user'] ?? '') === '') {
            $err['snmp_v3_user'] = 'User SNMPv3 wajib diisi.';
        }
        if ($level !== 'noAuthNoPriv' && ($d['snmp_v3_auth_pass'] ?? '') === '') {
            $err['snmp_v3_auth_pass'] = 'Password autentikasi wajib diisi.';
        }
        if ($level === 'authPriv' && ($d['snmp_v3_priv_pass'] ?? '') === '') {
            $err['snmp_v3_priv_pass'] = 'Password enkripsi wajib diisi.';
        }
        if ($err) {
            throw ValidationException::withMessages($err);
        }
    }
}
