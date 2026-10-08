<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Samakan kata sandi bawaan anggota yang sudah ada dengan aturan baru
     * supaya mereka bisa login: guru dan admin memakai `tekara123`, siswa
     * memakai nomor induknya sendiri.
     *
     * Sebelumnya akun yang dibuat dari halaman Anggota memakai kata sandi acak
     * yang tidak pernah diberitahukan, jadi tidak ada yang bisa login. Semua
     * akun ditandai wajib ganti kata sandi dan baru boleh masuk halaman lain
     * setelah menggantinya. Kode peran ditulis langsung, bukan lewat konstanta
     * model, supaya migrasi ini tetap jalan walau model berubah.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereIn('role', ['teacher', 'admin'])
            ->update([
                'password' => Hash::make('tekara123'),
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);

        $students = DB::table('users')->where('role', 'student')->select('id', 'identifier_number')->orderBy('id')->get();

        foreach ($students as $student) {
            $identifier = trim((string) $student->identifier_number);

            DB::table('users')->where('id', $student->id)->update([
                'password' => Hash::make($identifier === '' ? 'tekara123' : $identifier),
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);
        }
    }

    /**
     * Kata sandi lama tidak bisa dikembalikan, jadi pembatalan migrasi ini
     * hanya membiarkan kata sandi yang sekarang terpasang.
     */
    public function down(): void
    {
        //
    }
};
