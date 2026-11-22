<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor perangkat massal dari CSV (mis. ekspor dari sistem lama / Excel).
 * Kolom: nama;host;site;kategori;snmp;community;induk  — pemisah ; atau , ; baris judul opsional.
 * Dua langkah: pratinjau (validasi per baris) lalu simpan. Community disimpan terenkripsi.
 */
class DeviceImportController extends Controller
{
    private const COLS = ['nama', 'host', 'site', 'kategori', 'snmp', 'community', 'induk'];

    public function form()
    {
        return view('devices.import', ['rows' => null]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            echo "\xEF\xBB\xBF" . implode(';', self::COLS) . "\n";
            echo "Core Switch Pit A;10.10.1.1;PIT-A;core;2c;public-ro;\n";
            echo "Radio PTP Pit A-B;10.10.2.5;PIT-A;ptp;2c;public-ro;Core Switch Pit A\n";
            echo "NVR Workshop;10.10.5.20;WS;nvr;;;Core Switch Pit A\n";
        }, 'templat-impor-perangkat.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request)
    {
        $request->validate(['file' => ['nullable', 'file', 'max:2048'], 'text' => ['nullable', 'string', 'max:500000']]);
        $raw = $request->file('file') ? file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('text');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $raw), fn ($l) => trim($l) !== ''));
        abort_if(count($lines) > 2000, 422, 'Maksimal 2000 baris sekali impor.');
        if (! $lines) {
            return back()->with('error', 'Tidak ada baris untuk diimpor.');
        }
        $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        if (str_contains(strtolower($lines[0]), 'host')) {
            array_shift($lines); // baris judul
        }

        $existingHosts = Device::pluck('id', 'host')->all();
        $existingNames = Device::pluck('id', 'name')->mapWithKeys(fn ($v, $k) => [mb_strtolower($k) => $v])->all();
        $namesInFile = [];
        $rows = [];
        foreach ($lines as $i => $line) {
            $c = array_map('trim', str_getcsv($line, $sep));
            $r = array_combine(self::COLS, array_pad(array_slice($c, 0, 7), 7, ''));
            $r['line'] = $i + 1;
            $r['kategori'] = strtolower($r['kategori'] ?: 'other');
            $r['snmp'] = strtolower(ltrim($r['snmp'], 'vV'));
            $err = [];
            if ($r['nama'] === '') {
                $err[] = 'nama kosong';
            }
            if (! preg_match('/^[A-Za-z0-9.:_-]+$/', $r['host'])) {
                $err[] = 'host tidak valid';
            } elseif (isset($existingHosts[$r['host']])) {
                $err[] = 'host sudah terdaftar';
            }
            if (! isset(Device::CATEGORIES[$r['kategori']])) {
                $err[] = 'kategori tidak dikenal (pakai: ' . implode(', ', array_keys(Device::CATEGORIES)) . ')';
            }
            if ($r['snmp'] !== '' && ! in_array($r['snmp'], ['1', '2c'], true)) {
                $err[] = 'snmp harus 1 atau 2c (SNMPv3 diisi lewat form perangkat)';
            }
            if ($r['snmp'] !== '' && $r['community'] === '') {
                $err[] = 'community kosong';
            }
            if ($r['site'] !== '' && ! preg_match('/^[A-Za-z0-9._-]{1,32}$/', $r['site'])) {
                $err[] = 'kode site tidak valid';
            }
            if ($r['induk'] !== '' && ! isset($existingNames[mb_strtolower($r['induk'])]) && ! isset($namesInFile[mb_strtolower($r['induk'])])) {
                $err[] = 'induk tidak ditemukan (harus perangkat terdaftar atau baris sebelumnya)';
            }
            $namesInFile[mb_strtolower($r['nama'])] = true;
            $existingHosts[$r['host']] = true; // cegah host ganda di dalam berkas
            $r['errors'] = $err;
            $rows[] = $r;
        }
        $request->session()->put('device_import', array_values(array_filter($rows, fn ($r) => ! $r['errors'])));

        return view('devices.import', ['rows' => $rows]);
    }

    public function store(Request $request, Collector $collector)
    {
        $rows = $request->session()->pull('device_import', []);
        abort_if(! $rows, 422, 'Pratinjau kedaluwarsa — unggah ulang berkasnya.');
        $created = DB::transaction(function () use ($rows) {
            $ids = [];
            $byName = Device::pluck('id', 'name')->mapWithKeys(fn ($v, $k) => [mb_strtolower($k) => $v])->all();
            foreach ($rows as $r) {
                $siteId = null;
                if ($r['site'] !== '') {
                    $code = strtoupper($r['site']);
                    $siteId = Site::firstOrCreate(['code' => $code], ['name' => $code])->id;
                }
                $d = new Device(['name' => $r['nama'], 'host' => $r['host'], 'site_id' => $siteId, 'category' => $r['kategori'], 'enabled' => true,
                    'ping_enabled' => true, 'snmp_enabled' => $r['snmp'] !== '', 'snmp_version' => $r['snmp'] ?: '2c', 'snmp_port' => 161,
                    'parent_id' => $r['induk'] !== '' ? ($byName[mb_strtolower($r['induk'])] ?? null) : null]);
                $d->setSecret('snmp_community_enc', $r['community'] ?: null);
                $d->save();
                $byName[mb_strtolower($d->name)] = $d->id;
                $ids[] = $d->id;
            }

            return $ids;
        });
        Audit::log('created', count($created) . ' perangkat diimpor dari CSV');
        foreach (array_slice($created, 0, 50) as $id) {
            $collector->pollNow($id); // pemeriksaan pertama segera (sisanya di putaran berikutnya)
        }

        return redirect()->route('devices.index')->with('ok', count($created) . ' perangkat diimpor. Status muncul dalam 1–2 menit.');
    }
}
