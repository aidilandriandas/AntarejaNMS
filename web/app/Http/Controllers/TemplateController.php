<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceTemplate;
use App\Models\Problem;
use App\Models\ServiceCheck;
use App\Models\TemplateCheck;
use App\Services\Audit;
use App\Services\TemplateSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Templat perangkat: ambang, makro, exporter, cek layanan, trigger untuk banyak perangkat sekaligus. Engineer & admin. */
class TemplateController extends Controller
{
    public function index()
    {
        return view('templates.index', ['templates' => DeviceTemplate::withCount(['devices', 'checks'])->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('templates.form', ['t' => new DeviceTemplate(['thresholds' => [], 'macros' => []]), 'devices' => collect()]);
    }

    public function store(Request $request)
    {
        $t = DeviceTemplate::create($this->validated($request));
        Audit::log('created', "Templat \"{$t->name}\" dibuat", $t);

        return redirect()->route('templates.edit', $t)->with('ok', "Templat {$t->name} dibuat. Tambahkan cek layanan bila perlu, lalu pasang ke perangkat lewat Ubah perangkat atau Ubah massal.");
    }

    /** Kelas sensor OID khusus: kunci => [label, satuan bawaan]. Kelas berambang ikut ambang bawaan/khusus. */
    public const OID_CLASSES = [
        'signal' => ['Sinyal radio', 'dBm'], 'ccq' => ['CCQ', '%'], 'quality' => ['Kualitas link', '%'], 'capacity' => ['Kapasitas link', '%'],
        'rate' => ['Rate / throughput', 'Mbps'], 'noise' => ['Noise floor', 'dBm'], 'distance' => ['Jarak', 'km'], 'temperature' => ['Suhu', '°C'],
        'cpu' => ['CPU', '%'], 'memory' => ['Memori', '%'], 'voltage' => ['Tegangan', 'V'], 'power' => ['Daya', 'W'], 'battery' => ['Baterai', '%'],
        'other' => ['Lainnya (tanpa ambang)', ''],
    ];

    public const OID_GROUPS = ['radio' => 'Radio', 'sistem' => 'Sistem', 'daya' => 'Daya', 'lingkungan' => 'Lingkungan'];

    public function storeOid(Request $request, DeviceTemplate $template)
    {
        $d = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'oid' => ['required', 'string', 'max:200', 'regex:/^\.?[0-9]+(\.[0-9]+)+$/'],
            'mode' => ['required', 'in:get,walk'],
            'class' => ['required', \Illuminate\Validation\Rule::in(array_keys(self::OID_CLASSES))],
            'unit' => ['nullable', 'string', 'max:16'],
            'scale' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'grp' => ['required', \Illuminate\Validation\Rule::in(array_keys(self::OID_GROUPS))],
        ], ['oid.regex' => 'OID berupa angka bertitik, mis. .1.3.6.1.4.1.17713.21.1.2.3.0']);
        $d['oid'] = '.' . ltrim($d['oid'], '.');
        $d['unit'] = $d['unit'] ?? self::OID_CLASSES[$d['class']][1];
        DB::table('template_oids')->insert($d + ['template_id' => $template->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('created', "Sensor OID \"{$d['label']}\" ({$d['oid']}) ditambahkan ke templat {$template->name}", $template);

        return redirect()->to(route('templates.edit', $template) . '#oid')->with('ok', "Sensor {$d['label']} ditambahkan. Nilainya muncul di halaman perangkat bertemplat ini setelah satu putaran SNMP.");
    }

    public function destroyOid(DeviceTemplate $template, int $toid)
    {
        $o = DB::table('template_oids')->where('id', $toid)->where('template_id', $template->id)->first();
        abort_unless($o, 404);
        DB::table('template_oids')->where('id', $toid)->delete();
        DB::table('device_sensors')->whereIn('device_id', $template->devices()->pluck('id'))->where(fn ($q) => $q->where('key', "ox:{$toid}")->orWhere('key', 'like', "ox:{$toid}:%"))->delete();
        Audit::log('deleted', "Sensor OID \"{$o->label}\" dihapus dari templat {$template->name}", $template);

        return redirect()->to(route('templates.edit', $template) . '#oid')->with('ok', "Sensor {$o->label} dihapus.");
    }

    public function edit(DeviceTemplate $template)
    {
        return view('templates.form', ['t' => $template->load('checks'), 'oids' => DB::table('template_oids')->where('template_id', $template->id)->orderBy('id')->get(), 'devices' => $template->devices()->with('site')->orderBy('name')->get(),
            'editCheck' => request()->integer('cek') ? $template->checks->firstWhere('id', request()->integer('cek')) : null,
            'triggers' => \App\Models\Trigger::where('scope', 'template')->where('template_id', $template->id)->orderBy('name')->get()]);
    }

    public function update(Request $request, DeviceTemplate $template)
    {
        $template->fill($this->validated($request, $template));
        $changes = Audit::diff($template);
        $template->save();
        Audit::log('updated', "Templat \"{$template->name}\" diubah", $template, $changes);
        $warn = TemplateSync::applyTemplate($template);

        return $this->done($template, "Templat {$template->name} disimpan dan diterapkan ke {$template->devices()->count()} perangkat.", $warn);
    }

    public function destroy(DeviceTemplate $template)
    {
        $ids = $template->devices()->pluck('id');
        $template->delete(); // cek layanan dari templat ikut terhapus (FK), perangkat lepas dari templat
        foreach (Device::whereIn('id', $ids)->get() as $d) {
            TemplateSync::apply($d);
        }
        Audit::log('deleted', "Templat \"{$template->name}\" dihapus ({$ids->count()} perangkat dilepas)", $template);

        return redirect()->route('templates.index')->with('ok', "Templat {$template->name} dihapus. Ambang, cek layanan, dan exporter dari templat dilepas dari {$ids->count()} perangkat; ambang perangkat sendiri tetap.");
    }

    public function storeCheck(Request $request, DeviceTemplate $template)
    {
        $c = new TemplateCheck($this->checkData($request));
        $c->template_id = $template->id;
        $c->save();
        Audit::log('created', "Cek \"{$c->name}\" ditambahkan ke templat {$template->name}", $template);

        return $this->done($template, "Cek {$c->name} ditambahkan ke templat dan dipasang ke perangkatnya.", TemplateSync::applyTemplate($template));
    }

    public function updateCheck(Request $request, DeviceTemplate $template, TemplateCheck $tcheck)
    {
        abort_unless($tcheck->template_id === $template->id, 404);
        $tcheck->fill($this->checkData($request))->save();
        Audit::log('updated', "Cek \"{$tcheck->name}\" di templat {$template->name} diubah", $template);

        return $this->done($template, "Cek {$tcheck->name} disimpan.", TemplateSync::applyTemplate($template));
    }

    public function destroyCheck(DeviceTemplate $template, TemplateCheck $tcheck)
    {
        abort_unless($tcheck->template_id === $template->id, 404);
        $tcheck->delete();
        Audit::log('deleted', "Cek \"{$tcheck->name}\" dihapus dari templat {$template->name}", $template);

        return redirect()->route('templates.edit', $template)->with('ok', "Cek {$tcheck->name} dihapus dari templat dan dari perangkatnya.");
    }

    private function done(DeviceTemplate $t, string $msg, array $warn)
    {
        $r = redirect()->route('templates.edit', $t)->with('ok', $msg);

        return $warn ? $r->with('error', implode(' ', array_slice($warn, 0, 5)) . (count($warn) > 5 ? ' Dan ' . (count($warn) - 5) . ' lainnya.' : '')) : $r;
    }

    private function validated(Request $r, ?DeviceTemplate $t = null): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('device_templates', 'name')->ignore($t)],
            'description' => ['nullable', 'string', 'max:500'],
            'macros' => ['nullable', 'string', 'max:5000'],
            'exporter_url' => ['nullable', 'string', 'max:255', 'regex:#^https?://[^\s@]+$#i'],
            'exporter_services' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9_.@:,\s{}$-]*$/'],
        ], ['exporter_url.regex' => 'Isi URL lengkap tanpa nama pengguna, mis. http://{HOST}:9100/metrics.',
            'exporter_services.regex' => 'Nama layanan hanya huruf, angka, dan tanda baca sederhana, dipisah koma.']);
        [$macros, $bad] = TemplateSync::parseMacros($d['macros'] ?? '');
        if ($bad) {
            throw ValidationException::withMessages(['macros' => 'Baris makro tidak sah: ' . implode('; ', array_slice($bad, 0, 3)) . '. Format: NAMA=nilai, nama huruf besar, angka, garis bawah.']);
        }
        $d['macros'] = $macros;
        $d['thresholds'] = DeviceController::parseThresholds($r);

        return $d;
    }

    private function checkData(Request $r): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', Rule::in(array_keys(ServiceCheck::TYPES))],
            'url' => ['nullable', 'required_if:type,http', 'string', 'max:500', 'regex:#^https?://[^\s]+$#i'],
            'port' => ['nullable', 'required_if:type,tcp', 'string', 'max:64', 'regex:/^(\d{1,5}|\{\$[A-Z0-9_]{1,40}\})$/'],
            'query' => ['nullable', 'required_if:type,dns', 'string', 'max:255', 'regex:/^[A-Za-z0-9._{}$-]+$/'],
            'expect_status' => ['nullable', 'string', 'max:32', 'regex:/^\s*\d{3}(\s*-\s*\d{3})?(\s*,\s*\d{3}(\s*-\s*\d{3})?)*\s*$/'],
            'expect_text' => ['nullable', 'string', 'max:200'],
            'interval_s' => ['required', 'integer', Rule::in(array_keys(ServiceCheck::INTERVALS))],
            'timeout_s' => ['required', 'integer', 'between:1,60'],
            'slow_ms' => ['nullable', 'integer', 'between:1,600000'],
            'cert_warn_days' => ['nullable', 'integer', 'between:1,365'],
            'severity' => ['required', Rule::in(array_keys(Problem::SEVERITIES))],
        ], ['url.regex' => 'Isi alamat lengkap, mis. https://{HOST}/login.', 'port.regex' => 'Port berupa angka atau satu makro, mis. {$PORT_WEB}.']);
        $d['verify_tls'] = $r->boolean('verify_tls');
        $d['enabled'] = $r->boolean('enabled');
        $keep = ['http' => ['url', 'expect_status', 'expect_text', 'cert_warn_days'], 'tcp' => ['port'], 'dns' => ['query', 'port']][$d['type']];
        foreach (['url', 'port', 'query', 'expect_status', 'expect_text', 'cert_warn_days'] as $k) {
            if (! in_array($k, $keep, true)) {
                $d[$k] = null;
            }
        }

        return $d;
    }
}
