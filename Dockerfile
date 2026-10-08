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
# Versi PHP di sini WAJIB sejalan dengan composer.lock, bukan dengan
# "require.php" di composer.json yang masih longgar (^8.3). Tahap vendor
# memasang paket mengikuti composer.lock, dan sejak symfony 8 beberapa paket
# menuntut PHP >= 8.4.1. Bila citra memakai versi lebih tua, proses build tetap
# berhasil tetapi setiap perintah artisan berhenti seketika dengan kode 255,
# sehingga layanan queue, scheduler, dan mqtt berulang kali dinyalakan ulang.
# Angka 8.5 dipilih supaya sama dengan PHP 8.5 yang dipakai saat pengembangan.
FROM php:8.5-fpm-alpine AS app

ENV TZ=Asia/Makassar

# Pemeriksaan versi, supaya ketidakcocokan terbaca saat build dan bukan saat
# wadah sudah dijalankan. PHP_VERSION_ID untuk 8.4.1 adalah 80401.
RUN if php -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);'; then \
        echo "versi PHP memenuhi kebutuhan composer.lock"; \
    else \
        echo 'BERHENTI: paket di composer.lock menuntut PHP >= 8.4.1'; \
        exit 1; \
    fi

# Pemeriksaan kecil, bukan pemasangan: pdo_sqlite sudah aktif pada citra resmi
# PHP. Baris ini memastikan, dan memberi pesan bila ternyata tidak ada, supaya
# masalahnya terbaca saat build dan bukan saat aplikasi sudah dipakai.
RUN php -m | grep -qi '^pdo_sqlite$' \
        && echo 'pdo_sqlite siap dipakai' \
        || echo 'PERINGATAN: pdo_sqlite tidak ada, basis data SQLite tidak akan jalan'

# Yang dipasang di sini hanya dua hal: tzdata supaya zona waktu WITA benar, dan
# pcntl supaya perintah artisan bisa dihentikan dengan rapi di dalam wadah.
# sqlite-libs tetap ditulis walau sudah ada di citra resmi supaya jelas dari mana
# pustaka SQLite berasal; pdo_sqlite sendiri sudah menyatu di biner PHP dan sudah
# diperiksa pada langkah di atas.
#
# opcache SENGAJA tidak ada di daftar docker-php-ext-install. Citra resmi PHP
# sudah membangun opcache menyatu di dalam biner, jadi php -v menulis
# "with Zend OPcache" dan tidak ada opcache.so di folder ekstensi (isi conf.d
# hanya docker-fpm.ini dan docker-php-ext-sodium.ini). Meminta pemasangan opcache
# membuat folder modules kosong, lalu make install berhenti dengan
# "cp: can't stat 'modules/*'" dan build gagal dengan kode 2. Pengaturan opcache
# tetap berlaku lewat docker/php/php.ini. Pemeriksaan di baris terakhir menjaga
# hal itu: bila suatu saat citra resmi berhenti menyertakannya, build berhenti di
# sini dengan pesan, bukan diam diam jalan tanpa opcache.
RUN apk add --no-cache sqlite-libs tzdata \
    && apk add --no-cache --virtual .perkakas-bangun $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && apk del .perkakas-bangun \
    && php -m | grep -qi '^pcntl$' \
    && php -m | grep -qi 'Zend OPcache' \
    && echo 'pcntl terpasang dan opcache bawaan citra menyala'

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
