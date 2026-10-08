# Tekara AbsenMu

Website presensi sekolah. Kehadiran dicatat dengan dua cara: menempelkan sidik
jari ke sensor, atau berdiri sebentar di depan kamera wajah. Semua catatan masuk
ke satu tempat bersama jadwal pelajaran, guru pengajar, dan rekap per kelas.

Repositori ini berisi aplikasi webnya. Pengenal wajah berjalan sebagai layanan
Python terpisah di repositori
[mesinabsen](https://github.com/harispoppylatip/mesinabsen), karena model wajah
memakan ratusan megabita memori dan lebih baik dimuat sekali saja lalu dipakai
berulang.

## Daftar isi

- [Cara kerja absen](#cara-kerja-absen)
- [Fitur](#fitur)
- [Halaman menurut peran](#halaman-menurut-peran)
- [Perangkat sensor](#perangkat-sensor)
- [Teknologi](#teknologi)
- [Menjalankan dengan Docker](#menjalankan-dengan-docker)
- [Menjalankan tanpa Docker](#menjalankan-tanpa-docker)
- [Pengaturan penting](#pengaturan-penting)
- [Akun bawaan](#akun-bawaan)
- [Pengujian](#pengujian)
- [Struktur folder](#struktur-folder)
- [Berkas terkait](#berkas-terkait)

## Cara kerja absen

Absen masuk lewat dua pintu: sensor sidik jari di gerbang atau depan ruang, dan
kamera wajah yang dibawa petugas. Keduanya bermuara pada catatan yang sama,
yaitu daftar hadir pada sesi jam pelajaran yang sedang berjalan.

### Sidik jari

Sensor mengirimkan setiap tap ke `POST /api/fingerprint/scan`. Tiga kemungkinan
jawaban:

- Template dikenali, absen dicatat pada sesi yang sedang terbuka.
- Template belum dikenal, server menyiapkan sesi pendaftaran dan siswa ditempel
  dua kali.
- Perangkat belum didaftarkan atau sedang dimatikan, dan server membalas dengan
  pesan singkat yang langsung tampil di layar sensor, misalnya `Sensor dimatikan`
  atau `Belum terdaftar`.

Pesan itu sengaja dibuat pendek agar muat di layar sensor, dan dimuat pada
balasan server supaya siswa tahu apa yang terjadi, bukan sekadar gagal tanpa
sebab yang jelas.

### Wajah

Petugas membuka `/absen-wajah` dari ponsel, memotret, dan foto itu dikirim ke
layanan Python untuk dicocokkan dengan data wajah yang tersimpan. Satu foto
butuh 12 sampai 15 detik pada komputer tanpa kartu grafis, jadi halaman itu
memang dirancang untuk menunggu. Yang boleh membuka halaman ini hanya guru yang
ditunjuk sebagai petugas kamera wajah, dan penunjukannya dilakukan di halaman
Data Wajah.

### Sesi jam pelajaran

Absen tidak dibandingkan dengan jam pada server. Guru membuka sesinya sendiri
sesuai jadwal, dan absen hanya diterima selama sesi itu terbuka. Sesi boleh
dibuka mulai 10 menit sebelum jam pelajaran dimulai. Satu jadwal hanya punya
satu sesi per tanggal, dan membuka ulang sesi berarti memulai daftar hadir itu
dari awal.

Sensor juga menolak scan di luar hak sesinya: guru ke sesi milik guru lain,
siswa ke kelas lain, dan akun admin memang sengaja tidak dilayani absen.

### Presensi gerbang

Di luar jam pelajaran ada presensi gerbang dengan tiga rentang jam: masuk
terhitung hadir, masuk terhitung terlambat, dan pulang. Rentangnya diatur di
`/presensi`.

## Fitur

**Absen**

- Absen sidik jari dari sensor, absen wajah dari kamera ponsel, dan presensi
  gerbang masuk serta pulang.
- Pendaftaran sidik jari dikirim dari halaman anggota, jadi siswa tidak perlu
  menghafal urutan menempel jari.
- Unggah foto wajah langsung dari halaman Data Wajah, maksimum tiga foto per
  pengiriman dan 20 foto per anggota.
- Impor foto wajah sekaligus dengan memilih satu folder, dengan nama subfolder
  sebagai nomor induk siswa.

**Data induk**

- Anggota (admin, guru, siswa) beserta impor dari berkas, contoh templat,
  pengaturan ulang sandi, dan penghapusan.
- Kelas, jam pelajaran dengan pengisian otomatis, dan jadwal mingguan yang
  menjaga agar satu kelas dan satu guru tidak bentrok jam.
- Data pengguna di `/data` dengan saringan peran, kelas, keberadaan sidik jari,
  dan pencarian bebas.
- Daftar sidik jari terdaftar di `/fingerprints`, termasuk pembersihan template
  ganda lewat `php artisan fingerprints:dedupe`.

**Sesi dan laporan**

- Pemantauan sesi absen per jam pelajaran, termasuk penunjukan guru pengganti
  dan pembukaan serta penutupan sesi oleh admin.
- Portal guru: jadwal sendiri, membuka dan menutup absen, rekap kelas, dan
  unduhan rekap sebagai CSV.
- Portal siswa: jadwal kelasnya, rekap kehadiran sendiri, dan riwayat presensi
  gerbang.
- Laporan rentang tanggal di `/laporan`, bisa dicetak dan diunduh sebagai CSV
  yang langsung terbaca di Excel.

**Perangkat dan pemeliharaan**

- Pusat kontrol perangkat di `/perangkat`: mendaftarkan sensor baru, mengubah
  nama, mematikan dan menyalakan, menautkan ke kelas, membatalkan pendaftaran,
  dan menghapus.
- Halaman Pengaturan untuk melihat jumlah riwayat tersimpan dan menghapusnya
  menurut umur, tanggal tertentu, atau seluruhnya, lengkap dengan peringatan
  bahwa tindakan itu tidak bisa dibatalkan.
- Halaman akun untuk mengganti nama, surel, dan sandi sendiri.
- Wajib ganti sandi bagi akun yang masih memakai sandi bawaan.

## Halaman menurut peran

Semua orang masuk lewat satu pintu `/masuk`, lalu diarahkan ke halamannya
sendiri. Tiga peran tersedia: admin, guru, dan siswa.

**Admin**

- `/` Dasbor
- `/data` Data pengguna
- `/anggota` Anggota dan pendaftaran sidik jari
- `/kelas` Kelas
- `/jam-pelajaran` Jam pelajaran
- `/jadwal` Jadwal mingguan
- `/sesi-absen` Sesi absen
- `/perangkat` Pusat kontrol perangkat sensor
- `/wajah` Data wajah dan petugas kamera
- `/fingerprints` Daftar sidik jari
- `/presensi` Presensi gerbang dan pemantauan
- `/laporan` Laporan dan unduhan
- `/pengaturan` Pemeliharaan riwayat

**Guru**

- `/guru` Jadwal mengajar, membuka dan menutup absen
- `/guru/rekap` Rekap kelas dan unduhannya
- `/absen-wajah` Kamera wajah, hanya bila ditunjuk sebagai petugas

**Siswa**

- `/siswa` Jadwal kelas dan rekap kehadiran sendiri
- `/siswa/presensi-gerbang` Riwayat presensi gerbang

**Semua peran**

- `/akun` Identitas dan sandi sendiri
- `/ubah-sandi` Wajib dibuka sekali bila sandi masih sandi bawaan

## Perangkat sensor

Alat memakai token perangkat, bukan sesi login. Semua alamat di bawah ini baru
dilayani setelah perangkat pengirim terdaftar dan aktif.

| Alamat                               | Gunanya                                                          |
| ------------------------------------ | ---------------------------------------------------------------- |
| `POST /api/fingerprint/scan`         | Tap jari dikirim ke sini, lalu diputuskan absen atau pendaftaran |
| `GET /api/fingerprint/command`       | Sensor menanyakan perintah, misalnya perintah mendaftarkan jari  |
| `POST /api/fingerprint/match`        | Pencocokan template sidik jari                                   |
| `POST /api/fingerprint/register`     | Menyimpan template hasil pendaftaran                             |
| `GET /api/fingerprint/template/{id}` | Mengambil template milik satu pengguna                           |
| `GET /api/fingerprint/templates`     | Daftar template terdaftar                                        |
| `GET /api/fingerprint/users`         | Daftar pengguna yang boleh didaftarkan                           |
| `POST /api/face/register`            | Menyimpan data wajah (dipakai skrip pendaftaran di server)       |
| `GET /api/face/embeddings`           | Daftar data wajah yang tersimpan                                 |

Perangkat baru yang terbaca otomatis muncul di `/perangkat` dengan status belum
didaftarkan, dan baru bisa dipakai setelah didaftarkan serta dihubungkan ke
kelasnya. Pesan dari sensor sidik jari masuk lewat MQTT, dan pendengarnya
dijalankan dengan `php artisan fingerprint:mqtt-listen`.

## Teknologi

- PHP 8.4.1 atau lebih baru dengan Laravel 13, karena paket di `composer.lock`
  menuntut versi itu (citra Docker memakai PHP 8.5)
- SQLite sebagai basis data, cukup satu berkas `database/database.sqlite`
- Tailwind CSS 4 dan Vite 8 untuk tampilan
- `php-mqtt/client` untuk menerima pesan dari sensor sidik jari
- PHPUnit 12 dan Pint untuk pengujian dan perapian kode
- Layanan Python dengan `face_recognition` di repositori terpisah untuk
  pengenalan wajah

## Menjalankan dengan Docker

Cara ini disarankan karena PHP, nginx, Node, dan penjadwalnya sudah disiapkan
dalam wadah terpisah. Yang perlu dijalankan hanya dua perintah, dan data absen
yang sudah ada tetap terpakai tanpa pendaftaran ulang.

```
docker network create absen-jaringan
docker compose up -d --build
```

Setelah itu website terbuka di `http://localhost:8000` dan pemeriksaan
kesehatan di `/up`. Rincian lengkapnya, termasuk cara mencadangkan basis data,
pengaturan unggahan, dan penyelesaian masalah, ada di
[README-docker.md](README-docker.md).

Layanan wajah Python dijalankan terpisah dari folder repositori `mesinabsen`,
dan dihubungi lewat jaringan `absen-jaringan` dengan alamat default
`http://wajah:5005`.

## Menjalankan tanpa Docker

Cocok untuk mengembangkan aplikasi di komputer sendiri.

Prasyarat: PHP 8.4.1 atau lebih baru dengan ekstensi `pdo_sqlite`, Composer 2,
dan Node.js 20.19 atau lebih baru.

```
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Di Linux atau macOS, langkah penyalinan berkas diganti dengan
`cp .env.example .env`. Saat sedang mengembangkan tampilan, `npm run dev`
dijalankan di terminal lain sebagai pengganti `npm run build`.

Bila sensor sidik jari dan kamera wajah ingin dipakai, dua proses berikut ini
juga perlu berjalan:

```
php artisan schedule:work
php artisan fingerprint:mqtt-listen
```

## Pengaturan penting

Seluruh pengaturan dibaca dari `.env`. Kunci yang paling sering diubah:

| Kunci                                                      | Gunanya                                                                       |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `APP_URL` dan `APP_PORT`                                   | Alamat website. Harus sama dengan alamat yang benar benar dibuka              |
| `APP_TIMEZONE` dan `APP_LOCALE`                            | Zona waktu dan bahasa aplikasi                                                |
| `MQTT_HOST`, `MQTT_PORT`, `MQTT_USERNAME`, `MQTT_PASSWORD` | Alamat broker dan akunnya                                                     |
| `MQTT_TOPIC_PREFIX`                                        | Awal nama topik yang dipakai sensor                                           |
| `FACE_SERVICE_URL`                                         | Alamat layanan wajah Python saat dijalankan langsung                          |
| `FACE_SERVICE_DOCKER_URL`                                  | Alamat layanan wajah saat dijalankan di Docker, bawaannya `http://wajah:5005` |
| `FACE_TOLERANCE`, `FACE_MODEL`, `FACE_SERVICE_TIMEOUT`     | Ketatnya pencocokan wajah, model yang dipakai, dan batas tunggunya            |
| `FACE_DEVICE_ID`                                           | Penanda sumber pada riwayat absen yang datang dari kamera wajah               |
| `LOG_CHANNEL`                                              | Diisi `stderr` agar catatan terbaca lewat `docker compose logs`               |

Bila alamat layanan wajah diubah, jalankan `php artisan config:clear` supaya
nilai barunya terbaca.

## Akun bawaan

Perintah `php artisan db:seed` membuat satu akun admin: `admin@tekara.my.id`
dengan sandi bawaan `tekara123`. Untuk akun lain, sandi bawaannya adalah
`tekara123` bagi guru dan admin, sedangkan siswa memakai nomor induknya sendiri.

Semua akun yang masih memakai sandi bawaan dipaksa menggantinya saat pertama
masuk, jadi sandi bawaan tidak bisa dipakai terus menerus.

Peringatan: `db:seed` tidak boleh dijalankan pada basis data yang sudah dipakai
sehari hari. Perintah itu mengembalikan sandi akun admin ke sandi bawaan dan
memaksa penggantian sandi pada saat berikutnya masuk.

## Pengujian

```
php artisan test
vendor/bin/pint
```

Seluruh tes lulus pada pemeriksaan terakhir, saat itu berjumlah lebih dari 300
tes dengan lebih dari 1.800 asersi. Pengujian mencakup alur absen sidik jari dan
wajah, pembukaan sesi oleh guru, penolakan perangkat yang belum didaftarkan,
dan hak akses tiap peran.

## Struktur folder

```
app/Http/Controllers   Halaman dan API
app/Http/Middleware    Penjaga peran, sandi, perangkat, dan petugas kamera
app/Models             Model data
app/Services           Logika absen, sidik jari, wajah, jadwal, dan laporan
database/migrations    Skema basis data
docker/                Berkas nginx, php.ini, dan entrypoint untuk Docker
resources/views        Tampilan Blade
routes/web.php         Seluruh rute halaman dan API
routes/console.php     Perintah artisan, termasuk pendengar MQTT
```

## Berkas terkait

- [README-docker.md](README-docker.md) panduan menjalankan website di Docker
- [mesinabsen](https://github.com/harispoppylatip/mesinabsen) layanan pengenal
  wajah Python beserta skrip pendaftaran wajahnya
