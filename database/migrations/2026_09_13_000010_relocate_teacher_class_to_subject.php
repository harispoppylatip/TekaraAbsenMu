<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rapikan data yang tersimpan sebelum kolom `subject` ada.
     *
     * Dulu kolom mata pelajaran ikut memakai kolom kelas, jadi guru yang
     * mengisi mata pelajaran membuat baris baru di tabel `school_classes`.
     * Baris itu lalu muncul sebagai kelas di halaman Kelas, filter kelas, dan
     * daftar "Kelas yang dilayani" pada pengaturan perangkat.
     *
     * Dua langkah yang dikerjakan:
     * 1. nilai kelas milik guru dipindah ke kolom `subject` lalu kolom
     *    kelasnya dikosongkan, karena guru tidak lagi memakai kelas;
     * 2. kelas yang tidak dipakai siapa pun (tanpa anggota, tanpa sensor, dan
     *    tanpa jadwal) dihapus supaya tidak lagi terbaca sebagai kelas.
     */
    public function up(): void
    {
        // Guru memakai kode peran 'teacher' secara langsung, bukan konstanta
        // model, supaya migrasi ini tetap jalan walau model berubah nanti.
        DB::table('users')
            ->where('role', 'teacher')
            ->whereNotNull('class_name')
            ->where('class_name', '!=', '')
            ->update([
                'subject' => DB::raw("COALESCE(NULLIF(subject, ''), TRIM(class_name))"),
                'class_name' => null,
            ]);

        $unusedClasses = $this->unusedClasses();

        if ($unusedClasses->isNotEmpty()) {
            DB::table('school_classes')->whereIn('id', $unusedClasses->pluck('id'))->delete();
        }
    }

    /**
     * Kebalikan dari up(), sebatas yang bisa dikembalikan. Nilai mata pelajaran
     * guru dipindah lagi ke kolom kelas seperti sebelum migrasi, sedangkan baris
     * kelas yang sudah dihapus tidak dibuat ulang.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('role', 'teacher')
            ->whereNotNull('subject')
            ->where('subject', '!=', '')
            ->update([
                'class_name' => DB::raw('subject'),
                'subject' => null,
            ]);
    }

    /**
     * Kelas yang tidak terpakai sama sekali: tidak ada anggota dengan nama
     * kelas tersebut, tidak dipilih sebagai layanan sensor, dan tidak punya
     * jadwal guru. Kelas yang masih dipakai tidak pernah ikut terhapus.
     *
     * @return Collection<int, object{id: int, name: string}>
     */
    private function unusedClasses()
    {
        return DB::table('school_classes')
            ->select('id', 'name')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('users')
                ->whereColumn('users.class_name', 'school_classes.name'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('device_school_class')
                ->whereColumn('device_school_class.school_class_id', 'school_classes.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('teaching_schedules')
                ->whereColumn('teaching_schedules.school_class_id', 'school_classes.id'))
            ->get();
    }
};
