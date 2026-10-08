<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda petugas kamera wajah.
     *
     * Absen wajah dipakai dari kamera HP petugas, jadi tidak semua guru boleh
     * membuka halaman kameranya. Admin mencentang penanda ini pada guru yang
     * ditugaskan; perannya tetap guru, tidak perlu peran baru.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_scan_face')->default(false)->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_scan_face');
        });
    }
};
