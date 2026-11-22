#!/bin/sh
# ANTAREJA NMS — layanan backup database (berjalan di image postgres resmi; memakai pg_dump & psql bawaan).
# - Backup harian pada jam di pengaturan (backup.time), dan backup manual yang diminta dari web (status 'requested').
# - Berkas: /backups/antareja-YYYYmmdd-HHMMSS.dump (format custom pg_dump, terkompresi) + .json (manifest).
# - Menyimpan N backup terbaru (backup.keep); yang lebih lama dihapus.
# Kredensial database dari environment (PGHOST, PGUSER, PGPASSWORD, PGDATABASE); tidak ditulis ke berkas.
set -u
DIR=/backups
VERSION="${ANTAREJA_VERSION:-dev}"
mkdir -p "$DIR"

q() { psql -X -q -t -A -v ON_ERROR_STOP=1 -c "$1" 2>/dev/null; }
setting() { q "SELECT value #>> '{}' FROM settings WHERE key = '$1'"; }
log() { echo "[backup] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

run_backup() {   # $1 = id baris backups
    id="$1"
    name="antareja-$(date '+%Y%m%d-%H%M%S').dump"
    q "UPDATE backups SET status = 'running', started_at = now(), file = '$name' WHERE id = $id" >/dev/null
    log "mulai #$id -> $name"
    if pg_dump -Fc -Z 6 --no-owner -f "$DIR/.$name.part" 2>"$DIR/.err"; then
        mv "$DIR/.$name.part" "$DIR/$name"
        rm -f "$DIR/.err"
        size=$(wc -c < "$DIR/$name" | tr -d ' ')
        devices=$(q "SELECT count(*) FROM devices")
        cat > "$DIR/${name%.dump}.json" <<JSON
{"file":"$name","created_at":"$(date -Iseconds)","antareja_version":"$VERSION","postgres":"$(pg_dump --version | awk '{print $NF}')","devices":$devices,"size_bytes":$size}
JSON
        q "UPDATE backups SET status = 'ok', size_bytes = $size, finished_at = now() WHERE id = $id" >/dev/null
        log "selesai #$id ($size byte)"
        prune
    else
        err=$(head -c 400 "$DIR/.err" | tr "'" ' ' | tr '\n' ' ')
        rm -f "$DIR/.$name.part" "$DIR/.err"
        q "UPDATE backups SET status = 'failed', error = '$err', finished_at = now() WHERE id = $id" >/dev/null
        log "GAGAL #$id: $err"
    fi
}

prune() {
    keep=$(setting backup.keep); keep=${keep:-14}
    # berkas tertua di luar N terbaru
    ls -1 "$DIR"/antareja-*.dump 2>/dev/null | sort -r | tail -n +"$((keep + 1))" | while read -r f; do
        b=$(basename "$f")
        rm -f "$f" "${f%.dump}.json"
        q "UPDATE backups SET status = 'deleted' WHERE file = '$b' AND status = 'ok'" >/dev/null
        log "dihapus (retensi $keep): $b"
    done
}

log "layanan backup berjalan, folder $DIR"
until pg_isready -q; do sleep 2; done
last_day=""
disk_at=0
while true; do
    # Laporkan ruang disk folder backup tiap 5 menit (dipakai halaman Kesehatan sistem & peringatan disk hampir penuh)
    if [ $(( $(date +%s) - disk_at )) -ge 300 ]; then
        set -- $(df -Pk "$DIR" | awk 'NR==2 {print $2, $4}')
        if [ -n "${1:-}" ]; then
            q "INSERT INTO system_status (key, value, updated_at) VALUES ('backup', '{\"disk_total_kb\": $1, \"disk_free_kb\": $2}', now())
               ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()" >/dev/null || true
        fi
        disk_at=$(date +%s)
    fi
    # Backup yang tertinggal 'running' (layanan sempat mati) ditandai gagal
    q "UPDATE backups SET status = 'failed', error = 'terputus (layanan backup restart)', finished_at = now() WHERE status = 'running' AND started_at < now() - interval '2 hours'" >/dev/null
    # Permintaan manual dari web
    for id in $(q "SELECT id FROM backups WHERE status = 'requested' ORDER BY id"); do run_backup "$id"; done
    # Jadwal harian
    if [ "$(setting backup.enabled)" = "true" ]; then
        at=$(setting backup.time); now=$(date '+%H:%M'); today=$(date '+%Y-%m-%d')
        if [ "$today" != "$last_day" ] && [ "$now" \> "$at" -o "$now" = "$at" ]; then
            done_today=$(q "SELECT count(*) FROM backups WHERE kind = 'scheduled' AND requested_at::date = current_date")
            if [ "${done_today:-0}" = "0" ]; then
                id=$(q "INSERT INTO backups (kind) VALUES ('scheduled') RETURNING id")
                run_backup "$id"
            fi
            last_day="$today"
        fi
    fi
    sleep 20
done
