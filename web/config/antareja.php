<?php

return [
    'version' => env('ANTAREJA_VERSION', '0.2.0-dev'),
    'collector_url' => env('ANTAREJA_COLLECTOR_URL', 'http://collector:9274'),
    'metrics_url' => env('ANTAREJA_METRICS_URL', 'http://metrics:8428'),
    'backup_dir' => env('ANTAREJA_BACKUP_DIR', '/backups'),
];
