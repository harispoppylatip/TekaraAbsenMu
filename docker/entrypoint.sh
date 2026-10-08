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
db_dir=""
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    db_file="${DB_DATABASE:-database/database.sqlite}"
    case "$db_file" in
        /*) ;;
        *) db_file="/var/www/html/$db_file" ;;
    esac
    db_dir="$(dirname "$db_file")"

    if [ ! -f "$db_file" ]; then
        mkdir -p "$db_dir"
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
#
# Uji dulu apakah chown memang berpengaruh di folder ini. Pada pemasangan dari
# Windows, chown berjalan tanpa galat tetapi tidak mengubah apa pun, sehingga
# hasilnya tidak boleh dipakai sebagai tanda bahaya.
uji_chown=/var/www/html/.uji-chown
chown_works=false
if touch "$uji_chown" 2>/dev/null \
    && chown www-data:www-data "$uji_chown" 2>/dev/null \
    && [ "$(stat -c %u "$uji_chown" 2>/dev/null || echo 0)" = "$(id -u www-data)" ]; then
    chown_works=true
fi
rm -f "$uji_chown" 2>/dev/null || true

# Benar bila folder itu bisa ditulis www-data: miliknya sendiri, atau izin
# tulisnya terbuka untuk semua pengguna.
bisa_ditulis_www_data() {
    if [ "$(stat -c %u "$1" 2>/dev/null || echo 0)" = "$(id -u www-data)" ]; then
        return 0
    fi

    case "$(stat -c %a "$1" 2>/dev/null || echo 0)" in
        *[2367]) return 0 ;;
    esac

    return 1
}

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
if [ -n "$db_file" ]; then
    # Folder basis data juga harus bisa ditulis www-data, bukan hanya berkasnya.
    # SQLite menulis berkas sementara "-journal" (atau "-wal" dan "-shm") di
    # dalam folder itu setiap kali ada perubahan, jadi folder yang hanya bisa
    # ditulis root membuat setiap penyimpanan gagal dengan pesan
    # "attempt to write a readonly database".
    #
    # Kegagalannya sulit terlihat karena migrasi dijalankan sebagai root sehingga
    # selalu berhasil, dan halaman masuk pun tampil normal. Akibatnya besar:
    # aplikasi ini memakai SESSION_DRIVER=database, jadi sesi dan token CSRF ikut
    # gagal disimpan dan setiap kiriman formulir, termasuk tombol Masuk, dijawab
    # 419 Page Expired walau email dan kata sandinya benar.
    chown www-data:www-data "$db_dir" 2>/dev/null || true
    for db_part in "$db_file" "$db_file-journal" "$db_file-wal" "$db_file-shm"; do
        if [ -e "$db_part" ]; then
            chown www-data:www-data "$db_part" 2>/dev/null || true
            chmod 0660 "$db_part" 2>/dev/null || true
        fi
    done

    if [ "$chown_works" = "true" ] && ! bisa_ditulis_www_data "$db_dir"; then
        # Cadangan bila chown ditolak, misalnya pada wadah tanpa hak root atau
        # folder yang datang dari NFS: buka izin tulisnya untuk semua pengguna.
        chmod 0777 "$db_dir" 2>/dev/null || true
        for db_part in "$db_file" "$db_file-journal" "$db_file-wal" "$db_file-shm"; do
            if [ -e "$db_part" ]; then
                chmod 0666 "$db_part" 2>/dev/null || true
            fi
        done
    fi

    if [ "$chown_works" = "true" ] && ! bisa_ditulis_www_data "$db_dir"; then
        echo "[persiapan] PERINGATAN: folder $db_dir belum bisa ditulis oleh www-data."
        echo "[persiapan] Setiap formulir akan dijawab 419 Page Expired. Jalankan di komputer induk:"
        echo "[persiapan]     sudo chown -R 82:82 database"
    fi
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
