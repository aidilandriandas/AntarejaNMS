<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\ConfigTransfer;
use Illuminate\Http\Request;
use RuntimeException;

/** Ekspor/impor konfigurasi ANTAREJA (khusus admin). */
class ConfigTransferController extends Controller
{
    public function index(Request $request)
    {
        return view('transfer.index', ['plan' => session('cfg_plan'), 'file' => session('cfg_name')]);
    }

    public function export(Request $request, ConfigTransfer $t)
    {
        $with = $request->boolean('with_secrets');
        $request->validate($with ? ['passphrase' => ['required', 'string', 'min:12', 'max:200', 'confirmed']] : [],
            ['passphrase.min' => 'Frasa sandi minimal 12 karakter.', 'passphrase.confirmed' => 'Konfirmasi frasa sandi tidak sama.'], ['passphrase' => 'frasa sandi']);
        $data = $t->export($with, $with ? $request->input('passphrase') : null);
        Audit::log('download', 'Konfigurasi diekspor (' . count($data['devices']) . ' perangkat' . ($with ? ', rahasia terenkripsi frasa sandi' : ', tanpa rahasia') . ')');
        $name = 'antareja-konfigurasi-' . now()->format('Ymd-Hi') . ($with ? '-rahasia' : '') . '.json';

        return response()->streamDownload(fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $name,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function preview(Request $request, ConfigTransfer $t)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.max' => 'Berkas maksimal 5 MB.'], ['file' => 'berkas']);
        try {
            $raw = file_get_contents($request->file('file')->getRealPath());
            $plan = $t->plan($t->parse($raw));
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        $request->session()->put('cfg_import', $raw);
        $request->session()->put('cfg_name', mb_substr($request->file('file')->getClientOriginalName(), 0, 120));

        return redirect()->route('transfer.index')->with('cfg_plan', $plan)->with('cfg_name', session('cfg_name'));
    }

    public function apply(Request $request, ConfigTransfer $t)
    {
        $raw = $request->session()->get('cfg_import');
        if (! $raw) {
            return redirect()->route('transfer.index')->withErrors(['file' => 'Pratinjau kedaluwarsa — unggah ulang berkasnya.']);
        }
        try {
            $data = $t->parse($raw);
            $pass = isset($data['secrets']) ? (string) $request->input('passphrase') : null;
            if (isset($data['secrets']) && $pass === '' && ! $request->boolean('skip_secrets')) {
                return redirect()->route('transfer.index')->with('cfg_plan', $t->plan($data))->with('cfg_name', session('cfg_name'))
                    ->withErrors(['passphrase' => 'Isi frasa sandi, atau centang "impor tanpa rahasia".']);
            }
            $res = $t->apply($data, $request->boolean('skip_secrets') ? null : $pass);
        } catch (RuntimeException $e) {
            return redirect()->route('transfer.index')->with('cfg_plan', $t->plan($t->parse($raw)))->with('cfg_name', session('cfg_name'))
                ->withErrors(['passphrase' => $e->getMessage()]);
        }
        $request->session()->forget(['cfg_import', 'cfg_name']);
        $sum = collect($res)->except('skipped')->filter()->map(fn ($n, $k) => "$k $n")->implode(', ');
        Audit::log('created', "Konfigurasi diimpor: {$sum}" . ($res['skipped'] ? ", {$res['skipped']} dilewati" : ''));

        return redirect()->route('transfer.index')->with('ok', "Impor selesai: {$sum}" . ($res['skipped'] ? " · {$res['skipped']} baris dilewati (tidak valid)" : '') . '.');
    }
}
