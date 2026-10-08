#!/bin/sh
#
# Persiapan singkat sebelum perintah utama wadah dijalankan.
#
# Dijalankan sebagai root supaya bisa memperbaiki pemilik berkas yang datang
# dari komputer induk, lalu menyerahkan kendali ke perintah utamanya: php-fpm
# pada layanan app, atau perintah artisan pada layanan queue, scheduler, dan
# mqtt.

set -e

cd /var/www/html

# ------------------------------------------------------------- basis data
# Pada salinan baru dari GitHub berkas SQLite belum ada karena diabaikan git.
# Berkasnya dibuat kosong lebih dulu supaya perintah migrasi punya sasaran.
db_file=""
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    db_file="${DB_DATABASE:-database/database.sqlite}"
    case "$db_file" in
        /*) ;;
        *) db_file="/var/www/html/$db_file" ;;
    esac

    if [ ! -f "$db_file" ]; then
        mkdir -p "$(dirname "$db_file")"
        touch "$db_file"
        echo "[persiapan] berkas basis data dibuat: $db_file"
    fi
fi

# ------------------------------------------------------------- penyimpanan
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Perbaikan pemilik ini penting saat dijalankan di Linux, karena berkas dari
# komputer induk biasanya dimiliki pengguna lain sedangkan PHP di dalam wadah
# berjalan sebagai www-data. Pada Docker Desktop Windows semua berkas yang
# dipasang dari komputer induk sudah bisa ditulis semua pengguna dan chown bisa
# ditolak, jadi kegagalannya sengaja diabaikan.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
if [ -n "$db_file" ]; then
    chown www-data:www-data "$db_file" 2>/dev/null || true
fi

# --------------------------------------------------------------- migrasi
# Hanya layanan app yang diminta menjalankan migrasi (lihat RUN_MIGRATIONS di
# docker-compose.yml) supaya keempat wadah tidak mengubah skema bersamaan.
# Perulangan ini untuk berjaga bila basis data belum siap menerima sambungan.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempt=1
    while [ "$attempt" -le 10 ]; do
        if php artisan migrate --force; then
            break
        fi
        echo "[persiapan] migrasi gagal, mencoba lagi ($attempt/10)"
        attempt=$((attempt + 1))
        sleep 3
    done
fi

exec "$@"
