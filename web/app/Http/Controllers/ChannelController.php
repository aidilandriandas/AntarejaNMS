<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use App\Models\NotifyChannel;
use App\Models\Problem;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Audit;
use App\Services\Collector;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChannelController extends Controller
{
    public function index()
    {
        return view('channels.index', [
            'channels' => NotifyChannel::with('site')->orderBy('delay_minutes')->orderBy('name')->get(),
            'logs' => NotificationLog::with('channel', 'problem')->orderByDesc('at')->limit(30)->get(),
            'thresholds' => [
                'latency_ms' => Setting::get('threshold.latency_ms', 150),
                'loss_pct' => Setting::get('threshold.loss_pct', 20),
                'util_pct' => Setting::get('threshold.util_pct', 90),
                'snmp_fail_cycles' => Setting::get('threshold.snmp_fail_cycles', 2),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $type = $request->query('type', 'telegram');
        abort_unless(isset(NotifyChannel::TYPES[$type]), 404);

        return view('channels.form', ['channel' => new NotifyChannel(['type' => $type, 'min_severity' => 'average', 'delay_minutes' => 0, 'notify_resolve' => true, 'enabled' => true]),
            'config' => $type === 'email' ? ['port' => 587, 'security' => 'starttls'] : [], 'sites' => Site::orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        $c = new NotifyChannel(['type' => $request->input('type')]);
        $this->fill($c, $request);
        $c->save();
        Audit::log('created', "Kanal notifikasi \"{$c->name}\" ({$c->type}) dibuat", $c);

        return redirect()->route('channels.index')->with('ok', "Kanal \"{$c->name}\" dibuat. Tekan \"Kirim uji\" untuk memastikan pesan sampai.");
    }

    public function edit(NotifyChannel $channel)
    {
        return view('channels.form', ['channel' => $channel, 'config' => $channel->publicConfig(), 'sites' => Site::orderBy('code')->get()]);
    }

    public function update(Request $request, NotifyChannel $channel)
    {
        $this->fill($channel, $request);
        $changes = \App\Services\Audit::diff($channel);
        $channel->save();
        Audit::log('updated', "Kanal notifikasi \"{$channel->name}\" diubah", $channel, $changes);

        return redirect()->route('channels.index')->with('ok', "Kanal \"{$channel->name}\" disimpan.");
    }

    public function destroy(NotifyChannel $channel)
    {
        $channel->delete();
        Audit::log('deleted', "Kanal notifikasi \"{$channel->name}\" dihapus", $channel);

        return redirect()->route('channels.index')->with('ok', "Kanal \"{$channel->name}\" dihapus.");
    }

    public function test(NotifyChannel $channel, Collector $collector)
    {
        try {
            $r = $collector->testChannel($channel->id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Collector tidak dapat dihubungi: ' . $e->getMessage());
        }

        return back()->with($r['ok'] ? 'ok' : 'error', $r['ok'] ? "Pesan uji terkirim ke \"{$channel->name}\". Periksa {$channel->target()}." : "Gagal mengirim ke \"{$channel->name}\": {$r['error']}");
    }

    public function thresholds(Request $request)
    {
        $d = $request->validate([
            'latency_ms' => ['required', 'numeric', 'between:1,10000'],
            'loss_pct' => ['required', 'numeric', 'between:1,100'],
            'util_pct' => ['required', 'numeric', 'between:10,100'],
            'snmp_fail_cycles' => ['required', 'integer', 'between:1,20'],
        ]);
        foreach ($d as $k => $v) {
            Setting::put("threshold.$k", $k === 'snmp_fail_cycles' ? (int) $v : (float) $v);
        }
        Audit::log('updated', 'Ambang problem diubah: ' . collect($d)->map(fn ($v, $k) => "$k=$v")->implode(', '));

        return back()->with('ok', 'Ambang disimpan. Berlaku pada penilaian berikutnya (±1 menit).');
    }

    private function fill(NotifyChannel $c, Request $r): void
    {
        $type = $c->type;
        abort_unless(isset(NotifyChannel::FIELDS[$type]), 422);
        $rules = [
            'name' => ['required', 'string', 'max:128'],
            'min_severity' => ['required', Rule::in(array_keys(Problem::SEVERITIES))],
            'delay_minutes' => ['required', 'integer', 'between:0,1440'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ];
        $existing = $c->exists ? $c->config() : [];
        $need = fn ($k) => empty($existing[$k]) ? 'required' : 'nullable';
        $rules += match ($type) {
            'telegram' => ['cfg.bot_token' => [$need('bot_token'), 'nullable', 'string', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
                'cfg.chat_id' => ['required', 'string', 'max:64', 'regex:/^(-?\d+|@[A-Za-z0-9_]{5,})$/']],
            'email' => ['cfg.host' => ['required', 'string', 'max:255'], 'cfg.port' => ['required', 'integer', 'between:1,65535'],
                'cfg.security' => ['required', Rule::in(['starttls', 'tls', 'none'])], 'cfg.username' => ['nullable', 'string', 'max:255'],
                'cfg.password' => ['nullable', 'string', 'max:255'], 'cfg.from' => ['required', 'email'], 'cfg.to' => ['required', 'string', 'max:1000']],
            'webhook' => ['cfg.url' => ['required', 'url:http,https', 'max:1000'], 'cfg.secret' => ['nullable', 'string', 'max:255']],
        };
        $d = $r->validate($rules, [
            'cfg.bot_token.required' => 'Isi token bot dari @BotFather.',
            'cfg.bot_token.regex' => 'Format token bot: 123456789:ABC... (dari @BotFather).',
            'cfg.chat_id.regex' => 'Chat ID berupa angka (grup diawali -100…) atau @namakanal.',
        ]);
        if ($type === 'email') {
            foreach (preg_split('/[\s,;]+/', $d['cfg']['to'], -1, PREG_SPLIT_NO_EMPTY) as $addr) {
                if (! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['cfg.to' => "Alamat penerima tidak valid: {$addr}"]);
                }
            }
        }
        $cfg = [];
        foreach (NotifyChannel::FIELDS[$type] as $k => $secret) {
            $v = trim((string) ($d['cfg'][$k] ?? ''));
            $cfg[$k] = ($secret && $v === '') ? ($existing[$k] ?? '') : $v; // rahasia kosong = pertahankan
        }
        $c->fill(['name' => $d['name'], 'min_severity' => $d['min_severity'], 'delay_minutes' => $d['delay_minutes'],
            'site_id' => $d['site_id'] ?? null, 'notify_resolve' => $r->boolean('notify_resolve'), 'enabled' => $r->boolean('enabled')]);
        $c->setConfig($cfg);
    }
}
