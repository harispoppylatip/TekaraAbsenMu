# Resep citra Docker untuk website presensi.
#
# Ada empat tahap. Tiga tahap pertama hanya tempat kerja sementara; yang
# akhirnya disimpan sebagai citra cuma tahap app dan web.
#
#   aset    memasang paket npm lalu membangun CSS dan JavaScript dengan Vite
#   vendor  memasang paket PHP tanpa paket pengembangan
#   app     citra php-fpm. Dipakai bersama oleh layanan app, queue, scheduler,
#           dan mqtt karena keempatnya menjalankan kode yang sama
#   web     citra nginx yang menyajikan aset statis dan meneruskan PHP ke app
#
# Membangun:
#     docker compose build
#
# Membangun dan menjalankan:
#     docker compose up -d --build

# ---------------------------------------------------------------------------
# 1. Aset depan (CSS dan JavaScript)
# ---------------------------------------------------------------------------
# Sengaja memakai node:24-slim dan bukan alpine. Vite 8 di proyek ini memakai
# Rolldown yang membawa biner sistem sendiri, dan versi glibc adalah jalur yang
# paling aman. Basis tahap ini tidak berpengaruh pada ukuran citra akhir karena
# yang diambil darinya hanya folder public hasil build.
FROM node:24-slim AS aset

WORKDIR /app

# Seluruh sumber disalin lebih dulu, termasuk berkas Blade, karena Tailwind
# membaca berkas Blade untuk mengetahui nama kelas mana yang benar benar
# dipakai. Berkas yang tidak perlu sudah dibuang lewat .dockerignore, jadi
# node_modules dan vendor dari komputer tidak ikut masuk.
COPY . .

# npm ci dipakai lebih dulu supaya hasilnya setia pada package-lock.json. Bila
# kunci itu dibuat di sistem operasi lain dan ada paket biner yang tidak
# tercatat di dalamnya, npm ci berhenti dengan galat "Missing ... from lock
# file"; dalam hal itu npm install dipakai sebagai cadangan.
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund

RUN npm run build

# ---------------------------------------------------------------------------
# 2. Paket PHP
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Tanpa skrip: skrip paket composer baru bisa jalan setelah seluruh kode ada di
# tempatnya, dan itu dikerjakan pada langkah di bawah.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts

COPY . .

RUN composer dump-autoload --no-dev --optimize

# ---------------------------------------------------------------------------
# 3. Citra aplikasi (php-fpm)
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

ENV TZ=Asia/Makassar

# Pemeriksaan kecil, bukan pemasangan: pdo_sqlite sudah aktif pada citra resmi
# PHP. Baris ini memastikan, dan memberi pesan bila ternyata tidak ada, supaya
# masalahnya terbaca saat build dan bukan saat aplikasi sudah dipakai.
RUN php -m | grep -qi '^pdo_sqlite$' \
        && echo 'pdo_sqlite siap dipakai' \
        || echo 'PERINGATAN: pdo_sqlite tidak ada, basis data SQLite tidak akan jalan'

# opcache mempercepat pemuatan kode, pcntl membuat perintah artisan bisa
# dihentikan dengan rapi di dalam wadah.
RUN apk add --no-cache sqlite-libs tzdata \
    && apk add --no-cache --virtual .perkakas-bangun $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" opcache pcntl \
    && apk del .perkakas-bangun

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-absen.ini
COPY docker/entrypoint.sh /usr/local/bin/masuk-wadah
RUN chmod 0755 /usr/local/bin/masuk-wadah

WORKDIR /var/www/html

# Kode aplikasi beserta paket PHP-nya.
COPY --from=vendor /app /var/www/html

# Folder public dari tahap aset. Isinya sama dengan sumber, ditambah hasil
# build Vite di dalam public/build.
COPY --from=aset /app/public /var/www/html/public

# Folder yang perlu ditulis saat aplikasi berjalan. Pemiliknya diubah ke
# www-data supaya proses PHP tidak perlu berjalan sebagai root.
RUN mkdir -p storage/app/private \
             storage/app/public \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["masuk-wadah"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# 4. Citra web (nginx)
# ---------------------------------------------------------------------------
# Tanpa PHP di dalamnya. Semua permintaan berkas .php diteruskan ke layanan
# app, jadi kode aplikasi tidak perlu ikut ke sini; nginx hanya butuh folder
# public.
FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=aset /app/public /var/www/html/public

EXPOSE 80
