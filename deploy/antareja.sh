#!/bin/sh
# ANTAREJA NMS — perintah operasional. Jalankan dari folder deploy:  ./antareja.sh <perintah>
set -eu
cd "$(dirname "$0")"
DC="docker compose"
# Sertifikat HTTPS kantor (opsional): letakkan certs/server.crt (beserta rantai/intermediate) & certs/server.key
if [ -f certs/server.crt ] && [ -f certs/server.key ]; then DC="docker compose -f compose.yml -f compose.cert.yml"; fi
[ -f .env ] || { echo "Berkas .env belum ada: cp .env.example .env lalu isi nilainya"; exit 1; }
BACKUP_DIR=$(grep -E '^BACKUP_DIR=' .env | cut -d= -f2- || true)
BACKUP_DIR=${BACKUP_DIR:-./backups}

usage() {
    cat <<USAGE
Pemakaian: ./antareja.sh <perintah>
  up                 jalankan / perbarui semua layanan (build bila perlu)
  update             git pull lalu up
  status             status container
  logs [layanan]     log (collector, web, backup, db, metrics)
  backup             backup database sekarang (menunggu sampai selesai)
  backups            daftar berkas backup
  restore <berkas>   PULIHKAN database dari berkas backup (data saat ini DIGANTI)
  cek                periksa pemasangan (.env, container, web, collector, backup terakhir)
USAGE
}

case "${1:-}" in
    up) $DC up -d --build ;;
    update) git pull --ff-only && $DC up -d --build ;;
    status) $DC ps ;;
    cek)
        ok=0
        say() { printf '%-34s %s\n' "$1" "$2"; }
        for v in DB_PASSWORD ANTAREJA_APP_KEY APP_URL; do
            val=$(grep -E "^$v=" .env | cut -d= -f2- || true)
            case "$val" in ''|*IP-VM-ANDA*) say "$v" "BELUM DIISI"; ok=1 ;; *) say "$v" "terisi" ;; esac
        done
        if [ -f certs/server.crt ] && [ -f certs/server.key ]; then
            say "Sertifikat HTTPS" "kantor ($(openssl x509 -in certs/server.crt -noout -enddate 2>/dev/null | cut -d= -f2))"
        else say "Sertifikat HTTPS" "self-signed bawaan (ganti: lihat README)"; fi
        for s in db metrics collector web go2rtc backup; do
            st=$($DC ps --format '{{.State}} {{.Health}}' "$s" 2>/dev/null | head -1)
            case "$st" in running*unhealthy*|'') say "Layanan $s" "MASALAH (${st:-tidak berjalan})"; ok=1 ;; *) say "Layanan $s" "$st" ;; esac
        done
        port=$(grep -E '^HTTPS_PORT=' .env | cut -d= -f2-); port=${port:-443}
        code=$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:$port/login" || true)
        case "$code" in 200|302) say "Web https://…:$port" "menjawab ($code)" ;; *) say "Web https://…:$port" "TIDAK MENJAWAB ($code)"; ok=1 ;; esac
        n=$($DC exec -T db psql -U antareja -d antareja -qtAc "SELECT count(*) FROM devices WHERE enabled" 2>/dev/null || echo "?")
        up=$($DC exec -T db psql -U antareja -d antareja -qtAc "SELECT count(*) FROM devices WHERE enabled AND status = 'up'" 2>/dev/null || echo "?")
        say "Perangkat aktif / up" "$n / $up"
        last=$($DC exec -T db psql -U antareja -d antareja -qtAc "SELECT coalesce(to_char(max(finished_at), 'DD/MM/YYYY HH24:MI'), 'belum ada') FROM backups WHERE status = 'ok'" 2>/dev/null || echo "?")
        say "Backup terakhir" "$last"
        say "Ruang kosong folder backup" "$(df -h "$BACKUP_DIR" 2>/dev/null | awk 'NR==2 {print $4}')"
        [ $ok -eq 0 ] && echo "Semua pemeriksaan dasar OK." || echo "Ada yang perlu dibereskan (lihat di atas)."
        exit $ok ;;
    logs) shift; $DC logs -f --tail 200 "$@" ;;
    backups) ls -lh "$BACKUP_DIR"/antareja-*.dump 2>/dev/null || echo "Belum ada backup di $BACKUP_DIR" ;;
    backup)
        id=$($DC exec -T db psql -U antareja -d antareja -qtAc "INSERT INTO backups (kind) VALUES ('manual') RETURNING id")
        echo "Backup #$id diminta, menunggu layanan backup…"
        for _ in $(seq 1 360); do
            st=$($DC exec -T db psql -U antareja -d antareja -qtAc "SELECT status || ' ' || coalesce(file, '') || ' ' || coalesce(error, '') FROM backups WHERE id = $id")
            case "$st" in ok*) echo "Selesai: $BACKUP_DIR/$(echo "$st" | awk '{print $2}')"; exit 0 ;; failed*) echo "GAGAL: $st"; exit 1 ;; esac
            sleep 5
        done
        echo "Belum selesai setelah 30 menit — cek: ./antareja.sh logs backup"; exit 1 ;;
    restore)
        f="${2:-}"
        [ -n "$f" ] || { echo "Sebutkan berkas, mis. ./antareja.sh restore antareja-20261122-013000.dump"; ./antareja.sh backups; exit 1; }
        [ -f "$f" ] || f="$BACKUP_DIR/$(basename "$f")"
        [ -f "$f" ] || { echo "Berkas tidak ditemukan: $f"; exit 1; }
        echo "PERHATIAN: seluruh data ANTAREJA saat ini akan DIGANTI dengan isi $(basename "$f")."
        echo "Pastikan ANTAREJA_APP_KEY di .env SAMA dengan saat backup dibuat (kredensial SNMP & notifikasi terenkripsi)."
        printf "Ketik PULIHKAN untuk melanjutkan: "; read -r ans
        [ "$ans" = "PULIHKAN" ] || { echo "Dibatalkan."; exit 1; }
        $DC stop web collector backup
        $DC exec -T db psql -U antareja -d postgres -qc "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'antareja' AND pid <> pg_backend_pid()" >/dev/null
        $DC exec -T db dropdb -U antareja antareja
        $DC exec -T db createdb -U antareja antareja
        $DC exec -T db pg_restore -U antareja -d antareja --no-owner < "$f"
        $DC up -d
        echo "Pemulihan selesai dari $(basename "$f"). Migrasi yang lebih baru (bila ada) dijalankan otomatis oleh collector."
        ;;
    *) usage ;;
esac
