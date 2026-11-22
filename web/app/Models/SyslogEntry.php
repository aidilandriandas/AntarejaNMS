<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris syslog (tabel syslog), ditulis penerima syslog di collector. */
class SyslogEntry extends Model
{
    protected $table = 'syslog';

    public $timestamps = false;

    /** Tingkat syslog: [label, nada warna]. */
    public const SEVERITIES = [
        0 => ['Emergency', 'crit'], 1 => ['Alert', 'crit'], 2 => ['Critical', 'crit'], 3 => ['Error', 'crit'],
        4 => ['Warning', 'warn'], 5 => ['Notice', 'info'], 6 => ['Info', ''], 7 => ['Debug', ''],
    ];

    public const FACILITIES = ['kern', 'user', 'mail', 'daemon', 'auth', 'syslog', 'lpr', 'news', 'uucp', 'cron', 'authpriv', 'ftp',
        'ntp', 'audit', 'alert', 'clock', 'local0', 'local1', 'local2', 'local3', 'local4', 'local5', 'local6', 'local7'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function sevLabel(): string
    {
        return self::SEVERITIES[$this->severity][0] ?? (string) $this->severity;
    }

    public function sevTone(): string
    {
        return self::SEVERITIES[$this->severity][1] ?? '';
    }
}
