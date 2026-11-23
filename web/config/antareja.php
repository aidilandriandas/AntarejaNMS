<?php

return [
    'version' => env('ANTAREJA_VERSION', '0.4.0'),
    'collector_url' => env('ANTAREJA_COLLECTOR_URL', 'http://collector:9274'),
    'metrics_url' => env('ANTAREJA_METRICS_URL', 'http://metrics:8428'),
    'backup_dir' => env('ANTAREJA_BACKUP_DIR', '/backups'),
    'go2rtc_url' => env('ANTAREJA_GO2RTC_URL', 'http://go2rtc:1984'),
    'syslog_port' => (int) env('ANTAREJA_SYSLOG_PORT', 514),  // untuk petunjuk konfigurasi perangkat
    'trap_port' => (int) env('ANTAREJA_TRAP_PORT', 162),
];
