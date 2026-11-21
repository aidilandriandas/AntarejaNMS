#!/bin/sh
# Dijalankan setiap container web start: cache konfigurasi/route/view untuk produksi.
# Skema database dikelola collector (db/migrations), bukan oleh web.
set -e
cd /app
if [ -z "$APP_KEY" ]; then
    echo "ANTAREJA: APP_KEY kosong — isi ANTAREJA_APP_KEY di deploy/.env" >&2
    exit 1
fi
su application -s /bin/sh -c "php artisan optimize:clear >/dev/null && php artisan config:cache && php artisan route:cache && php artisan view:cache"
