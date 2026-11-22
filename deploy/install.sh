#!/bin/sh
# ANTAREJA NMS — pemasangan pertama di VM (menggantikan langkah 4–5 README).
#
#   ./install.sh                     tanya IP, port, folder backup, lalu buat .env & jalankan
#   ./install.sh --tanpa-jalan       hanya buat .env (tidak menjalankan layanan)
#
# Nilai juga bisa diberikan lewat environment (tanpa pertanyaan):
#   ANTAREJA_IP=10.10.0.50 HTTP_PORT=80 HTTPS_PORT=443 BACKUP_DIR=/mnt/nas/antareja ./install.sh -y
set -eu
cd "$(dirname "$0")"

YES=0
RUN=1
for a in "$@"; do
    case "$a" in
        -y|--ya) YES=1 ;;
        --tanpa-jalan) RUN=0 ;;
        -h|--help) sed -n 2,9p "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Pilihan tidak dikenal: $a (lihat ./install.sh --help)"; exit 1 ;;
    esac
done

say() { printf '\n== %s\n' "$1"; }
fail() { printf '\nGAGAL: %s\n' "$1" >&2; exit 1; }
ask() { # ask "Pertanyaan" bawaan -> jawaban
    if [ "$YES" -eq 1 ]; then echo "$2"; return; fi
    printf '%s [%s]: ' "$1" "$2" >&2
    read -r ans || ans=""
    echo "${ans:-$2}"
}
port_used() { # port sedang dipakai proses lain di VM? (ss → netstat → coba sambung lewat bash)
    if command -v ss >/dev/null 2>&1; then ss -ltnH 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"
    elif command -v netstat >/dev/null 2>&1; then netstat -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"
    elif command -v bash >/dev/null 2>&1; then timeout 2 bash -c "echo > /dev/tcp/127.0.0.1/$1" 2>/dev/null
    else return 1; fi
}

say "Memeriksa prasyarat"
command -v docker >/dev/null 2>&1 || fail "Docker belum terpasang. Jalankan: sudo apt install -y docker.io docker-compose-v2 git curl openssl snmp"
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 belum ada. Jalankan: sudo apt install -y docker-compose-v2"
docker info >/dev/null 2>&1 || fail "User ini belum bisa memakai Docker. Jalankan: sudo usermod -aG docker \$USER lalu logout & login lagi"
command -v openssl >/dev/null 2>&1 || fail "openssl belum ada: sudo apt install -y openssl"
echo "Docker: $(docker --version | cut -d, -f1) · $(docker compose version --short 2>/dev/null || echo compose)"
if command -v timedatectl >/dev/null 2>&1 && ! timedatectl 2>/dev/null | grep -q 'synchronized: yes'; then
    echo "PERINGATAN: jam VM belum tersinkron NTP (timedatectl). Waktu kejadian & laporan bisa salah."
fi

if [ -f .env ]; then
    say "Berkas .env sudah ada — tidak diubah"
    echo "Hapus/ganti nama .env bila ingin membuat ulang (kunci lama akan hilang!)."
else
    say "Membuat konfigurasi .env"
    guess=$(hostname -I 2>/dev/null | awk '{print $1}')
    IP=${ANTAREJA_IP:-$(ask "IP atau nama DNS VM ini" "${guess:-127.0.0.1}")}
    HTTP_P=${HTTP_PORT:-$(ask "Port HTTP (dialihkan ke HTTPS)" 80)}
    HTTPS_P=${HTTPS_PORT:-$(ask "Port HTTPS" 443)}
    BDIR=${BACKUP_DIR:-$(ask "Folder backup (disarankan folder NAS yang di-mount)" ./backups)}
    for p in "$HTTP_P" "$HTTPS_P"; do
        case "$p" in ''|*[!0-9]*) fail "Port tidak valid: $p" ;; esac
        if port_used "$p"; then
            fail "Port $p sudah dipakai aplikasi lain di VM ini (mis. NMS lama/web server). Pilih port lain, mis. HTTP_PORT=8080 HTTPS_PORT=8443."
        fi
    done
    [ "$HTTPS_P" = 443 ] && URL="https://$IP" || URL="https://$IP:$HTTPS_P"

    umask 077
    sed -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" \
        -e "s|^ANTAREJA_APP_KEY=.*|ANTAREJA_APP_KEY=base64:$(openssl rand -base64 32)|" \
        -e "s|^APP_URL=.*|APP_URL=$URL|" \
        -e "s|^HTTP_PORT=.*|HTTP_PORT=$HTTP_P|" \
        -e "s|^HTTPS_PORT=.*|HTTPS_PORT=$HTTPS_P|" \
        -e "s|^BACKUP_DIR=.*|BACKUP_DIR=$BDIR|" .env.example > .env
    chmod 600 .env
    mkdir -p "$BDIR" 2>/dev/null || echo "PERINGATAN: folder backup $BDIR belum bisa dibuat — pastikan NAS sudah di-mount."
    echo "Dibuat: deploy/.env (alamat web: $URL)"
    cat <<'PENTING'

  ┌──────────────────────────────────────────────────────────────────────────────┐
  │ PENTING: simpan salinan deploy/.env di tempat aman (brankas password / NAS). │
  │ ANTAREJA_APP_KEY mengunci semua kredensial perangkat & notifikasi. Bila      │
  │ hilang, kredensial di backup tidak bisa dibuka. Jangan commit ke Git.        │
  └──────────────────────────────────────────────────────────────────────────────┘
PENTING
fi

[ "$RUN" -eq 1 ] || { echo; echo "Selesai (tanpa menjalankan layanan). Jalankan nanti: ./antareja.sh up"; exit 0; }

say "Menjalankan ANTAREJA (pertama kali ±5–15 menit: unduh & build image)"
chmod +x antareja.sh
./antareja.sh up

say "Menunggu layanan siap"
i=0
until ./antareja.sh cek >/dev/null 2>&1 || [ $i -ge 36 ]; do sleep 5; i=$((i + 1)); done
./antareja.sh cek || { echo; echo "Belum semua siap. Lihat: ./antareja.sh logs web  /  ./antareja.sh logs collector"; exit 1; }

URL=$(grep -E '^APP_URL=' .env | cut -d= -f2-)
cat <<SELESAI

Selesai. Buka $URL di browser:
  1. Sertifikat bawaan self-signed → pilih "lanjutkan" (ganti dengan sertifikat kantor: README langkah 7)
  2. Buat akun admin di halaman Setup Awal
  3. Atur firewall Proxmox: port web hanya untuk jaringan kantor/NOC, 514 hanya dari jaringan perangkat
SELESAI
