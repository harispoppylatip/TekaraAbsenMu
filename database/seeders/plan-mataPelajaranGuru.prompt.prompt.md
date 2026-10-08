# Rencana: field "Kelas" berubah menjadi "Mata Pelajaran" saat peran Guru

## Permintaan

"jika peran di ganti guru, untuk form kelas jadi mata pelajaran"

Artinya: di form anggota, label dan arti field yang sekarang bernama **Kelas** harus
mengikuti peran yang dipilih. Peran `Guru` memakai **Mata Pelajaran**, peran lain
memakai **Kelas** seperti sekarang.

## Kondisi kode saat ini

- Form tambah/ubah anggota: `resources/views/members/index.blade.php`
  - `select name="role"` di baris 87 (opsi `student`, `teacher`, `admin`)
  - field kelas dari `@include('partials.class-options', ...)` di baris 93
  - kolom tabel "Kelas" di baris 135 (`$user->class_name`)
  - filter "Kelas" di baris 35 dari daftar `school_classes`
- Partial bersama: `resources/views/partials/class-options.blade.php`
  selalu berlabel **Kelas**, `name="class_name"`, plus `<datalist>` kelas terdaftar.
  Dipakai dua tempat: `/anggota` dan form sesi sidik jari `ready` di
  `resources/views/fingerprints/index.blade.php` baris 65.
- `app/Http/Controllers/MemberController.php`
  - `rules()` baris 181 sampai 189 menerima `class_name` nullable
  - `withResolvedClass()` baris 196 sampai 203 memanggil `SchoolClass::resolveName()`
- `app/Http/Controllers/EnrollmentController.php` baris 21 dan 25 melakukan hal sama
- `app/Models/SchoolClass.php` `resolveName()` **membuat baris `school_classes` baru**
  bila nama yang diketik belum terdaftar (perilaku ini disengaja sejak 2026-09-11)
- Tidak ada kolom mata pelajaran di `users`. Peran `teacher` hari ini memakai
  `class_name` yang sama dengan siswa.

## Kenapa tidak cukup hanya mengganti label

Kalau guru mengetik "Matematika" pada `class_name`, `SchoolClass::resolveName()` akan
**membuat kelas baru bernama "Matematika"**. Akibatnya:

- halaman `/kelas` berisi "Matematika", "Bahasa Indonesia", dan seterusnya sebagai kelas
- dropdown filter kelas di `/anggota` dan `/data` terisi nama mata pelajaran
- relasi `SchoolClass::members()` (memakai nama, bukan id) menganggap guru tersebut
  anggota kelas bernama "Matematika"
- `SchoolClassController::destroy()` menolak menghapus kelas karena dianggap masih
  punya anggota
- kolom "Kelas" pada tabel presensi dashboard dan `/presensi` menampilkan nama mapel
  sebagai kelas

## Keputusan

Tambah kolom tersendiri `users.subject` (nullable, 50) khusus mata pelajaran guru.
`class_name` tetap khusus kelas siswa.

Pemetaan yang dipakai di form, validasi, dan tampilan:

| Peran | Field yang tampil | Kolom database | Perilaku |
| --- | --- | --- | --- |
| `teacher` | Mata Pelajaran | `subject` | `class_name` dikosongkan, nama mapel **tidak** dibuat sebagai kelas |
| `student`, `admin` | Kelas | `class_name` | perilaku sekarang dipertahankan, kelas diketik bebas dan didaftarkan otomatis |

Ditolak: memakai `class_name` untuk mata pelajaran, karena mengotori data kelas
seperti pada bagian sebelumnya.

## Perubahan yang direncanakan

### 1. Database dan model

- Migration baru `2026_09_12_000009_add_subject_to_users_table.php`:
  `$table->string('subject', 50)->nullable()->after('class_name');`
  dan `down()` berisi `dropColumn('subject')`.
- `app/Models/User.php`
  - tambahkan `'subject'` ke atribut `#[Fillable([...])]`
  - tambahkan helper supaya Blade tidak menulis percabangan peran berulang:
    - `studyFieldLabel(): string` menghasilkan `'Mata Pelajaran'` untuk guru, selain itu `'Kelas'`
    - `studyFieldValue(): ?string` menghasilkan `subject` untuk guru, selain itu `class_name`
    - `static applyRoleStudyFields(array $data): array` berisi aturan penyimpanan
      (lihat bagian 4) agar `MemberController` dan `EnrollmentController` memakai
      satu sumber aturan

### 2. Partial form

`resources/views/partials/class-options.blade.php` diubah menjadi sadar peran
(nama file dan variabel `$classListId` tetap supaya pemanggil lain tidak perlu tahu
detailnya):

- terima variabel baru `$role` (peran yang sedang aktif)
- render dua blok, masing-masing `<div class="role-field" data-role="student">` dan
  `data-role="teacher"`:
  - blok `student`: label **Kelas**, `name="class_name"`, `<datalist>` kelas terdaftar
  - blok `teacher`: label **Mata Pelajaran**, `name="subject"`, `maxlength="50"`,
    placeholder "Ketik mata pelajaran", tanpa `datalist` karena belum ada master mapel
- blok yang tidak sesuai peran diberi atribut `hidden` yang dihitung **di server**
  dari `$role`, supaya tampilan pertama sudah benar walau JavaScript belum jalan
- jangan memakai `disabled` pada field yang disembunyikan, karena nilai lama bisa
  hilang saat validasi gagal; server yang mengabaikan nilai tidak sesuai peran

### 3. JavaScript pengalih

`resources/js/app.js` ditambah pendengar `change` pada `select[name="role"]`
(vanilla, tanpa dependensi baru):

- cari `[data-role]` di form terdekat, tampilkan blok sesuai nilai peran,
  sisanya disembunyikan
- peran `teacher` memunculkan blok `teacher`, peran lain memunculkan blok `student`

### 4. Validasi dan penyimpanan

- `MemberController::rules()` tambah `'subject' => ['nullable', 'string', 'max:50']`
- `withResolvedClass()` diganti `withResolvedStudyFields()` yang mendelegasikan ke
  `User::applyRoleStudyFields()`:
  - peran guru: `subject` dinormalisasi (spasi berlebih dibuang, kosong menjadi `null`),
    `class_name` dipaksa `null`, dan `SchoolClass::resolveName()` **tidak** dipanggil
  - peran lain: `class_name` lewat `SchoolClass::resolveName()` seperti sekarang,
    `subject` dipaksa `null`
- `EnrollmentController::store()` memakai aturan dan helper yang sama

### 5. Tampilan lain

- `resources/views/members/index.blade.php`
  - kirim `'role' => old('role', $editUser?->role ?? User::RoleStudent)` ke partial
  - kolom tabel "Kelas" memakai `$user->studyFieldValue() ?: 'Belum diisi'` dan
    `data-label` mengikuti label peran supaya mode kartu di layar kecil tetap benar
  - teks bantuan di bawah form disesuaikan: kalimat tentang kelas otomatis dibuat
    hanya berlaku untuk siswa, dan untuk guru jelaskan bahwa kolomnya mata pelajaran
- `resources/views/fingerprints/index.blade.php` memakai partial yang sama dengan
  `'role' => 'student'` sebagai nilai awal
- `resources/views/directory/index.blade.php` baris 145, `resources/views/dashboard.blade.php`
  baris 119, `resources/views/attendance/index.blade.php` baris 301 dan 348 memakai
  `$user->class_name ?: 'Belum diisi'`; ubah agar guru menampilkan mata pelajaran
- Filter kelas di `/anggota` dan `/data` tetap menyaring `class_name` (siswa) dan
  tidak berubah, karena `UserDirectoryController::classNames()` hanya mengumpulkan
  `school_classes` ditambah nilai `class_name` yang terpakai, sehingga mata pelajaran
  tidak ikut masuk daftar filter

### 6. Tes

`tests/Feature/MemberUpdateTest.php`

- tambah: peran guru + `subject` tersimpan di `users.subject`, `class_name` menjadi
  `null`, dan `assertDatabaseMissing('school_classes', ['name' => 'Matematika'])`
- tambah: anggota guru diubah menjadi siswa, `subject` menjadi `null` dan
  `class_name` terisi lewat `SchoolClass::resolveName()`
- tambah: halaman `/anggota` mode tambah merender dua blok dan blok mata pelajaran
  berstatus `hidden`; saat `?edit=` anggota guru, blok mata pelajaran terbuka dan
  blok kelas `hidden`
- perbarui: `test_member_page_keeps_one_live_region_and_one_class_field_while_editing`
  sekarang memeriksa satu `input name="class_name"` dan satu `input name="subject"`

Tes lain yang perlu diperiksa ulang

- `tests/Feature/SchoolClassTest.php` baris 303 memeriksa
  `<input name="class_name" list="class-suggestions"` pada halaman anggota
- `tests/Feature/UserDirectoryTest.php` baris 109 dan seterusnya membuat siswa dengan
  `class_name`, pastikan tetap lulus
- `tests/Feature/FingerprintScanTest.php` baris 425 dan 431 memakai `class_name`

### 7. Verifikasi

- `php artisan migrate` (wajib, kolom baru) lalu `vendor/bin/pint --format agent`
- `php artisan test --compact` sampai seluruh suite lulus
- `npm run build` karena `resources/js/app.js` berubah
- browser `/anggota`: pilih peran Guru, label berubah menjadi Mata Pelajaran, isi mapel,
  simpan, lalu pastikan halaman `/kelas` **tidak** memuat nama mapel tersebut dan
  kolom tabel menampilkan mapel
- ulangi untuk peran Siswa supaya perilaku kelas otomatis tetap jalan
- periksa juga form sesi sidik jari di `/fingerprints` dan halaman `/data`
- lebar 1280 px dan 390 px: tidak ada geseran horizontal, mode kartu tabel benar
- seluruh teks baru patuh [[Rules]]: tanpa dot, garis di samping teks, gradasi, pill,
  ikon dekoratif, dan em-dash

### 8. Ritual catatan setelah selesai

- `[[absensidikjari/catatan-project]]`: bagian perubahan form anggota dan kolom `subject`
- `[[ingatan-agent]]`: satu butir ringkas di tanggal berjalan
- bila pola "field form berubah mengikuti peran" ini dipakai lagi di proyek lain,
  catat sebagai POLA di `[[pola-fitur]]`
- perbarui memori internal repo

## Pertanyaan terbuka untuk disempurnakan

1. Peran `admin`: tetap memakai field Kelas seperti sekarang, atau field itu
   disembunyikan saja karena admin biasanya tidak terkait kelas?
2. Mata pelajaran cukup teks bebas dulu, atau perlu master data `subjects` supaya bisa
   dipakai juga oleh jadwal mengajar guru di halaman Kelas?
3. Guru didaftarkan dengan NIM yang sama dengan siswa (kolom `identifier_number` sudah
   dipakai bersama), atau label field NIM juga perlu menyesuaikan peran?
