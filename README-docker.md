# Website presensi di dalam Docker

Berkas ini menjelaskan cara menjalankan aplikasi website ini sebagai wadah
Docker. Layanan pengenal wajah Python tidak ikut di sini karena siklus hidupnya
berbeda: PHP memuat ulang kode setiap permintaan, sedangkan model wajah dimuat
sekali lalu menahan ratusan megabita memori selama hidupnya. Layanan wajah
dijalankan dari folder `face absen` dan dihubungi lewat jaringan bersama.

Singkatnya: satu berkas compose di sini untuk website, satu berkas compose di
folder layanan wajah, dan satu jaringan Docker yang menyatukan keduanya.

## Isi berkas

| Berkas                      | Isinya                                                                                   |
| --------------------------- | ---------------------------------------------------------------------------------------- |
| `Dockerfile`                | Resep empat tahap: aset depan (Vite), paket PHP, citra aplikasi php-fpm, citra web nginx |
| `docker-compose.yml`        | Lima layanan: `app`, `web`, `scheduler`, `queue`, `mqtt`                                 |
| `docker/entrypoint.sh`      | Persiapan singkat sebelum PHP hidup: berkas basis data, folder penyimpanan, migrasi      |
| `docker/nginx/default.conf` | Nginx: melayani berkas statis, meneruskan PHP ke layanan `app`                           |
| `docker/php/php.ini`        | Batas unggah, batas waktu, dan opcache                                                   |
| `.dockerignore`             | Berkas yang tidak ikut ke dalam citra                                                    |

## Yang dijalankan

| Layanan     | Perannya                                                                                                          |
| ----------- | ----------------------------------------------------------------------------------------------------------------- |
| `app`       | Inti aplikasi. Menerima permintaan PHP dari nginx dan menjalankan migrasi saat pertama dinyalakan                 |
| `web`       | Nginx. Satu satunya pintu masuk dari peramban dan dari alat di jaringan sekolah                                   |
| `scheduler` | Menjalankan penjadwal tiap menit, yaitu pembuka sesi absen yang jamnya sudah masuk                                |
| `queue`     | Pekerja antrean. Saat ini diam menunggu karena belum ada pekerjaan antrean, disediakan untuk keperluan berikutnya |
| `mqtt`      | Pendengar pesan MQTT dari sensor sidik jari. Duduk menunggu tanpa henti, jadi memang harus punya wadah sendiri    |

Kelima layanan itu memakai citra yang sama, hanya perintah utamanya yang
berbeda. Yang menyimpan data bukan wadahnya, melainkan berkas basis data dan
folder proyek di komputer ini.

## Prasyarat

- Docker Desktop atau Docker Engine beserta Compose versi 2
- Berkas `.env` sudah ada (diperoleh dari `.env.example`, sudah termasuk
  `APP_KEY`)
- Untuk absen wajah: layanan wajah Python sudah dinyalakan dari folder
  `face absen`

## Menyiapkan `.env`

Berkas `.env` dipakai apa adanya sebagai sumber pengaturan, jadi yang perlu
ditambahkan hanya dua baris untuk Docker:

```env
# Port di komputer yang dipakai untuk membuka website.
# Harus sama dengan port pada APP_URL di atasnya.
APP_PORT=8000

# Alamat layanan wajah bila dijalankan di dalam Docker.
# Nama "wajah" adalah nama layanan di folder face absen.
FACE_SERVICE_DOCKER_URL=http://wajah:5005
```

Bila website dibuka dari perangkat lain (misalnya tablet atau ponsel), isi
`APP_URL` dengan alamat IP komputer ini, misalnya
`APP_URL=http://192.168.1.10:8000`. Alamat itu dipakai aplikasi untuk menyusun
alamat gambar dan tautan, jadi harus sama dengan alamat yang benar benar
dibuka.

## Menjalankan pertama kali

Jaringan bersama dibuat sekali saja. Jaringan inilah yang membuat layanan wajah
tidak perlu dibuka ke seluruh jaringan sekolah:

```
docker network create absen-jaringan
```

Lalu nyalakan website:

```
docker compose up -d --build
```

Build pertama memakan waktu beberapa menit karena memasang paket npm, paket
PHP, dan membangun aset. Build berikutnya cepat karena lapisannya sudah
tersimpan.

Periksa hasilnya:

```
docker compose ps
```

Semua layanan harus berstatus `running`, dan `app` serta `web` bertuliskan
`healthy`. Setelah itu website bisa dibuka di `http://localhost:8000` dan
pemeriksaan kesehatan di `http://localhost:8000/up` membalas `200`.

## Basis data

Basis data aplikasi ini adalah berkas SQLite `database/database.sqlite`, dan
folder `database` dipasang dari komputer ini ke dalam wadah. Artinya:

- Data yang sudah ada (anggota, sidik jari, jadwal, riwayat presensi) langsung
  terpakai apa adanya. Tidak ada yang perlu didaftarkan ulang, dan sandi tidak
  berubah.
- Migrasi baru dijalankan otomatis saat layanan `app` dinyalakan. Perintahnya
  `php artisan migrate --force` dan hanya layanan `app` yang menjalankannya.
- Berkas aslinya tetap satu: `database/database.sqlite` di komputer ini. Yang
  perlu disalin untuk mencadangkan data adalah berkas itu.

Pada salinan baru dari GitHub berkas SQLite belum ada karena diabaikan git.
Wadah akan membuatnya sendiri beserta seluruh tabelnya pada kali pertama
dinyalakan. Setelah itu akun bawaan perlu dibuat:

```
docker compose exec app php artisan db:seed
```

Akun admin hasil perintah itu adalah `admin@tekara.my.id` dengan sandi bawaan
`tekara123`, dan aplikasi akan langsung meminta sandi itu diganti.

Peringatan: `db:seed` tidak boleh dijalankan pada basis data yang sudah berisi
data kerja. Perintah itu mengembalikan sandi akun admin ke sandi bawaan dan
memaksa penggantian sandi pada saat berikutnya masuk.

## Perintah sehari hari

| Keperluan                            | Perintah                                      |
| ------------------------------------ | --------------------------------------------- |
| Menyalakan                           | `docker compose up -d --build`                |
| Melihat status                       | `docker compose ps`                           |
| Melihat catatan                      | `docker compose logs -f app`                  |
| Melihat catatan layanan lain         | `docker compose logs -f web` atau `-f mqtt`   |
| Menghentikan tanpa menghapus         | `docker compose stop`                         |
| Menghapus wadahnya                   | `docker compose down`                         |
| Menjalankan perintah artisan         | `docker compose exec app php artisan migrate` |
| Masuk ke dalam wadah                 | `docker compose exec app sh`                  |
| Membangun ulang setelah kode berubah | `docker compose up -d --build`                |

Data tidak hilang oleh `docker compose down` karena basis data berada di folder
proyek ini, bukan di dalam wadah.

## Cara kerja citra

Yang masuk ke dalam citra:

- Seluruh kode aplikasi dan paket PHP dari `composer install --no-dev`
- Aset CSS dan JavaScript hasil `npm run build`
- Folder penyimpanan kosong yang siap ditulis

Yang tidak masuk ke dalam citra:

- `.env`, `vendor`, `node_modules`, dan basis data asli, semuanya diabaikan oleh
  `.dockerignore`
- Perkakas pengembangan: pengujian, pint, dan berkas catatan alur kerja

Kode di dalam citra tidak dipasang dari folder komputer (tanpa bind mount) supaya
yang dijalankan di sini benar benar sama dengan hasil build, bukan berkas yang
kebetulan ada di laptop. Perubahan kode dijalankan dengan membangun ulang citra.
Cara ini juga menghindarkan masalah hak akses berkas antara Windows dan Linux di
dalam wadah.

Folder `storage` hidup di dalam wadah. Isinya hanya berkas sementara: catatan
aplikasi diarahkan ke keluaran wadah (`LOG_CHANNEL=stderr`) sehingga terbaca
lewat `docker compose logs`, sedangkan sesi dan singgahan disimpan di basis
data. Jadi membangun ulang citra tidak menghilangkan apa pun yang penting.

## Berkas unggahan wajah

Foto wajah dikirim dari peramban ke aplikasi, lalu diteruskan ke layanan wajah
Python, dan setelah itu tidak disimpan di mana pun. Karena itu:

- Batas unggah di nginx 32 MB, di `php.ini` 24 MB, dan aturan aplikasi 3 foto
  dengan maksimum 5 MB per foto. Urutan itu sengaja: yang terlalu besar ditolak
  aplikasi dengan pesan yang bisa dibaca pengguna, bukan dibuang diam diam oleh
  PHP.
- Batas tunggu nginx 600 detik karena satu foto butuh 12 sampai 15 detik untuk
  dihitung pada komputer tanpa kartu grafis.
- Perintah `php artisan storage:link` tidak diperlukan karena tidak ada berkas
  unggahan yang dilayani langsung sebagai berkas publik.

## Alat di jaringan sekolah

Sensor memanggil alamat website, bukan sebaliknya. Karena itu:

- Port `APP_PORT` dibuka ke seluruh kartu jaringan, bukan hanya `127.0.0.1`.
  Isi `APP_URL` dengan IP komputer ini agar alamat yang dipakai alat benar.
- Sensor sidik jari dihubungi lewat MQTT. Lihat `docker compose logs -f mqtt`
  bila ada pesan yang tidak masuk.
- Layanan wajah sengaja tidak dibuka ke jaringan: hanya bisa dihubungi dari
  dalam jaringan `absen-jaringan` dengan alamat `http://wajah:5005`.

## Penyelesaian masalah

| Gejala                                                         | Sebabnya                                                                              | Tindakan                                                                                 |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Halaman menampilkan 502                                        | Layanan `app` belum sehat atau sedang menjalankan migrasi                             | `docker compose ps`, lalu `docker compose logs -f app`                                   |
| Build berhenti di `npm ci` karena `Missing ... from lock file` | Kunci paket dibuat di Windows dan tidak memuat paket biner Linux                      | Sudah ditangani: Dockerfile otomatis memakai `npm install` sebagai cadangan              |
| Build menulis `PERINGATAN: pdo_sqlite tidak ada`               | Citra PHP dasar tidak memuat pdo_sqlite                                               | Tambahkan `docker-php-ext-install pdo_sqlite` pada tahap `app` di Dockerfile             |
| Permintaan foto dibalas 413                                    | Ukuran kiriman melebihi batas nginx                                                   | Kirim foto lebih sedikit atau ubah `client_max_body_size` di `docker/nginx/default.conf` |
| Absen wajah gagal padahal unggah berhasil                      | Layanan wajah Python belum dinyalakan, atau belum berada di jaringan `absen-jaringan` | Nyalakan layanan wajah, lalu `docker compose logs app` untuk melihat balasan layanan itu |
| Pesan MQTT tidak masuk                                         | Layanan `mqtt` mati atau pengaturan MQTT salah                                        | `docker compose logs -f mqtt`, periksa `MQTT_*` di `.env`                                |
| Perubahan kode tidak terlihat                                  | Wadah memakai kode dari citra, bukan dari folder                                      | `docker compose up -d --build`                                                           |

## Sebelum dipakai produksi

Sedikit pengaturan di `.env` sudah cukup untuk membedakan pemakaian percobaan
dan pemakaian sehari hari:

```env
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
```

Yang perlu diingat sebelum dipakai sungguhan:

- Seluruh sandi bawaan sudah diganti. Sandi bawaan hanya dibuat oleh
  `db:seed`, dan aplikasi memaksa penggantiannya saat pertama masuk.
- Berkas `database/database.sqlite` dicadangkan berkala. Cukup salin berkas itu
  sementara wadah berhenti, atau pakai `docker compose stop` lebih dulu.
- Port website sengaja terbuka ke jaringan sekolah karena alat presensi
  memanggilnya. Bila perlu dibatasi, gunakan firewall dan bukan dengan
  menutup port, karena alat tidak akan bisa absen.

## Berkas terkait

- `README.md` pada repositori ini, pengenalan aplikasi, fitur, halaman menurut
  peran, dan cara menjalankan tanpa Docker
- `README.md` pada repositori layanan wajah, penjelasan layanan Python
  pengenalan wajah beserta skrip pendaftarannya
- `README-wajah.md` pada repositori layanan wajah, dokumentasi lebih dalam
  mengenai bagian pengenalan wajah
