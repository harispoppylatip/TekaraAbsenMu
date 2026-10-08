<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda wajib ganti kata sandi. Setiap akun baru memakai kata sandi
     * bawaan (guru dan admin `tekara123`, siswa nomor induknya), jadi halaman
     * aplikasi baru terbuka setelah pemiliknya mengganti kata sandinya.
     *
     * `password_changed_at` menyimpan kapan terakhir diganti supaya riwayat
     * pemakaian akun bisa diperiksa tanpa membuka tabel lain.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(true)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
